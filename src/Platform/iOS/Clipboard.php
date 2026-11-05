<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\Transfer\Data;

final class Clipboard implements Platform\Clipboard
{
    public function readData(array $formats): Data
    {
        $d = new Data();
        $text = $this->readText();
        if ($text !== '') {
            $d->setText($text);
        }
        return $d;
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        $text = $data->text();
        if ($text !== null) {
            $this->writeText($text);
        }
    }

    public function formats(): array
    {
        return ['public.utf8-plain-text'];
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
