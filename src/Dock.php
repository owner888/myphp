<?php
declare(strict_types=1);

namespace MyPHP;

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

    /** Sets the menu the Dock icon shows above the standard items, as to open a new window; null removes it (mygo's SetMenu). */
    public static function setMenu(?Menu $menu): void
    {
        self::$menu = $menu;
        if (App::running()) {
            App::backend()->app()->setDockMenu($menu?->snapshot());
        }
    }

    /** The menu setMenu() set (mygo's Menu). */
    public static function menu(): ?Menu
    {
        return self::$menu;
    }

    /** Shows $text on the Dock icon; '' clears it (mygo's SetBadge). */
    public static function setBadge(string $text): void
    {
        self::app()->setBadge($text);
    }

    /** The text the Dock icon shows (mygo's Badge). */
    public static function badge(): string
    {
        return self::app()->badge();
    }

    /** Bounces the Dock icon for the user's attention, until the app is activated when $critical, else once; returns the id cancelBounce() takes (mygo's Bounce). */
    public static function bounce(bool $critical = false): int
    {
        return self::app()->bounce($critical);
    }

    /** Stops a bounce bounce() started (mygo's CancelBounce). */
    public static function cancelBounce(int $id): void
    {
        self::app()->cancelBounce($id);
    }

    /** Replaces the Dock icon with a PNG image, or the bundle's again for '' (mygo's SetIcon); throws when it is no image. */
    public static function setIcon(string $png): void
    {
        self::app()->setDockIcon($png);
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

    private static function app(): Platform\AppController
    {
        if (!App::running()) {
            throw new RuntimeException('myphp: the Dock needs the running app (App::whenReady)');
        }
        return App::backend()->app();
    }
}
