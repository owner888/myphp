<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use MyPHP\Transfer\Data;

/**
 * The clipboard of Windows (mygo's windows clipboard), opened by the app's
 * window: text as CF_UNICODETEXT for now; the transfer's formats, HTML and
 * images come with drag and drop's stage.
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

    public function readData(array $formats): Data
    {
        throw new Unsupported('myphp: the clipboard\'s formats are not on Windows yet');
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        throw new Unsupported('myphp: the clipboard\'s formats are not on Windows yet');
    }

    public function formats(): array
    {
        throw new Unsupported('myphp: the clipboard\'s formats are not on Windows yet');
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
