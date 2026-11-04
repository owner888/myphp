<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        try {
            ObjC::boot(['UIKit']);
            $app = ObjC::cls('UIApplication')?->msg('sharedApplication');
            if ($app === null) {
                return;
            }
            $nsUrl = ObjC::cls('NSURL')?->msg('URLWithString:', ObjC::str($url));
            if ($nsUrl !== null) {
                $emptyDict = ObjC::cls('NSDictionary')?->msg('dictionary');
                $app->msg('openURL:options:completionHandler:', $nsUrl, $emptyDict, null);
            }
        } catch (\Throwable $e) {}
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
        try {
            \MyPHP\Native\iOS::ffi()->myphp_ios_haptic_feedback(0);
        } catch (\Throwable $e) {}
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
