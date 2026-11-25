<?php
// The update window of the updater plugin: "Check for Updates…" in the app
// menu, and the button below. Run from the project, a development run, it
// says that development builds do not update themselves; a bundle that
// `myphp build` made with myphp.json's "updates" checks its feed. With
// --demo, checks find a made-up version 9.9.9 whose "install" only counts
// up, to show the release notes, the progress and the relaunch offer. With
// --native, the window is drawn in native UI (Native::plugin()), as is the
// app's own.
// Run: myphp run -d macos examples/updater [--demo] [--native]
declare(strict_types=1);

use MyPHP\{App, Logger, Menu, MenuItem, Window, WindowOptions};
use MyPHP\Native\Fibers;
use MyPHP\Plugins\Updater\{Options, Release, Updater};
use MyPHP\Plugins\Updater\Native\Native;
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

App::setName('Updater Demo');
$native = in_array('--native', $argv, true);
$demo = in_array('--demo', $argv, true);
$opts = new Options(disableAutomaticChecks: true);
App::use($native ? Native::new($opts) : Updater::new($opts));

if ($demo) {
    // Not an API: the plugin's internals, so that a development run shows each view of the window.
    $u = (fn() => self::$active)->bindTo(null, Updater::class)();
    $u->enabled = fn(): bool => true;
    $u->relaunch = fn() => Logger::debug('the app would relaunch now', 'updater-demo');
    $u->available = '9.9.9';
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

App::whenReady(function () use ($native, $demo) {
    if ($native) {
        $state = new class {
            public string $tab = 'home';
            public string $available = '';
            public string $status = 'available';
            public float $progress = 0.0;
            public ?Closure $install = null;
        };
        $state->available = Updater::availableVersion();
        $win = new Window(new WindowOptions(
            title: 'Updater Demo',
            width: 780,
            height: 480,
            titleBarStyle: 'hiddenInset',
            vibrancy: 'sidebar',
            content: Content::file(__DIR__ . '/view.php', $state),
        ));
        $state->install = function () use ($state, $win, $demo): void {
            if (!$demo) {
                Updater::checkForUpdates();
                return;
            }
            if ($state->status === 'downloading') {
                return;
            }
            if ($state->status === 'installing') {
                Logger::debug('relaunching into 9.9.9', 'updater-demo');
                $state->available = '';
                $state->status = 'available';
                $win->requestFrame();
                return;
            }
            App::go(function () use ($state, $win): void {
                $state->status = 'downloading';
                $state->progress = 0.0;
                $win->requestFrame();
                for ($n = 1; $n <= 25; $n++) {
                    Fibers::sleep(0.05);
                    $state->progress = $n / 25;
                    $win->requestFrame();
                }
                $state->status = 'installing';
                $win->requestFrame();
            });
        };
        Updater::onChange(function () use ($state, $win): void {
            $state->available = Updater::availableVersion();
            $win->requestFrame();
        });
        return;
    }
    new Window(new WindowOptions(title: 'Updater', width: 420, height: 200, html: <<<'HTML'
        <!doctype html><meta charset="utf-8">
        <body style="font: 15px -apple-system, system-ui; padding: 24px">
        <p>Check from the app menu, or here:</p>
        <button onclick="myphp.call('Demo.check')">Check for Updates…</button>
        HTML));
});
App::run();
