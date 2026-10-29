<?php
declare(strict_types=1);

namespace MyPHP;

use FFI;
use MyPHP\Native\{Drag, ObjC};
use RuntimeException;

/**
 * The desktop (mygo's Shell): URLs and files opened with their default
 * applications, the Finder, the Trash, file icons, the installed apps and
 * the alert sound. Call it once the app runs.
 */
final class Shell
{
    private static ?FFI $kit = null;

    /** Opens a URL with its default application, as a web page in the browser (mygo's OpenExternal); throws when it is no URL or no app opens it. */
    public static function openExternal(string $url): void
    {
        $u = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($url)) ?? throw new RuntimeException("myphp: invalid URL \"$url\"");
        if (!ObjC::msg('b:p', self::workspace(), 'openURL:', $u)) {
            throw new RuntimeException("myphp: no application can open \"$url\"");
        }
    }

    /** Opens a file or folder with its default application (mygo's OpenPath); throws when it cannot. */
    public static function openPath(string $path): void
    {
        if (!ObjC::msg('b:p', self::workspace(), 'openURL:', self::fileURL($path))) {
            throw new RuntimeException("myphp: cannot open \"$path\"");
        }
    }

    /** Shows the file in a Finder window, selected (mygo's ShowItemInFolder). */
    public static function showItemInFolder(string $path): void
    {
        ObjC::msg('v:p', self::workspace(), 'activateFileViewerSelectingURLs:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', self::fileURL($path)));
    }

    /** Moves a file or folder to the Trash (mygo's TrashItem); throws with the system's reason when it cannot. */
    public static function trashItem(string $path): void
    {
        $err = ObjC::$c->new('void*[1]');
        $fm = ObjC::msg('p:', ObjC::cls('NSFileManager'), 'defaultManager');
        if (!ObjC::msg('b:ppp', $fm, 'trashItemAtURL:resultingItemURL:error:', self::fileURL($path), null, $err)) {
            $why = $err[0] === null ? 'cannot move to the Trash' : ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription'));
            throw new RuntimeException("myphp: $path: $why");
        }
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
        $img = ObjC::msg('p:p', self::workspace(), 'iconForFile:', ObjC::str($real));
        $png = $img === null ? null : self::png($img, $size);
        return $png ?? throw new RuntimeException("myphp: no icon for \"$real\"");
    }

    /** @internal UI\VideoPlayerWelcome: the app's icon (the Dock's, NSApp's applicationIconImage) as a PNG of $size by $size pixels. */
    public static function appIcon(int $size): string
    {
        $img = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'applicationIconImage');
        $png = $img === null ? null : self::png($img, $size);
        return $png ?? throw new RuntimeException('myphp: the app has no icon');
    }

    /**
     * The applications installed on the Mac, sorted by name, as a launcher
     * shows them (mygo's InstalledApps): the .app bundles in /Applications,
     * /System/Applications, ~/Applications and CoreServices' Applications,
     * and in their folders (Utilities), plus the Finder. It reads the disk.
     * @return list<InstalledApp>
     */
    public static function installedApps(): array
    {
        $paths = []; $seen = [];
        $add = function (string $p) use (&$paths, &$seen): void {
            $real = realpath($p) ?: $p;
            if (!isset($seen[$real])) { $seen[$real] = true; $paths[] = $p; } // an app a link points to is listed at the link
        };
        foreach (['/Applications', '/System/Applications', '/System/Library/CoreServices/Applications', getenv('HOME') . '/Applications'] as $dir) {
            foreach (self::entries($dir) as $e) {
                $p = "$dir/$e";
                if (str_ends_with($e, '.app')) { $add($p); continue; }
                if (!is_dir($p)) { continue; }
                foreach (self::entries($p) as $sub) {
                    if (str_ends_with($sub, '.app')) { $add("$p/$sub"); }
                }
            }
        }
        $add('/System/Library/CoreServices/Finder.app');
        $fm = ObjC::msg('p:', ObjC::cls('NSFileManager'), 'defaultManager');
        $apps = [];
        foreach ($paths as $p) {
            $ns = ObjC::str($p);
            $bundle = ObjC::msg('p:p', ObjC::cls('NSBundle'), 'bundleWithPath:', $ns);
            if ($bundle === null) { continue; }
            $id = ObjC::msg('p:', $bundle, 'bundleIdentifier');
            $apps[] = new InstalledApp(preg_replace('/\.app$/', '', ObjC::phpStr(ObjC::msg('p:p', $fm, 'displayNameAtPath:', $ns))), $id === null ? $p : ObjC::phpStr($id), $p);
        }
        usort($apps, fn(InstalledApp $a, InstalledApp $b) => [mb_strtolower($a->name), $a->path] <=> [mb_strtolower($b->name), $b->path]);
        return $apps;
    }

    /** Starts an app of installedApps(), or brings it to the front if it runs (mygo's LaunchApp). */
    public static function launchApp(InstalledApp $app): void
    {
        if ($app->path === '') {
            throw new \InvalidArgumentException('myphp: Shell::launchApp: the app has no path');
        }
        self::openPath($app->path);
    }

    /** Plays the system's alert sound (mygo's Beep). */
    public static function beep(): void
    {
        self::workspace();
        (self::$kit ??= FFI::cdef('void NSBeep(void);', '/System/Library/Frameworks/AppKit.framework/AppKit'))->NSBeep();
    }

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        $e = is_dir($dir) ? scandir($dir) : false;
        return $e === false ? [] : array_values(array_filter($e, fn($n) => $n[0] !== '.'));
    }

    /** $img drawn in an sRGB bitmap of $size by $size pixels, as PNG (mygo's pngOfImage). */
    private static function png($img, int $size): ?string
    {
        $rep = ObjC::msg('p:pllllbbpll', ObjC::alloc('NSBitmapImageRep'), 'initWithBitmapDataPlanes:pixelsWide:pixelsHigh:bitsPerSample:samplesPerPixel:hasAlpha:isPlanar:colorSpaceName:bytesPerRow:bitsPerPixel:',
            null, $size, $size, 8, 4, true, false, ObjC::str('NSCalibratedRGBColorSpace'), 0, 0);
        if ($rep === null) {
            return null;
        }
        ObjC::msg('p:', $rep, 'autorelease');
        $rep = ObjC::msg('p:p', $rep, 'bitmapImageRepByRetaggingWithColorSpace:', ObjC::msg('p:', ObjC::cls('NSColorSpace'), 'sRGBColorSpace'));
        $gc = ObjC::cls('NSGraphicsContext');
        $ctx = ObjC::msg('p:p', $gc, 'graphicsContextWithBitmapImageRep:', $rep);
        if ($ctx === null) {
            return null;
        }
        ObjC::msg('v:', $gc, 'saveGraphicsState');
        ObjC::msg('v:p', $gc, 'setCurrentContext:', $ctx);
        ObjC::msg('v:L', $ctx, 'setImageInterpolation:', 3); // NSImageInterpolationHigh
        ObjC::msg('v:rrLd', $img, 'drawInRect:fromRect:operation:fraction:', ObjC::rect(0, 0, $size, $size), ObjC::rect(0, 0, 0, 0), 2, 1.0); // NSCompositingOperationSourceOver; the zero rect, all of it
        ObjC::msg('v:', $gc, 'restoreGraphicsState');
        $png = ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary')); // NSBitmapImageFileTypePNG
        return $png === null ? null : Drag::bytes($png);
    }

    private static function fileURL(string $path)
    {
        return ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path));
    }

    private static function workspace()
    {
        if (!App::running()) {
            throw new RuntimeException('myphp: Shell needs the running app (App::whenReady)');
        }
        return ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
    }
}
