<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/**
 * The app's log, as IINA's Logger: messages of a level (verbose, debug,
 * warning, error) and a subsystem ("app" by default; the video player's
 * "player0", "fmatcher", "ytdl"…), those under the level set dropped;
 * kept for the log viewer (Context::logViewer), written to a file of the
 * session (App::path(Logs)/<time>_<code>/<app>.log) once logToFile() turns
 * it on, and printed to the standard error once console() does. By
 * default a development app (App::isDev: `php app.php`, `myphp dev`,
 * `myphp build -debug`) logs from debug on and prints them; a production
 * one (`myphp build`) only warnings and errors, printing none.
 *
 *     Logger::warn('the cache is full');
 *     Logger::error("cannot open $path", 'player');
 *     Logger::setLevel(Logger::WARNING);
 *     Logger::logToFile(true);
 */
final class Logger
{
    public const VERBOSE = 0, DEBUG = 1, WARNING = 2, ERROR = 3;
    /** The levels' names and short forms, as IINA's. */
    public const NAMES = ['verbose', 'debug', 'warning', 'error'], SHORT = ['v', 'd', 'w', 'e'];
    /** The subsystem of the messages that name none. */
    public const APP = 'app';
    /** How many it keeps, the oldest going first. */
    private const KEEP = 100000;

    /** As setLevel() and console() set them, else null: as the app's kind says (defaultLevel(), App::isDev). */
    private static ?int $level = null;
    private static ?bool $console = null;
    private static bool $toFile = false;
    /** @var list<array{time: string, level: int, subsystem: string, message: string}> */
    private static array $entries = [];
    private static int $count = 0;
    /** @var array<string, true> the subsystems, by name: "app" first, the others as they came, sorted */
    private static array $subsystems = [self::APP => true];
    /** @var resource|null */
    private static $file = null;
    private static ?string $dir = null;

    public static function verbose(string $message, string $subsystem = self::APP): void { self::log(self::VERBOSE, $message, $subsystem); }

    public static function debug(string $message, string $subsystem = self::APP): void { self::log(self::DEBUG, $message, $subsystem); }

    public static function warn(string $message, string $subsystem = self::APP): void { self::log(self::WARNING, $message, $subsystem); }

    public static function error(string $message, string $subsystem = self::APP): void { self::log(self::ERROR, $message, $subsystem); }

    /** Logs $message at $level (VERBOSE to ERROR) of $subsystem, unless it is under the level set. */
    public static function log(int $level, string $message, string $subsystem = self::APP): void
    {
        if ($level < self::VERBOSE || $level > self::ERROR) {
            throw new \InvalidArgumentException("Logger: no level $level");
        }
        if ($level < self::level()) {
            return;
        }
        if (!isset(self::$subsystems[$subsystem])) {
            self::$subsystems[$subsystem] = true;
            $rest = array_slice(self::$subsystems, 1, null, true);
            ksort($rest, SORT_STRING);
            self::$subsystems = [self::APP => true] + $rest;
        }
        $t = microtime(true);
        $e = ['time' => date('H:i:s', (int)$t) . sprintf('.%03d', (int)(fmod($t, 1) * 1000)), 'level' => $level, 'subsystem' => $subsystem, 'message' => $message];
        self::$entries[] = $e;
        if (count(self::$entries) > self::KEEP) { array_shift(self::$entries); }
        self::$count++;
        $line = self::line($e);
        if (self::$console ??= App::isDev()) { fwrite(STDERR, $line); }
        if (self::$toFile) {
            self::$file ??= fopen($path = self::directory() . '/' . App::name() . '.log', 'a') ?: throw new RuntimeException("Logger: cannot write $path");
            fwrite(self::$file, $line);
        }
    }

    /** The level under which messages are dropped (IINA's log level): defaultLevel() until set. */
    public static function setLevel(int $level): void
    {
        if ($level < self::VERBOSE || $level > self::ERROR) {
            throw new \InvalidArgumentException("Logger: no level $level");
        }
        self::$level = $level;
    }

    public static function level(): int
    {
        return self::$level ??= self::defaultLevel();
    }

    /** The level of the app's kind: DEBUG in development (App::isDev), WARNING in production. */
    public static function defaultLevel(): int
    {
        return App::isDev() ? self::DEBUG : self::WARNING;
    }

    /** Whether the messages are written to the session's file too (IINA's "Enable logging to file"): off by default. */
    public static function logToFile(bool $on): void
    {
        self::$toFile = $on;
        if (!$on && self::$file !== null) { fclose(self::$file); self::$file = null; }
    }

    /** Whether the messages are printed to the standard error too: in development (App::isDev) by default. */
    public static function console(bool $on): void
    {
        self::$console = $on;
    }

    /** The folder of this session's log file (IINA's logDirectory), in the app's logs, made the first time. */
    public static function directory(): string
    {
        if (self::$dir === null) {
            $dir = App::path(PathName::Logs) . '/' . date('Y-m-d-H-i-s') . '_' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
            if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
                throw new RuntimeException("Logger: cannot make the log folder $dir");
            }
            self::$dir = $dir;
        }
        return self::$dir;
    }

    /** @internal UI\LogViewer: an entry as the file has it, "12:34:56.789 [app][d] the message\n". @param array{time: string, level: int, subsystem: string, message: string} $e */
    public static function line(array $e): string
    {
        return "{$e['time']} [{$e['subsystem']}][" . self::SHORT[$e['level']] . "] {$e['message']}\n";
    }

    /** @internal UI\LogViewer: those kept. @return list<array{time: string, level: int, subsystem: string, message: string}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** @internal how many were logged in all. */
    public static function count(): int
    {
        return self::$count;
    }

    /** @internal UI\LogViewer: the subsystems that logged, "app" first, the others sorted. @return list<string> */
    public static function subsystems(): array
    {
        return array_map('strval', array_keys(self::$subsystems));
    }
}
