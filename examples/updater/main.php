<?php
// The update window of the updater plugin: "Check for Updates…" in the app
// menu, and the button below. Run from the project, a development run, it
// says that development builds do not update themselves; a bundle that
// `myphp build` made with myphp.json's "updates" checks its feed. With
// --demo, checks find a made-up version 9.9.9 whose "install" only counts
// up, to show the release notes, the progress and the relaunch offer.
// Run: ./run.sh updater [--demo]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\Native\Fibers;
use MyPHP\Plugins\Updater\{Options, Release, Updater};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

App::setName('Updater Demo');
App::use(Updater::new(new Options(disableAutomaticChecks: true)));

if (in_array('--demo', $argv, true)) {
    // Not an API: the plugin's internals, so that a development run shows each view of the window.
    $u = (fn() => self::$active)->bindTo(null, Updater::class)();
    $u->enabled = fn(): bool => true;
    $u->relaunch = fn() => error_log('updater demo: the app would relaunch now');
    $u->check = function (Closure $canceled): Release {
        Fibers::sleep(1);
        return new Release('9.9.9', "## What's new\n\n- A **faster** start\n- Links such as [the changelog](https://example.com)\n\n### Fixed\n\n1. A crash on launch\n2. `Cmd+Q` while saving", function (?Closure $progress, Closure $canceled): void {
            for ($n = 0; $n <= 40; $n++) {
                if ($canceled()) {
                    throw new RuntimeException('context canceled');
                }
                $progress($n * 500_000, 20_000_000);
                Fibers::sleep(0.08);
            }
        });
    };
}

App::setMenu(new Menu([
    new MenuItem(role: 'appMenu', submenu: [new MenuItem(role: 'about'), Updater::menuItem(), MenuItem::separator(), new MenuItem(role: 'quit')]),
    new MenuItem(role: 'editMenu'),
    new MenuItem(role: 'windowMenu'),
]));

final class Demo
{
    public function check(): void
    {
        Updater::checkForUpdates();
    }
}
MyPHP\Ipc::bind(new Demo());

App::whenReady(function () {
    new Window(new WindowOptions(title: 'Updater', width: 420, height: 200, html: <<<'HTML'
        <!doctype html><meta charset="utf-8">
        <body style="font: 15px -apple-system, system-ui; padding: 24px">
        <p>Check from the app menu, or here:</p>
        <button onclick="myphp.call('Demo.check')">Check for Updates…</button>
        HTML));
});
App::run();
