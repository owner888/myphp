<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\ObjC;
use MyPHP\Platform;
use MyPHP\Transfer\Data;

final class Clipboard implements Platform\Clipboard
{
    private function pasteboard(): object
    {
        ObjC::boot(['UIKit']);
        return ObjC::cls('UIPasteboard')->msg('generalPasteboard');
    }

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
        $pb = $this->pasteboard();
        $str = $pb->msg('string');
        return $str !== null ? (string)ObjC::string($str) : '';
    }

    public function writeText(string $text): void
    {
        $pb = $this->pasteboard();
        $nsStr = ObjC::str($text);
        $pb->msg('setString:', $nsStr);
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
