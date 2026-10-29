<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\App;
use MyPHP\Logger;

/**
 * One instance of the app (mygo's single_instance.go): the first takes a
 * Unix socket of the user's, named after the app, and later ones hand it
 * their command line and working directory there, which onSecondInstance
 * and the deep links get; they then exit. The socket is polled on the run
 * loop, as Process is.
 *
 * @internal
 */
final class SingleInstance
{
    /** How often the first instance looks for later ones, in seconds. */
    public const POLL = 0.1;
    /** @var array<int, Closure(list<string>, string): void> */
    public static array $handlers = [];
    /** @var resource|null */
    private static $server = null;
    private static string $path = '';
    /** The socket file's inode, which identifies ours at $path. */
    private static ?int $inode = null;
    private static bool $locked = false;
    /** The arguments and working directory of this instance; tests set them. @var ?array{0: list<string>, 1: string} @internal */
    public static ?array $self = null;

    public static function request(): bool
    {
        if ((string)getenv('MYPHP_GENERATE') !== '') {
            return true; // App::run will only write the TypeScript client
        }
        if (self::$locked) {
            return true;
        }
        $path = self::socket(self::key(App::name()));
        [$args, $wd] = self::$self ?? [array_slice($_SERVER['argv'] ?? [], 1), (string)getcwd()];
        $msg = json_encode(['args' => array_values($args), 'workingDir' => $wd], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $conn = @stream_socket_client("unix://$path", $errno, $err, 1.0);
            if ($conn !== false) {
                // Another instance owns the lock: hand over our arguments.
                stream_set_timeout($conn, 2);
                fwrite($conn, $msg);
                stream_socket_shutdown($conn, STREAM_SHUT_WR);
                stream_get_contents($conn); // wait until it was read
                fclose($conn);
                if (getenv('MYPHP_DEV') === '1') {
                    Logger::debug(App::name() . ' is already running: handed the command line over to it (requestSingleInstanceLock)');
                }
                return false;
            }
            // Nobody answers: remove a stale socket left by a crash and take
            // over. If another instance wins the race, listening fails and the
            // next attempt connects to it.
            @unlink($path);
            $server = @stream_socket_server("unix://$path", $errno, $err);
            if ($server !== false) {
                stream_set_blocking($server, false);
                clearstatcache(true, $path);
                $st = @stat($path);
                [self::$server, self::$path, self::$inode, self::$locked] = [$server, $path, $st === false ? null : $st['ino'], true];
                App::whenReady(fn() => App::after(self::POLL, self::poll(...)));
                App::onQuit(self::release(...));
                return true;
            }
            if ($errno !== 48 && $errno !== 98) { // EADDRINUSE: macOS's, Linux's
                // Locking is not possible here (a read-only temporary directory): behave as the only instance.
                return true;
            }
            usleep(($attempt + 1) * 20_000);
        }
        return true;
    }

    /** Takes the command lines of the later instances that connected, and looks again a little later. */
    public static function poll(): void
    {
        if (self::$server === null) {
            return;
        }
        self::accept();
        App::after(self::POLL, self::poll(...));
    }

    /** Takes the command lines of the later instances that connected. @internal */
    public static function accept(): void
    {
        while (($conn = @stream_socket_accept(self::$server, 0)) !== false) {
            stream_set_timeout($conn, 2);
            $data = (string)stream_get_contents($conn, 1 << 20);
            fclose($conn);
            $msg = json_decode($data, true);
            if (!is_array($msg) || !is_array($msg['args'] ?? null) || !is_string($msg['workingDir'] ?? null)) {
                continue;
            }
            $args = array_values(array_map('strval', $msg['args']));
            foreach (self::$handlers as $fn) {
                $fn($args, $msg['workingDir']);
            }
            DeepLink::deliverArgs($args, $msg['workingDir']);
        }
    }

    public static function release(): void
    {
        if (self::$server === null) {
            return;
        }
        fclose(self::$server);
        clearstatcache(true, self::$path);
        $st = @stat(self::$path);
        // Removed only if it is still ours: an instance that found it stale may have replaced it.
        if ($st !== false && $st['ino'] === self::$inode) {
            @unlink(self::$path);
        }
        [self::$server, self::$inode, self::$locked] = [null, null, false];
    }

    /** Identifies the lock of the app named $name: with the bundle's identifier, so that a development run stays apart from the installed app. */
    public static function key(string $name): string
    {
        $id = Bundle::info('CFBundleIdentifier');
        return $id !== null && $id !== '' ? "$id\n$name" : $name;
    }

    /** A short per-user socket path: Unix socket paths are limited to about 100 bytes. */
    public static function socket(string $name): string
    {
        $file = 'myphp-' . getmyuid() . '-' . substr(hash('sha256', $name), 0, 12) . '.sock';
        foreach ([(string)getenv('XDG_RUNTIME_DIR'), sys_get_temp_dir(), '/tmp'] as $dir) {
            if ($dir !== '' && strlen("$dir/$file") < 100) {
                return rtrim($dir, '/') . "/$file";
            }
        }
        return "/tmp/$file";
    }
}
