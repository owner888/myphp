<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\Transfer\Data;
use MyPHP\Transfer\Format;
use MyPHP\Transfer\FormatUnavailable;

final class Clipboard implements Platform\Clipboard
{
    public function readData(array $formats): Data // text alone, as Android's: Data's own API (it has no setText nor text())
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
            $this->writeText($data->read(Format::TEXT));
        } catch (FormatUnavailable) {} // no text in it: nothing this clipboard holds
        $released?->__invoke();
    }

    public function formats(): array
    {
        return [Format::TEXT];
    }

    public function flush(): void
    {
    }

    public function readText(): string
    {
        $f = iOS::ffi();
        $p = $f->myphp_ios_clipboard_get_text();
        if ($p === null) {
            return '';
        }
        $text = \FFI::string($p);
        $f->myphp_ios_free_string($p);
        return $text;
    }

    public function writeText(string $text): void
    {
        iOS::ffi()->myphp_ios_clipboard_set_text($text);
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
        return [\MyPHP\Transfer\Format::TEXT];
    }

    public function writeImage(string $png): void
    {
    }

    public function readFiles(): array
    {
        return [];
    }

    public function writeFiles(array $paths): void
    {
    }
}
