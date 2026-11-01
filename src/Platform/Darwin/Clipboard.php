<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use Closure;
use MyPHP\Native\Pasteboard;
use MyPHP\Platform;
use MyPHP\Transfer\Data;

/** The general pasteboard (mygo's darwin clipboard.go). */
final class Clipboard implements Platform\Clipboard
{
    public function readData(array $formats): Data
    {
        return Pasteboard::readData(...($formats === [] ? Pasteboard::formats() : $formats));
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        Pasteboard::writeData($data, $released);
    }

    public function formats(): array
    {
        return Pasteboard::formats();
    }

    public function flush(): void
    {
        Pasteboard::flush();
    }

    public function readText(): string
    {
        return Pasteboard::text();
    }

    public function writeText(string $text): void
    {
        Pasteboard::setText($text);
    }

    public function readHTML(): string
    {
        return Pasteboard::string('public.html');
    }

    public function readImage(): string
    {
        return Pasteboard::image();
    }

    public function isImage(string $bytes): bool
    {
        return Pasteboard::isImage($bytes);
    }

    public function clear(): void
    {
        Pasteboard::clear();
    }

    public function availableFormats(): array
    {
        return Pasteboard::systemTypes();
    }
}
