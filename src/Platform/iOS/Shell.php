<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        $app = ObjC::cls('UIApplication')?->msg('sharedApplication');
        if ($app === null) {
            return;
        }
        $nsUrl = ObjC::cls('NSURL')?->msg('URLWithString:', ObjC::str($url));
        if ($nsUrl !== null) {
            $emptyDict = ObjC::cls('NSDictionary')?->msg('dictionary');
            $app->msg('openURL:options:completionHandler:', $nsUrl, $emptyDict, null);
        }
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
    }
}
