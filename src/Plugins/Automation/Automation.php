<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use MyPHP\{App, Plugin};

/**
 * The automation plugin (mygo's plugins/automation): another process, an
 * end-to-end test runner or an agent, drives the running app. It lists
 * its windows, reads what their native UI shows as the elements
 * assistive technology sees, acts on them, sends pointer, wheel, key and
 * text input, chooses items of the application menu, evaluates scripts
 * in pages, captures windows as PNG images and waits for them to settle,
 * in newline-delimited JSON over a Unix domain socket.
 *
 *     if (getenv('MYPHP_AUTOMATION') !== false) {
 *         App::use(Automation::plugin()); // the app's test runs, not its release
 *     }
 *
 *     MYPHP_AUTOMATION=/tmp/app.sock php app.php
 *
 * It listens only when MYPHP_AUTOMATION names the path of the socket, and
 * does nothing otherwise; the app removes the socket when it quits. The
 * protocol is docs/automation.md's.
 */
final class Automation
{
    /** How often the socket is looked at for connections and requests, in seconds, and idle's windows. */
    public const POLL = 0.005;

    /** The plugin (mygo's automation package, imported). */
    public static function plugin(): Plugin
    {
        return new Plugin('automation', null, self::setup(...));
    }

    private static function setup(): void
    {
        $path = (string)getenv('MYPHP_AUTOMATION');
        if ($path === '') {
            return;
        }
        try {
            $server = Server::listen($path);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "myphp: automation: {$e->getMessage()}\n");
            return;
        }
        $driver = new AppDriver;
        $poll = function () use ($server, $driver, &$poll): void {
            if ($server->poll($driver)) {
                App::after(self::POLL, $poll);
            }
        };
        App::whenReady(fn() => App::after(self::POLL, $poll));
        App::onQuit($server->close(...));
    }
}
