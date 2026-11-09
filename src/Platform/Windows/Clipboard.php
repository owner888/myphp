<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use MyPHP\Native\Build\Png;
use MyPHP\Native\Zlib;
use MyPHP\Platform;
use MyPHP\Transfer\{Data, Format, Item, Representation};

/**
 * The clipboard of Windows (mygo's windows clipboard), opened by the app's
 * window: plain text as CF_UNICODETEXT, every other format of a transfer
 * as a clipboard format registered by its name, its bytes as they are;
 * HTML also as CF_HTML ("HTML Format", a fragment in a header of byte
 * offsets), and a PNG image also as the registered "PNG" browsers and
 * Office copy and a CF_DIB, which other apps read (mygo's htmlData,
 * imageToDIB). Read back, they are HTML and PNG again, a bitmap's pixels
 * encoded (dibToImage). The data is copied as it is written (its
 * providers run then, and are released); OLE's data object, with what it
 * renders as asked, and files come with drag and drop's stage, as mygo's.
 */
final class Clipboard implements Platform\Clipboard
{
    private const CF_UNICODETEXT = 13, CF_DIB = 8, CF_DIBV5 = 17;

    public function __construct(private readonly Backend $b) {}

    /** Runs $fn with the clipboard open, waiting a little for another app that has it. */
    private function open(Closure $fn): mixed
    {
        $u = Win32::user32();
        for ($i = 0; !$u->OpenClipboard($this->b->hwnd()); $i++) {
            if ($i === 10) { throw new \RuntimeException('myphp: another app keeps the clipboard open'); }
            usleep(10000);
        }
        try {
            return $fn($u);
        } finally {
            $u->CloseClipboard();
        }
    }

    public function readText(): string
    {
        return $this->open(function (FFI $u): string {
            $h = $u->GetClipboardData(self::CF_UNICODETEXT);
            if ($h === null) {
                return '';
            }
            $k = Win32::kernel32();
            $p = $k->GlobalLock($h);
            try {
                return Win32::str($p);
            } finally {
                $k->GlobalUnlock($h);
            }
        });
    }

    public function writeText(string $text): void
    {
        $this->open(function (FFI $u) use ($text): void {
            $u->EmptyClipboard();
            $wide = (string)iconv('UTF-8', 'UTF-16LE', $text) . "\0\0";
            $k = Win32::kernel32();
            $h = $k->GlobalAlloc(0x0002 /* GMEM_MOVEABLE */, strlen($wide)) ?? throw new \RuntimeException('myphp: no memory for the clipboard');
            FFI::memcpy($k->GlobalLock($h), $wide, strlen($wide));
            $k->GlobalUnlock($h);
            if ($u->SetClipboardData(self::CF_UNICODETEXT, $h) === null) {
                $k->GlobalFree($h);
                throw new \RuntimeException('myphp: the clipboard refused the text');
            }
        });
    }

    public function clear(): void
    {
        $this->open(fn(FFI $u) => $u->EmptyClipboard());
    }

    /** The formats on the clipboard by their names: registered ones by theirs, the standard ones as CF_ constants are named. */
    public function availableFormats(): array
    {
        return $this->open(function (FFI $u): array {
            $names = [1 => 'CF_TEXT', 2 => 'CF_BITMAP', 7 => 'CF_OEMTEXT', 8 => 'CF_DIB', 13 => 'CF_UNICODETEXT', 15 => 'CF_HDROP', 16 => 'CF_LOCALE', 17 => 'CF_DIBV5'];
            $out = [];
            $buf = $u->new('uint16_t[256]');
            for ($f = $u->EnumClipboardFormats(0); $f !== 0; $f = $u->EnumClipboardFormats($f)) {
                $n = $u->GetClipboardFormatNameW($f, $buf, 256);
                $out[] = $n > 0 ? Win32::str($u->cast('uint16_t *', FFI::addr($buf[0])), $n) : ($names[$f] ?? "CF_$f");
            }
            return $out;
        });
    }

    /** What $formats are on the clipboard (all of them for none), as one item. */
    public function readData(array $formats): Data
    {
        return $this->open(function (FFI $u) use ($formats): Data {
            $reps = [];
            foreach ($this->offered($u) as $format => $id) {
                if ($formats !== [] && !in_array($format, $formats, true)) {
                    continue;
                }
                $h = $u->GetClipboardData($id);
                if ($h === null) {
                    continue;
                }
                $bytes = self::bytes($h, $id === self::CF_UNICODETEXT);
                $name = $id === self::CF_UNICODETEXT || $id === self::CF_DIB || $id === self::CF_DIBV5 ? '' : $this->name($u, $id);
                if ($name === 'HTML Format') {
                    $bytes = self::fragment($bytes);
                } elseif ($id === self::CF_DIB || $id === self::CF_DIBV5) {
                    $bytes = self::png($bytes);
                }
                if ($bytes !== null) {
                    $reps[] = Representation::bytes($format, $bytes);
                }
            }
            return $reps === [] ? new Data() : new Data(new Item(...$reps));
        });
    }

    /** Replaces the clipboard with $data's formats, each read now; $released runs once they are all copied. */
    public function writeData(Data $data, ?Closure $released): void
    {
        $this->open(function (FFI $u) use ($data): void {
            $u->EmptyClipboard();
            $k = Win32::kernel32();
            $put = function (int $id, string $bytes, string $format) use ($u, $k): void {
                if ($id === 0) {
                    throw new \RuntimeException("myphp: cannot register the clipboard format $format");
                }
                $h = $k->GlobalAlloc(0x0002 /* GMEM_MOVEABLE */, max(1, strlen($bytes))) ?? throw new \RuntimeException('myphp: no memory for the clipboard');
                if ($bytes !== '') {
                    FFI::memcpy($k->GlobalLock($h), $bytes, strlen($bytes));
                    $k->GlobalUnlock($h);
                }
                if ($u->SetClipboardData($id, $h) === null) {
                    $k->GlobalFree($h);
                    throw new \RuntimeException("myphp: the clipboard refused $format");
                }
            };
            $named = fn(string $name): int => $u->RegisterClipboardFormatW(Win32::wide($name));
            foreach ($data->formats() as $format) {
                $bytes = $data->read($format);
                if ($format === Format::TEXT) {
                    $put(self::CF_UNICODETEXT, (string)iconv('UTF-8', 'UTF-16LE', $bytes) . "\0\0", $format);
                    continue;
                }
                $put($named($format), $bytes, $format);
                if ($format === Format::HTML) {
                    $put($named('HTML Format'), self::htmlData($bytes), 'HTML Format');
                } elseif ($format === Format::PNG && ($dib = self::dib($bytes)) !== null) {
                    $put($named('PNG'), $bytes, 'PNG');
                    $put(self::CF_DIB, $dib, 'CF_DIB');
                }
            }
        });
        if ($released !== null) {
            $released();
        }
    }

    public function formats(): array
    {
        return $this->open(fn(FFI $u): array => array_keys($this->offered($u)));
    }

    /**
     * The transfer's formats on the open clipboard, by their clipboard
     * formats: plain text, those registered by a name of a type
     * ("image/png"), and other apps' HTML (CF_HTML) and images (the
     * registered "PNG", else a bitmap), where the app's own are not
     * (mygo's clipboardFormats). @return array<string, int>
     */
    private function offered(FFI $u): array
    {
        $out = [];
        $png = $dib = $html = 0;
        for ($id = $u->EnumClipboardFormats(0); $id !== 0; $id = $u->EnumClipboardFormats($id)) {
            if ($id === self::CF_UNICODETEXT) {
                $out[Format::TEXT] = $id;
                continue;
            }
            if ($id === self::CF_DIB || $id === self::CF_DIBV5) {
                $dib = $dib ?: $id;
                continue;
            }
            $name = $this->name($u, $id);
            if ($name === 'PNG') {
                $png = $id;
            } elseif ($name === 'HTML Format') {
                $html = $id;
            } elseif (str_contains($name, '/') && !isset($out[$name])) {
                $out[$name] = $id;
            }
        }
        if (!isset($out[Format::TEXT]) && $u->IsClipboardFormatAvailable(self::CF_UNICODETEXT)) { // Windows makes it of the older texts
            $out[Format::TEXT] = self::CF_UNICODETEXT;
        }
        if (!isset($out[Format::HTML]) && $html !== 0) {
            $out[Format::HTML] = $html;
        }
        if (!isset($out[Format::PNG]) && ($png !== 0 || $dib !== 0 || $u->IsClipboardFormatAvailable(self::CF_DIB))) { // the PNG before a bitmap, whose alpha a DIB may lose; Windows makes a DIB of a bitmap
            $out[Format::PNG] = $png !== 0 ? $png : ($dib !== 0 ? $dib : self::CF_DIB);
        }
        return $out;
    }

    /** A registered clipboard format's name, '' for a standard one. */
    private function name(FFI $u, int $id): string
    {
        $buf = $u->new('uint16_t[256]');
        $n = $u->GetClipboardFormatNameW($id, $buf, 256);
        return $n > 0 ? Win32::str($u->cast('uint16_t *', FFI::addr($buf[0])), $n) : '';
    }

    /** The bytes of a clipboard handle; $wide, a NUL-terminated UTF-16 string, as UTF-8. */
    private static function bytes(\FFI\CData $h, bool $wide = false): string
    {
        $k = Win32::kernel32();
        $p = $k->GlobalLock($h);
        try {
            return $wide ? Win32::str($p) : FFI::string($p, $k->GlobalSize($h));
        } finally {
            $k->GlobalUnlock($h);
        }
    }

    /** The bytes of the open clipboard's format $id, null without it. */
    private static function data(FFI $u, int $id, bool $wide = false): ?string
    {
        $h = $u->GetClipboardData($id);
        return $h === null ? null : self::bytes($h, $wide);
    }

    /** CF_HTML of a fragment of markup: a header of byte offsets, the fragment in a document (mygo's htmlData). */
    private static function htmlData(string $markup): string
    {
        $header = "Version:0.9\r\nStartHTML:%010d\r\nEndHTML:%010d\r\nStartFragment:%010d\r\nEndFragment:%010d\r\n";
        [$prefix, $suffix] = ['<html><body><!--StartFragment-->', '<!--EndFragment--></body></html>'];
        $h = strlen(sprintf($header, 0, 0, 0, 0));
        $start = $h + strlen($prefix);
        $end = $start + strlen($markup);
        return sprintf($header, $h, $end + strlen($suffix), $start, $end) . $prefix . $markup . $suffix . "\0";
    }

    /** The fragment of CF_HTML, by its header's offsets (mygo's ReadHTML); null for a header that does not hold. */
    private static function fragment(string $data): ?string
    {
        $data = rtrim($data, "\0");
        $at = fn(string $key): int => preg_match('/' . $key . '(-?\d+)\s*[\r\n]/', $data, $m) === 1 ? (int)$m[1] : -1;
        [$start, $end] = [$at('StartFragment:'), $at('EndFragment:')];
        return $start < 0 || $end < $start || $end > strlen($data) ? null : substr($data, $start, $end - $start);
    }

    /**
     * A PNG of an uncompressed 24 or 32-bit device independent bitmap, its
     * alpha a 32-bit one's of a header past BITMAPINFOHEADER's, opaque
     * otherwise; null for one that is not, or too large (mygo's dibToImage).
     */
    private static function png(string $dib): ?string
    {
        if (strlen($dib) < 40) {
            return null;
        }
        $v = unpack('Vsize/lw/lh/vplanes/vbits/Vcompression', $dib);
        [$size, $w, $h, $bits, $comp] = [$v['size'], $v['w'], $v['h'], $v['bits'], $v['compression']];
        if ($w <= 0 || $h === 0 || ($bits !== 24 && $bits !== 32) || ($comp !== 0 && $comp !== 3) || $size < 40 || $size > strlen($dib)) {
            return null;
        }
        $offset = $size + ($comp === 3 && $size === 40 ? 12 : 0); // BI_BITFIELDS' masks
        $up = $h > 0;
        $h = abs($h);
        if ($w > (64 << 20) / 4 || $h > (64 << 20) / (4 * $w)) {
            return null; // the clipboard's sizes are untrusted
        }
        $stride = intdiv($w * $bits + 31, 32) * 4;
        if ($offset > strlen($dib) || $h > intdiv(strlen($dib) - $offset, $stride)) {
            return null;
        }
        [$px, $alpha] = [intdiv($bits, 8), $bits === 32 && $size > 40];
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $line = substr($dib, $offset + ($up ? $h - 1 - $y : $y) * $stride, $stride);
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $p = $x * $px;
                $raw .= $line[$p + 2] . $line[$p + 1] . $line[$p] . ($alpha ? $line[$p + 3] : "\xff");
            }
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', Zlib::deflate($raw, 15, 6)) . $chunk('IEND', '');
    }

    /** A top-down 32-bit BITMAPINFOHEADER bitmap of a PNG, its colors straight, null for no PNG (mygo's imageToDIB). */
    private static function dib(string $png): ?string
    {
        try {
            [$w, $h, $f] = Png::decode($png);
        } catch (\RuntimeException) {
            return null;
        }
        $out = pack('VllvvVVllVV', 40, $w, -$h, 1, 32, 0, 4 * $w * $h, 0, 0, 0, 0);
        for ($i = 0, $n = $w * $h * 4; $i < $n; $i += 4) {
            $a = $f[$i + 3];
            $c = fn(float|int $v): int => $a > 0 ? min(255, (int)round($v * 255 / $a)) : 0; // unpremultiplied
            $out .= chr($c($f[$i + 2])) . chr($c($f[$i + 1])) . chr($c($f[$i])) . chr((int)round($a));
        }
        return $out;
    }

    public function flush(): void {}

    /** The HTML on the clipboard: the app's own, else CF_HTML's fragment (mygo's ReadHTML); '' without any. */
    public function readHTML(): string
    {
        return $this->open(function (FFI $u): string {
            $own = $u->RegisterClipboardFormatW(Win32::wide(Format::HTML));
            if ($u->IsClipboardFormatAvailable($own) && ($bytes = self::data($u, $own)) !== null) {
                return $bytes;
            }
            $cf = $u->RegisterClipboardFormatW(Win32::wide('HTML Format'));
            return ($bytes = self::data($u, $cf)) === null ? '' : (self::fragment($bytes) ?? '');
        });
    }

    /** The image on the clipboard as PNG: the registered "PNG" browsers and Office copy, else a bitmap's pixels encoded (mygo's ReadImage); '' without one. */
    public function readImage(): string
    {
        return $this->open(function (FFI $u): string {
            foreach ([Format::PNG, 'PNG'] as $name) {
                $id = $u->RegisterClipboardFormatW(Win32::wide($name));
                if ($u->IsClipboardFormatAvailable($id) && ($bytes = self::data($u, $id)) !== null && $bytes !== '') {
                    return $bytes;
                }
            }
            $dib = self::data($u, self::CF_DIB);
            return $dib === null ? '' : (self::png($dib) ?? '');
        });
    }

    /** Whether $bytes are a PNG image, which the clipboard takes (mygo's WriteImage decodes it). */
    public function isImage(string $bytes): bool
    {
        try {
            Png::decode($bytes);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
