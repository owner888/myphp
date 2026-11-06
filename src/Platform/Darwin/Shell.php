<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use FFI;
use MyPHP\InstalledApp;
use MyPHP\Native\{Drag, ObjC};
use MyPHP\Platform;
use RuntimeException;

/** NSWorkspace, the Finder and the Trash (mygo's darwin shell). */
final class Shell implements Platform\Shell
{
    private static ?FFI $kit = null;

    public function openExternal(string $url): void
    {
        $u = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($url)) ?? throw new RuntimeException("myphp: invalid URL \"$url\"");
        if (!ObjC::msg('b:p', self::workspace(), 'openURL:', $u)) {
            throw new RuntimeException("myphp: no application can open \"$url\"");
        }
    }

    public function openPath(string $path): void
    {
        if (!ObjC::msg('b:p', self::workspace(), 'openURL:', self::fileURL($path))) {
            throw new RuntimeException("myphp: cannot open \"$path\"");
        }
    }

    public function openApplication(string $path): void
    {
        $this->openPath($path);
    }

    public function showItemInFolder(string $path): void
    {
        ObjC::msg('v:p', self::workspace(), 'activateFileViewerSelectingURLs:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', self::fileURL($path)));
    }

    public function trashItem(string $path): void
    {
        $err = ObjC::$c->new('void*[1]');
        $fm = ObjC::msg('p:', ObjC::cls('NSFileManager'), 'defaultManager');
        if (!ObjC::msg('b:ppp', $fm, 'trashItemAtURL:resultingItemURL:error:', self::fileURL($path), null, $err)) {
            $why = $err[0] === null ? 'cannot move to the Trash' : ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription'));
            throw new RuntimeException("myphp: $path: $why");
        }
    }

    public function fileIcon(string $path, int $size): string
    {
        $img = ObjC::msg('p:p', self::workspace(), 'iconForFile:', ObjC::str($path));
        $png = $img === null ? null : self::png($img, $size);
        return $png ?? throw new RuntimeException("myphp: no icon for \"$path\"");
    }

    /** Whether the file is on a volume of this Mac, not a mounted remote drive (NSURLVolumeIsLocalKey). */
    public function isLocal(string $path): bool
    {
        $keys = ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', ObjC::str('NSURLVolumeIsLocalKey'));
        $values = ObjC::msg('p:pp', self::fileURL($path), 'resourceValuesForKeys:error:', $keys, null);
        $local = $values === null ? null : ObjC::msg('p:p', $values, 'objectForKey:', ObjC::str('NSURLVolumeIsLocalKey'));
        if ($local === null) {
            throw new RuntimeException("myphp: no volume known of $path");
        }
        return ObjC::msg('b:', $local, 'boolValue');
    }

    /** The app's icon (the Dock's, NSApp's applicationIconImage). */
    public function appIcon(int $size): string
    {
        $img = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'applicationIconImage');
        $png = $img === null ? null : self::png($img, $size);
        return $png ?? throw new RuntimeException('myphp: the app has no icon');
    }

    public function beep(): void
    {
        (self::$kit ??= FFI::cdef('void NSBeep(void);', '/System/Library/Frameworks/AppKit.framework/AppKit'))->NSBeep();
    }

    /** NSHapticFeedbackManager's patterns for the kinds: a selection ticks (LevelChange, 2), the rest are the generic tap (0). Alignment (1) is for snapping while dragging, which no kind is. */
    public const PATTERNS = ['light' => 0, 'medium' => 0, 'heavy' => 0, 'soft' => 0, 'rigid' => 0, 'selection' => 2, 'success' => 0, 'warning' => 0, 'error' => 0];

    public function haptic(string $kind): void
    {
        $performer = ObjC::msg('p:', ObjC::cls('NSHapticFeedbackManager'), 'defaultPerformer');
        ObjC::msg('v:ll', $performer, 'performFeedbackPattern:performanceTime:', self::PATTERNS[$kind], 1); // NSHapticFeedbackPerformanceTimeNow
    }

    /**
     * The applications installed on the Mac, sorted by name, as a launcher
     * shows them (mygo's darwin Applications): the .app bundles in
     * /Applications, /System/Applications, ~/Applications and CoreServices'
     * Applications, and in their folders (Utilities), plus the Finder.
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
        return ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
    }
}
