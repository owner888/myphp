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
    public function readData(array $formats): Data
    {
        $reps = [];
        $want = fn(string $f) => $formats === [] || in_array($f, $formats, true);
        if ($want(Format::TEXT) && ($text = $this->readText()) !== '') {
            $reps[] = \MyPHP\Transfer\Representation::bytes(Format::TEXT, $text);
        }
        if ($want(Format::PNG) && ($img = $this->readImage()) !== '') {
            $reps[] = \MyPHP\Transfer\Representation::bytes(Format::PNG, $img);
        }
        return $reps === [] ? new Data() : new Data(new \MyPHP\Transfer\Item(...$reps));
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        try {
            $this->writeText($data->read(Format::TEXT));
        } catch (FormatUnavailable) {}
        try {
            $this->writeImage($data->read(Format::PNG));
        } catch (FormatUnavailable) {}
        $released?->__invoke();
    }

    public function formats(): array
    {
        $out = [];
        if ($this->readText() !== '') { $out[] = Format::TEXT; }
        if ($this->readImage() !== '') { $out[] = Format::PNG; }
        return $out ?: [Format::TEXT];
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
        $f = iOS::ffi();
        $len = $f->new('int');
        $p = $f->myphp_ios_clipboard_get_image(\FFI::addr($len));
        if ($p === null || \FFI::isNull($p) || (int)$len->cdata <= 0) {
            return '';
        }
        $png = \FFI::string($p, (int)$len->cdata);
        $f->myphp_ios_free_string($f->cast('char*', $p));
        return $png;
    }

    public function isImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
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
        if ($png === '') {
            return;
        }
        $f = iOS::ffi();
        $n = strlen($png);
        $buf = $f->new("uint8_t[$n]");
        \FFI::memcpy($buf, $png, $n);
        $f->myphp_ios_clipboard_set_image($buf, $n);
    }

    public function readFiles(): array
    {
        return [];
    }

    public function writeFiles(array $paths): void
    {
    }
}
