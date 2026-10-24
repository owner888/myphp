<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

use FFI;
use InvalidArgumentException;
use MyPHP\{App, Plugin};
use MyPHP\Plugins\NativeLibrary;

/**
 * SQLite (mygo's plugins/sqlite): SQLite's C amalgamation and mygo's small
 * C shim, the library mygo's release carries, loaded through FFI.
 *
 *     $db = Sqlite::open(App::path(PathName::UserData) . '/app.db');
 *     $db->execute('CREATE TABLE IF NOT EXISTS notes (title TEXT)');
 *     $db->transaction([new Statement('INSERT INTO notes VALUES (?)', ['first'])]);
 *     $rows = $db->query('SELECT title FROM notes'); // $rows->columns, $rows->values
 *     $db->close();
 *
 * Foreign keys are on; FTS5, R*Tree and JSON are in; extensions cannot be
 * loaded. Pages use it through the plugin (plugin(), App::use) and its
 * TypeScript client.
 */
final class Sqlite
{
    private static bool $loaded = false;

    /**
     * The page plugin with default options (mygo's sqlite.Plugin), for
     * App::use() when pages use the plugin's TypeScript client
     * (client/index.ts): it binds "plugin:sqlite". It loads the library
     * only when a page opens its first database. PHP and native UI code
     * call open() directly and need no plugin.
     */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /** The page plugin with options (mygo's sqlite.New). Connections belong to their page, close when it goes away, and close as the app quits. */
    public static function new(Options $opts): Plugin
    {
        $s = new Service($opts);
        // Its shutdown is private, out of the pages' reach: the app's quit calls it.
        $shutdown = \Closure::bind(fn() => $this->shutdown(), $s, Service::class);
        return new Plugin('sqlite', $s, function () use ($shutdown): void { App::onQuit($shutdown); });
    }

    /**
     * Opens $path, or ":memory:" (mygo's Open). It creates a missing file
     * unless $opts->readOnly; the parent directory must exist. Close the
     * connection when done. It throws an Error SQLite gives.
     */
    public static function open(string $path, OpenOptions $opts = new OpenOptions): DB
    {
        if ($path === '' || str_contains($path, "\0") || !mb_check_encoding($path, 'UTF-8')) {
            throw new InvalidArgumentException('sqlite: database path must be nonempty UTF-8 without NUL');
        }
        self::load();
        $f = Lib::ffi();
        $flags = $opts->readOnly ? 0x00000001 | 0x00010000 : 0x00000002 | 0x00000004 | 0x00010000; // READONLY or READWRITE | CREATE, FULLMUTEX
        $h = $f->new('sqlite3*');
        $rc = $f->sqlite3_open_v2($path, FFI::addr($h), $flags, null);
        if ($rc !== 0) {
            $e = DB::error($h, $rc);
            if (!FFI::isNull($h)) { $f->sqlite3_close_v2($h); }
            throw $e;
        }
        $f->sqlite3_extended_result_codes($h, 1);
        $timeout = $opts->busyTimeout == 0 ? 5.0 : $opts->busyTimeout;
        $ms = (int)($timeout * 1000);
        if ($timeout > 0 && $ms === 0) { $ms = 1; }
        return new DB($h, max(0, min($ms, 0x7fffffff)));
    }

    /** Loads the plugin's SQLite library once (mygo's Load). open() calls it; call it earlier to report a missing library. */
    public static function load(): void
    {
        if (!self::$loaded) {
            Lib::load(self::libraryPath());
            self::$loaded = true;
        }
    }

    /**
     * The library the plugin loads (mygo's LibraryPath): the one
     * $MYGO_SQLITE_LIBRARY names, the app's resources, the executable's
     * directory, then mygo's SHA-256-checked cache, where programs that
     * are not packaged apps download it when it is missing.
     */
    public static function libraryPath(): string
    {
        $path = NativeLibrary::find(__DIR__ . '/mygo-plugin.json', 'mygo-sqlite3', 'MYGO_SQLITE_LIBRARY', 'sqlite');
        return str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
    }
}
