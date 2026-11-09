<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use MyPHP\Transfer\{Data, Format, Item, Representation};

/**
 * The clipboard of Windows (mygo's windows clipboard), opened by the app's
 * window: plain text as CF_UNICODETEXT, every other format of a transfer
 * as a clipboard format registered by its name, its bytes as they are.
 * The data is copied as it is written (its providers run then, and are
 * released); OLE's data object, with what it renders as asked, the HTML
 * and images other apps read and their files come with drag and drop's
 * stage, as mygo's.
 */
final class Clipboard implements Platform\Clipboard
{
    private const CF_UNICODETEXT = 13;

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
                $k = Win32::kernel32();
                $p = $k->GlobalLock($h);
                try {
                    $bytes = $id === self::CF_UNICODETEXT ? Win32::str($p) : FFI::string($p, $k->GlobalSize($h));
                } finally {
                    $k->GlobalUnlock($h);
                }
                $reps[] = Representation::bytes($format, $bytes);
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
            foreach ($data->formats() as $format) {
                $bytes = $data->read($format);
                [$id, $bytes] = $format === Format::TEXT
                    ? [self::CF_UNICODETEXT, (string)iconv('UTF-8', 'UTF-16LE', $bytes) . "\0\0"]
                    : [$u->RegisterClipboardFormatW(Win32::wide($format)), $bytes];
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

    /** The transfer's formats on the open clipboard, by their clipboard formats: plain text, and those registered by a name of a type ("image/png"). @return array<string, int> */
    private function offered(FFI $u): array
    {
        $out = [];
        $buf = $u->new('uint16_t[256]');
        for ($id = $u->EnumClipboardFormats(0); $id !== 0; $id = $u->EnumClipboardFormats($id)) {
            if ($id === self::CF_UNICODETEXT) {
                $out[Format::TEXT] = $id;
                continue;
            }
            $n = $u->GetClipboardFormatNameW($id, $buf, 256);
            $name = $n > 0 ? Win32::str($u->cast('uint16_t *', FFI::addr($buf[0])), $n) : '';
            if (str_contains($name, '/') && !isset($out[$name])) {
                $out[$name] = $id;
            }
        }
        if (!isset($out[Format::TEXT]) && $u->IsClipboardFormatAvailable(self::CF_UNICODETEXT)) { // Windows makes it of the older texts
            $out[Format::TEXT] = self::CF_UNICODETEXT;
        }
        return $out;
    }

    public function flush(): void {}

    public function readHTML(): string
    {
        throw new Unsupported('myphp: HTML on the clipboard is not on Windows yet');
    }

    public function readImage(): string
    {
        throw new Unsupported('myphp: images on the clipboard are not on Windows yet');
    }

    public function isImage(string $bytes): bool
    {
        return false;
    }
}
