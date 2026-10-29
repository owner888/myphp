<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use MyPHP\{App, FileOp, FileWatcher, FileWatchOptions, Logger};

/**
 * Documents notice what other apps do to their files (mygo's
 * document_watch.go): one App::watchFiles per open document's file, which
 * a timer looks at, and a look when a document's window gets the
 * keyboard, since watches miss changes (VS Code and Notepad++ look then
 * too). An unedited document reads its file again, an edited one is in
 * conflict and asks at its next save, a file renamed in its directory is
 * followed, and a deleted one is written again by the next save.
 *
 * @internal
 */
final class Watches
{
    /** How often the watches are looked at, in seconds. */
    public const POLL = 0.25;
    /** @var array<string, array{refs: int, watcher: FileWatcher, known: ?array{0: int, 1: int}}> by path */
    private static array $files = [];
    private static bool $polling = false;

    /** A document's file moved from $old to $path: '' for none. */
    public static function move(string $old, string $path): void
    {
        if ($old !== '' && isset(self::$files[$old]) && --self::$files[$old]['refs'] === 0) {
            self::$files[$old]['watcher']->close();
            unset(self::$files[$old]);
        }
        if ($path === '') {
            return;
        }
        if (isset(self::$files[$path])) {
            self::$files[$path]['refs']++;
            return;
        }
        try {
            $w = App::watchFiles($path, new FileWatchOptions(debounce: 0.1));
        } catch (\Throwable) {
            return; // the file is gone: the looks when a window gets the keyboard remain
        }
        self::$files[$path] = ['refs' => 1, 'watcher' => $w, 'known' => self::identity($w->root())];
        // What changed before the watch started, as after a rename.
        Host::later(function () use ($path): void {
            foreach (Registry::at($path) as $d) {
                $d->checkDisk();
            }
        });
        if (!self::$polling) {
            self::$polling = true;
            Host::after(self::POLL, self::poll(...));
        }
    }

    /** Looks at every watch, and again a moment later while there are any. */
    public static function poll(): void
    {
        foreach (self::$files as $path => $f) {
            try {
                $events = $f['watcher']->next(0);
            } catch (\Throwable $e) {
                Logger::warn("the watch of $path stopped: {$e->getMessage()}", 'documents');
                $events = null;
            }
            if ($events === null) { // the watch ended: its directory went away
                unset(self::$files[$path]);
                continue;
            }
            if ($events !== []) {
                self::changed($path, $events);
            }
        }
        self::$polling = self::$files !== [];
        if (self::$polling) {
            Host::after(self::POLL, self::poll(...));
        }
    }

    /** @param list<\MyPHP\FileEvent> $events */
    private static function changed(string $path, array $events): void
    {
        $f = self::$files[$path];
        $moved = '';
        if (end($events)->op === FileOp::FileRemove && $f['known'] !== null) {
            $moved = self::findRenamed(dirname($f['watcher']->root()), $f['known']);
        }
        foreach (Registry::at($path) as $d) {
            $moved !== '' ? $d->moved($moved) : $d->checkDisk();
        }
        if ($moved === '' && isset(self::$files[$path])) {
            self::$files[$path]['known'] = self::identity($f['watcher']->root()) ?? $f['known'];
        }
    }

    /** The device and inode of the file at $path, which a rename keeps, or null. @return ?array{0: int, 1: int} */
    private static function identity(string $path): ?array
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        return $st === false ? null : [$st['dev'], $st['ino']];
    }

    /** The path of the file in $dir that is the file of $identity, or ''. @param array{0: int, 1: int} $identity */
    private static function findRenamed(string $dir, array $identity): string
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && self::identity("$dir/$name") === $identity) {
                return "$dir/$name";
            }
        }
        return '';
    }

    /** The paths watched, for tests. @return list<string> */
    public static function paths(): array
    {
        return array_keys(self::$files);
    }
}
