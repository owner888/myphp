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

    /** View.HapticFeedbackConstants for the kinds (API 30's and under, minSdk 31): KEYBOARD_TAP 3, CONTEXT_CLICK 6, LONG_PRESS 0, CLOCK_TICK 4, VIRTUAL_KEY 1, CONFIRM 16, REJECT 17; Android has no warning apart from reject. */
    public const CONSTANTS = ['light' => 3, 'medium' => 6, 'heavy' => 0, 'soft' => 4, 'rigid' => 1, 'selection' => 4, 'success' => 16, 'warning' => 17, 'error' => 17];

    public function haptic(string $kind): void
    {
        \MyPHP\Native\Android::ffi()->myphp_perform_haptic_kind(self::CONSTANTS[$kind]);
    }

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
