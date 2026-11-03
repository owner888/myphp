<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use Closure;
use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\Transfer\{Data, Format, FormatUnavailable, Representation};

/**
 * Android system clipboard via ClipboardManager over JNI.
 */
final class Clipboard implements Platform\Clipboard
{
    public function readData(array $formats): Data
    {
        $text = $this->readText();
        if ($text === '') {
            return new Data();
        }
        return Data::text($text);
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        try {
            $text = $data->read(Format::TEXT);
            $this->writeText($text);
        } catch (FormatUnavailable) {}
        $released?->__invoke();
    }

    public function formats(): array
    {
        return [Format::TEXT];
    }

    public function flush(): void {}

    public function readText(): string
    {
        $f = Android::ffi();
        $buf = $f->new('char[65536]');
        $len = $f->myphp_clipboard_get_text($buf, 65536);
        return $len > 0 ? \FFI::string($buf, $len) : '';
    }

    public function writeText(string $text): void
    {
        Android::ffi()->myphp_clipboard_set_text($text);
    }

    public function readHTML(): string
    {
        return '';
    }

    public function readImage(): string
    {
        return '';
    }

    public function isImage(string $bytes): bool
    {
        return false;
    }

    public function clear(): void
    {
        $this->writeText('');
    }

    public function availableFormats(): array
    {
        return [Format::TEXT];
    }

    public function writeImage(string $png): void {}
}
