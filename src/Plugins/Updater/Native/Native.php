<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater\Native;

use MyPHP\Plugin;
use MyPHP\Plugins\Updater\{Options, Updater};

/**
 * The update window of the updater plugin drawn in native UI instead of a
 * web page (mygo's plugins/updater/native), for apps whose windows all
 * show native UI. Use it in place of Updater::plugin():
 *
 *     App::use(Native::plugin()); // or Native::new(new Options(interval: ..., icon: ...))
 *
 * The rest is Updater's: Updater::menuItem() and Updater::checkForUpdates()
 * check from the app's menu, and the preferences (setAutomaticChecks(),
 * onChange()…) work the same.
 */
final class Native
{
    /** The plugin with the default options (mygo's native.Plugin). */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /** The plugin with $opts (mygo's native.New). Use one of them only, and neither Updater::plugin() nor Updater::new(). */
    public static function new(Options $opts): Plugin
    {
        return Updater::with($opts, UpdateWindow::open(...));
    }
}
