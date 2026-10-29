<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use Closure;
use DateTimeImmutable;
use MyPHP\{App, MenuItem, Plugin};
use RuntimeException;

/**
 * The update window of MyPHP apps (mygo's plugins/updater), in the manner of
 * Sparkle: it checks for updates in the background, once a day by default,
 * and when a new version is out shows its release notes and offers to
 * install it, skip it or remind the user later. The first check is soon
 * after launch, so a version that came out since the app last ran shows
 * when it opens again. Installing downloads the update with a progress
 * bar, then offers to relaunch the app into it.
 *
 *     App::use(Updater::plugin());
 *
 * and "Check for Updates…" in the app's menu, after About:
 *
 *     new MenuItem(label: 'My App', submenu: [new MenuItem(role: 'about'), Updater::menuItem(), ...])
 *
 * It is built on MyPHP\Updater, so the app must be built with updates
 * (myphp.json's "updates"). Development runs never check in the
 * background, and say why when the user checks.
 *
 * The user's choices are kept in updater.json in the app's user data
 * directory: whether to check automatically, whether to install updates
 * without asking (the checkbox of the window), the version they skipped
 * and when the app last checked. onChange() tells the app when they
 * changed, for a preferences page.
 */
final class Updater
{
    private static ?Checker $active = null;

    /** The plugin with the default options (mygo's updater.Plugin). */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /** The plugin with $opts (mygo's updater.New). Use one of them only. */
    public static function new(Options $opts): Plugin
    {
        return self::with($opts, null);
    }

    /**
     * The plugin with $opts whose windows $open creates (mygo's
     * frontend.Plugin): Native\Native's, or the web page's for null.
     *
     * @internal
     * @param ?Closure(Checker, Session): void $open
     */
    public static function with(Options $opts, ?Closure $open): Plugin
    {
        $u = new Checker($opts, $open);
        return new Plugin('updater', new Service($u), function () use ($u, $opts): void {
            foreach ($opts->strings as $lang => $s) {
                if (($err = $s->check()) !== null) {
                    throw new RuntimeException("updater: strings of \"$lang\": $err");
                }
            }
            if (self::$active !== null) {
                throw new RuntimeException('updater: another updater plugin is used');
            }
            self::$active = $u;
            App::whenReady(fn() => $u->start());
        });
    }

    /**
     * Checks for updates as the user asked, from a menu item or a button:
     * the window shows right away, says when the app is up to date or the
     * check failed, and shows updates the user skipped. It returns at once;
     * while a check is under way it brings its window to the front.
     */
    public static function checkForUpdates(): void
    {
        $u = self::used();
        ($u->go)(fn() => $u->begin(true));
    }

    /** A "Check for Updates…" item that calls checkForUpdates(), labeled in the language the app shows now: an app rebuilding its menu as its language changes (App::onLocaleChanged) gets it relabeled. */
    public static function menuItem(): MenuItem
    {
        return new MenuItem(label: self::used()->text()->s->menuItem, click: fn() => self::checkForUpdates());
    }

    /** Whether the app checks for updates in the background. */
    public static function automaticChecks(): bool
    {
        $u = self::used();
        $u->load();
        return $u->automaticChecks();
    }

    /** Turns checking for updates in the background on or off, for a preference of the app. */
    public static function setAutomaticChecks(bool $on): void
    {
        $u = self::used();
        $u->update(fn(array $s): array => ['automaticChecks' => $on] + $s);
        $u->schedule();
    }

    /** Whether updates found in the background install without asking: they run the next time the app starts. The window offers to turn it on. */
    public static function automaticDownloads(): bool
    {
        $u = self::used();
        $u->load();
        return $u->state['automaticDownloads'];
    }

    /** Turns installing updates found in the background without asking on or off. */
    public static function setAutomaticDownloads(bool $on): void
    {
        self::used()->update(fn(array $s): array => ['automaticDownloads' => $on] + $s);
    }

    /** When the app last checked for updates successfully, or null. */
    public static function lastCheck(): ?DateTimeImmutable
    {
        $u = self::used();
        $u->load();
        return $u->state['lastCheck'];
    }

    /**
     * Calls $fn after the choices of the user or the time of the last
     * check changed: a check succeeded, the user answered the window (its
     * checkbox, Skip This Version), or the app called setAutomaticChecks()
     * or setAutomaticDownloads(). Returns a function that removes it.
     */
    public static function onChange(Closure $fn): Closure
    {
        return self::used()->onChange($fn);
    }

    private static function used(): Checker
    {
        return self::$active ?? throw new RuntimeException('updater: the plugin is not used: call App::use(Updater::plugin()) before App::run');
    }
}
