<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;
use RuntimeException;

/**
 * The app's Dock icon (mygo's App.Dock): a menu of the app's above the
 * standard items, a badge, a bounce for the user's attention, another
 * icon. setMenu() may be called before App::run; the rest once the app
 * runs.
 */
final class Dock
{
    private static ?Menu $menu = null;
    /** The NSMenu built of $menu, which the app delegate hands AppKit (applicationDockMenu:). */
    private static $native = null;

    /** Sets the menu the Dock icon shows above the standard items, as to open a new window; null removes it (mygo's SetMenu). */
    public static function setMenu(?Menu $menu): void
    {
        self::$menu = $menu;
        if (self::$native !== null) {
            ObjC::msg('v:', self::$native, 'release');
            self::$native = null;
        }
        if ($menu !== null && App::running()) {
            self::$native = $menu->build(); // owned: alloc'd
        }
    }

    /** The menu setMenu() set (mygo's Menu). */
    public static function menu(): ?Menu
    {
        return self::$menu;
    }

    /** @internal the NSMenu AppKit shows, built once the app runs */
    public static function nativeMenu()
    {
        if (self::$native === null && self::$menu !== null) {
            self::$native = self::$menu->build();
        }
        return self::$native;
    }

    /** Shows $text on the Dock icon; '' clears it (mygo's SetBadge). */
    public static function setBadge(string $text): void
    {
        ObjC::msg('v:p', self::tile(), 'setBadgeLabel:', $text === '' ? null : ObjC::str($text));
    }

    /** The text the Dock icon shows (mygo's Badge). */
    public static function badge(): string
    {
        $s = ObjC::msg('p:', self::tile(), 'badgeLabel');
        return $s === null ? '' : ObjC::phpStr($s);
    }

    /** Bounces the Dock icon for the user's attention, until the app is activated when $critical, else once; returns the id cancelBounce() takes (mygo's Bounce). */
    public static function bounce(bool $critical = false): int
    {
        return ObjC::msg('l:L', self::app(), 'requestUserAttention:', $critical ? 0 : 10); // NSCriticalRequest, NSInformationalRequest
    }

    /** Stops a bounce bounce() started (mygo's CancelBounce). */
    public static function cancelBounce(int $id): void
    {
        ObjC::msg('v:l', self::app(), 'cancelUserAttentionRequest:', $id);
    }

    /** Replaces the Dock icon with a PNG image, or the bundle's again for '' (mygo's SetIcon); throws when it is no image. */
    public static function setIcon(string $png): void
    {
        $img = null;
        if ($png !== '') {
            $img = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $png, strlen($png))) ?? throw new RuntimeException('myphp: Dock::setIcon: not an image');
        }
        ObjC::msg('v:p', self::app(), 'setApplicationIconImage:', $img);
        if ($img !== null) { ObjC::msg('v:', $img, 'release'); }
    }

    /** Removes the Dock icon: the accessory activation policy (mygo's Hide). */
    public static function hide(): void
    {
        App::setActivationPolicy(ActivationPolicy::Accessory);
    }

    /** Shows the Dock icon again: the regular activation policy (mygo's Show). */
    public static function show(): void
    {
        App::setActivationPolicy(ActivationPolicy::Regular);
    }

    private static function app()
    {
        if (!App::running()) {
            throw new RuntimeException('myphp: the Dock needs the running app (App::whenReady)');
        }
        return ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
    }

    private static function tile()
    {
        return ObjC::msg('p:', self::app(), 'dockTile');
    }
}
