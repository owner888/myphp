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
    /** @var array<string, array{refs: int, watcher: FileWatcher}> by path */
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
        self::$files[$path] = ['refs' => 1, 'watcher' => $w];
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
        // The file that went away, by what it was when the watch last saw it (mygo's 924b727), as a look now may come
        // after the next rename, or else as the document last read or wrote it: the watch looks once changes settle, so a file that
        // replaced the one it saw, which the document read (when the watch started, or its window got the keyboard),
        // can be renamed before the watch ever saw it (mygo's 8f6864d).
        $docs = Registry::at($path);
        if (end($events)->op === FileOp::FileRemove) {
            $moved = self::findRenamed(dirname($f['watcher']->root()), [$f['watcher']->gone(), ...array_map(fn($d) => $d->fileIdentity(), $docs)]);
        }
        foreach ($docs as $d) {
            $moved !== '' ? $d->moved($moved) : $d->checkDisk();
        }
    }

    /**
     * The device, inode and type of the file at $path, which a rename keeps, or null: the type too, as a file system
     * such as ext4 gives a freed inode to the next file created (mygo's 0915563).
     * @return ?array{0: int, 1: int, 2: int}
     */
    private static function identity(string $path): ?array
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        return $st === false ? null : [$st['dev'], $st['ino'], $st['mode'] & 0o170000];
    }

    /**
     * The path of the file in $dir that one of $ids identifies, the first of them that one does, or ''.
     * @param list<?array{0: int, 1: int, 2: int}> $ids
     */
    private static function findRenamed(string $dir, array $ids): string
    {
        if (array_filter($ids) === []) {
            return '';
        }
        [$found, $at] = ['', count($ids)];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $id = self::identity("$dir/$name");
            foreach (array_slice($ids, 0, $at) as $i => $want) {
                if ($want !== null && $want === $id) {
                    [$found, $at] = ["$dir/$name", $i];
                    break;
                }
            }
        }
        return $found;
    }

    /** The paths watched, for tests. @return list<string> */
    public static function paths(): array
    {
        return array_keys(self::$files);
    }
}
