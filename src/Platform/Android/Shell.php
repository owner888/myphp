<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Native\Android;
use MyPHP\Platform;

/**
 * Android system shell: URLs via Intent(ACTION_VIEW).
 */
final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        if ($url !== '') {
            Android::ffi()->myphp_open_url($url);
        }
    }

    public function openPath(string $path): void
    {
        $this->openExternal('file://' . $path);
    }

    public function showItemInFolder(string $path): void {}

    public function trashItem(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function beep(): void {}

    public function fileIcon(string $path, int $size): string
    {
        return '';
    }

    public function openApplication(string $path): void {}

    public function isLocal(string $path): bool
    {
        return true;
    }

    public function appIcon(int $size): string
    {
        return '';
    }
}
