<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/**
 * The desktop (mygo's Shell): URLs and files opened with their default
 * applications, the Finder, the Trash, file icons, the installed apps and
 * the alert sound. Call it once the app runs.
 */
final class Shell
{
    /** Opens a URL with its default application, as a web page in the browser (mygo's OpenExternal); throws when it is no URL or no app opens it. */
    public static function openExternal(string $url): void
    {
        self::backend()->openExternal($url);
    }

    /** Opens a file or folder with its default application (mygo's OpenPath); throws when it cannot. */
    public static function openPath(string $path): void
    {
        self::backend()->openPath($path);
    }

    /** Shows the file in a Finder window, selected (mygo's ShowItemInFolder). */
    public static function showItemInFolder(string $path): void
    {
        self::backend()->showItemInFolder($path);
    }

    /** Moves a file or folder to the Trash (mygo's TrashItem); throws with the system's reason when it cannot. */
    public static function trashItem(string $path): void
    {
        App::backend()->shell()->trashItem($path);
    }

    /**
     * The icon the Finder shows for a file, a folder or an application, as
     * a PNG image of $size by $size pixels (mygo's FileIcon): the size in
     * points times the display's scale gives a sharp one. A symbolic link
     * gets the icon of what it points to.
     */
    public static function fileIcon(string $path, int $size): string
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('myphp: Shell::fileIcon: size must be positive');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException("myphp: $path: no such file");
        }
        return self::backend()->fileIcon($real, $size);
    }

    /**
     * @internal UI\MediaPlayerState: whether the file at $path is on a
     * volume of this computer, not a mounted remote drive, as IINA asks
     * before making a film's thumbnails.
     */
    public static function isLocal(string $path): bool
    {
        return App::backend()->shell()->isLocal($path);
    }

    /** @internal UI\MediaPlayerWelcome: the app's icon (the Dock's) as a PNG of $size by $size pixels. */
    public static function appIcon(int $size): string
    {
        return App::backend()->shell()->appIcon($size);
    }

    /**
     * The applications installed on the computer, sorted by name, as a
     * launcher shows them (mygo's InstalledApps). It reads the disk.
     * @return list<InstalledApp>
     */
    public static function installedApps(): array
    {
        return App::backend()->applications();
    }

    /** Starts an app of installedApps(), or brings it to the front if it runs (mygo's LaunchApp). */
    public static function launchApp(InstalledApp $app): void
    {
        if ($app->path === '') {
            throw new \InvalidArgumentException('myphp: Shell::launchApp: the app has no path');
        }
        self::backend()->openApplication($app->path);
    }

    /** Plays the system's alert sound (mygo's Beep). */
    public static function beep(): void
    {
        self::backend()->beep();
    }

    private static function backend(): Platform\Shell
    {
        if (!App::running()) {
            throw new RuntimeException('myphp: Shell needs the running app (App::whenReady)');
        }
        return App::backend()->shell();
    }
}
