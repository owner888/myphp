<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** The desktop environment (mygo's platform.Shell). */
interface Shell
{
    public function openExternal(string $url): void;

    public function openPath(string $path): void;

    public function showItemInFolder(string $path): void;

    public function trashItem(string $path): void;

    public function beep(): void;

    /** The icon the file manager shows for a file, a folder or an application, as a PNG of $size by $size pixels. */
    public function fileIcon(string $path, int $size): string;

    /** Starts the application at InstalledApp::$path, or brings it to the front. */
    public function openApplication(string $path): void;

    /** Whether the file is on a volume of this computer, not a mounted remote drive (myphp's, for the player). */
    public function isLocal(string $path): bool;

    /** The app's icon as a PNG of $size by $size pixels (myphp's, for the player). */
    public function appIcon(int $size): string;
}
