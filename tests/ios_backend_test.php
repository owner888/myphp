<?php
// The iOS backend's contracts and phone guardrails (Platform\iOS, not mygo's):
// the bundle's flat layout (Info.plist and resources at the bundle's root,
// App::version() and App::path('resources') reading them); the system locale
// (preferredLanguages, region, saveLocale); what a phone does not have
// throwing Platform\Unsupported (a second window, the menu bar, context menus,
// the tray, global shortcuts, the Dock, the about panel, window placement,
// Dialog::save, Dialog::prompt, Dialog::login, Dialog::open of a directory);
// the event loop's timers and signal() draining App::later().
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{AboutPanelOptions, App, AppHandler, Dock, GlobalShortcut, Menu, MenuItem, OpenDialogOptions, PathName, Rectangle, SaveDialogOptions, Tray, TrayOptions};
use MyPHP\Native\{Bundle, Plist};
use MyPHP\Platform\iOS\{AppController, Backend, Dialogs};
use MyPHP\Platform\Unsupported;

(function (): void {
    $throwsUnsupported = function (\Closure $fn): bool {
        try {
            $fn();
            return false;
        } catch (Unsupported) {
            return true;
        }
    };

    // 1. iOS bundle layout: Info.plist and resources sit in the .app root, not Contents/.
    $tmp = sys_get_temp_dir() . '/myphp-ios-bundle-' . bin2hex(random_bytes(4)) . '.app';
    mkdir($tmp, 0755, true);
    file_put_contents("$tmp/Info.plist", \MyPHP\Native\Build\Bundle::xml([
        'CFBundleName' => 'PhoneDemo',
        'CFBundleIdentifier' => 'com.example.phonedemo',
        'CFBundleShortVersionString' => '2.4.0',
        'CFBundleVersion' => '2.4.0',
        'CFBundleExecutable' => 'PhoneDemo',
        'MyPHPProduction' => true,
    ]));
    file_put_contents("$tmp/sample.txt", 'ios resource');

    $refLaunched = new \ReflectionProperty(Bundle::class, 'launched');
    $refInfo = new \ReflectionProperty(Bundle::class, 'info');
    $prevLaunched = $refLaunched->getValue();
    $prevInfo = $refInfo->getValue();
    $prevPlatform = getenv('MYPHP_PLATFORM');

    try {
        putenv('MYPHP_PLATFORM=ios');
        $refLaunched->setValue(null, $tmp);
        $refInfo->setValue(null, null);

        same(true, App::isIOS(), 'MYPHP_PLATFORM=ios marks the iOS runtime');
        same($tmp, Bundle::path(), 'the bundle path');
        same($tmp, Bundle::resources(), 'on iOS the resources sit in the bundle root');
        same($tmp, App::path(PathName::Resources), 'App::path(Resources) is the iOS bundle root');
        same('2.4.0', App::version(), 'App::version() reads <bundle>/Info.plist on iOS');
        same(["$tmp/PhoneDemo"], Bundle::command(), 'Bundle::command() points to <bundle>/<Executable> on iOS');
        same(false, App::isDev(), 'MyPHPProduction in <bundle>/Info.plist turns App::isDev() off');

        // 2. What a phone does not have throws Platform\Unsupported (docs/mobile.md).
        check($throwsUnsupported(fn() => App::setMenu(new Menu([new MenuItem('File')]))), 'App::setMenu throws Unsupported on iOS');
        same(null, Menu::default(), 'Menu::default() is null on iOS');
        check($throwsUnsupported(fn() => Dock::setMenu(new Menu())), 'Dock::setMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => Dock::hide()), 'Dock::hide throws Unsupported on iOS');
        check($throwsUnsupported(fn() => Dock::show()), 'Dock::show throws Unsupported on iOS');

        $b = new Backend(new AppHandler());
        same('ios/metal', $b->name(), 'Backend::name()');
        check($throwsUnsupported(fn() => $b->popupMenu(new Menu()->snapshot(), null, null)), 'Backend::popupMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $b->newTray(new class implements \MyPHP\Platform\TrayHandler {
            public function clicked(): void {}
            public function rightClicked(): void {}
            public function appearanceChanged(): void {}
        })), 'Backend::newTray throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $b->registerHotkey(1, 'CmdOrCtrl+K')), 'Backend::registerHotkey throws Unsupported on iOS');

        $app = $b->app();
        check($throwsUnsupported(fn() => $app->setBadge('1')), 'AppController::setBadge throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->badge()), 'AppController::badge throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->bounce(false)), 'AppController::bounce throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->cancelBounce(0)), 'AppController::cancelBounce throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->setDockIcon('')), 'AppController::setDockIcon throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->setDockMenu(null)), 'AppController::setDockMenu throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $app->showAboutPanel(new AboutPanelOptions())), 'AppController::showAboutPanel throws Unsupported on iOS');

        $dialogs = $b->dialogs();
        check($throwsUnsupported(fn() => $dialogs->prompt(null, 'Name', '')), 'Dialog::prompt throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->login(null, 'Sign in', '', '', '')), 'Dialog::login throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->save(new SaveDialogOptions())), 'Dialog::save throws Unsupported on iOS');
        check($throwsUnsupported(fn() => $dialogs->open(new OpenDialogOptions(directory: true))), 'Dialog::open(directory: true) throws Unsupported on iOS');
        if (PHP_OS_FAMILY === 'Darwin') {
            \MyPHP\Native\ObjC::boot(['Foundation']);
            $langs = $app->preferredLanguages();
            check($langs !== [] && is_string($langs[0]) && $langs[0] !== '', 'AppController::preferredLanguages reads NSLocale/AppleLanguages');
            $region = $app->region();
            check($region->locale !== '' && in_array($region->hourCycle, [12, 24], true), 'AppController::region reads NSLocale/NSCalendar');
        }
    } finally {
        $refLaunched->setValue(null, $prevLaunched);
        $refInfo->setValue(null, $prevInfo);
        putenv($prevPlatform === false ? 'MYPHP_PLATFORM' : "MYPHP_PLATFORM=$prevPlatform");
        @unlink("$tmp/Info.plist");
        @unlink("$tmp/sample.txt");
        @rmdir($tmp);
    }
})();