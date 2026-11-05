<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\Platform;

final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        iOS::ffi()->myphp_ios_open_url($url);
    }

    public function showItemInFolder(string $path): void
    {
        // iOS sandboxed: no Finder
    }

    public function openPath(string $path): void
    {
        $this->openExternal('file://' . $path);
    }

    public function trashItem(string $path): void
    {
        @unlink($path);
    }

    public function beep(): void
    {
        \MyPHP\Native\iOS::ffi()->myphp_ios_haptic_feedback(0);
    }

    public function fileIcon(string $path, int $size): string
    {
        return '';
    }

    public function openApplication(string $path): void
    {
    }

    public function isLocal(string $path): bool
    {
        return true;
    }

    public function appIcon(int $size): string
    {
        return '';
    }
}
