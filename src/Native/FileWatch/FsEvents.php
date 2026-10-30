<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * macOS's backend (mygo's internal/darwin fseWatch): FSEvents, one stream
 * per directory target covering the whole tree beneath it, so a watch costs
 * no descriptor per file and a large folder is fine. A file target is
 * covered by the stream of the directory holding it, which the scan
 * enrolls for a file watch, and a directory below a stream already
 * watching its tree needs none of its own. The stream says what changed
 * (kFSEventStreamCreateFlagFileEvents), and the first change after a quiet
 * period at once (NoDefer); the notices stay hints, the scan comparing
 * what it sees, and changes dropped (MustScanSubDirs, a user or kernel
 * drop) have it look at everything again.
 *
 * The streams are scheduled on the run loop in a mode of their own, which
 * poll() runs: their callbacks come then, on the main thread, where PHP
 * runs, and never in the middle of the app's own work.
 *
 * @internal
 */
final class FsEvents implements Backend
{
    /** FSEventStreamCreateFlags: NoDefer, WatchRoot, FileEvents. */
    private const CREATE_NO_DEFER = 0x2, CREATE_WATCH_ROOT = 0x4, CREATE_FILE_EVENTS = 0x10;
    /** FSEventStreamEventFlags. */
    public const MUST_SCAN_SUB_DIRS = 0x1, USER_DROPPED = 0x2, KERNEL_DROPPED = 0x4, EVENT_IDS_WRAPPED = 0x8, HISTORY_DONE = 0x10, ROOT_CHANGED = 0x20,
        MOUNT = 0x40, UNMOUNT = 0x80, ITEM_CREATED = 0x100, ITEM_REMOVED = 0x200, ITEM_RENAMED = 0x800, ITEM_MODIFIED = 0x1000, ITEM_IS_FILE = 0x10000;
    /** How long FSEvents may gather events, in seconds: well under the debounce, which does the coalescing an app sees. */
    private const LATENCY = 0.01;
    /** How many notices a watch holds before it has the scan look at everything again. */
    private const QUEUE = 4096;
    private const UTF8 = 0x08000100, SINCE_NOW = 0xFFFFFFFFFFFFFFFF;

    private static ?FFI $c = null;
    /** @var array<int, array{stream: CData, key: CData, path: string, subtree: bool}> the streams by target, with the cell holding the target's id that the stream's context points to */
    private array $streams = [];
    /** @var list<Notice> what the callback took */
    private array $queue = [];
    private bool $overflow = false;
    private CData $mode;
    /** The callback FSEvents calls, kept alive while the streams are. */
    private \Closure $callback;

    public function __construct()
    {
        $c = self::c();
        $this->mode = $c->CFStringCreateWithCString(null, 'com.myphp.watch', self::UTF8);
        $this->callback = function ($stream, $info, $n, $paths, $flags, $ids) use ($c): void {
            $id = $c->cast('intptr_t*', $info)[0]; // the cell add() made
            $s = $this->streams[$id] ?? null;
            if ($s === null) {
                return;
            }
            $p = $c->cast('char**', $paths);
            for ($i = 0; $i < $n; $i++) {
                if ($p[$i] === null) { continue; }
                $notice = self::notice($id, $s['path'], $s['subtree'], FFI::string($p[$i]), $flags[$i]);
                if ($notice === null) { continue; }
                if (count($this->queue) < self::QUEUE) { $this->queue[] = $notice; } else { $this->overflow = true; }
            }
        };
    }

    /**
     * The notice of one event at $path with $flags, of the stream of target
     * $id at $streamPath, or null for none (mygo's fseStream.event): pure,
     * so the mapping is tested without the framework.
     */
    public static function notice(int $id, string $streamPath, bool $subtree, string $path, int $flags): ?Notice
    {
        $prefix = rtrim($streamPath, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            $rel = substr($path, strlen($prefix));
        } elseif ($path === $streamPath || $path === rtrim($streamPath, '/')) {
            $rel = '';
        } else {
            return null;
        }
        if ($flags & (self::MUST_SCAN_SUB_DIRS | self::USER_DROPPED | self::KERNEL_DROPPED | self::EVENT_IDS_WRAPPED)) {
            return new Notice($id, err: new Overflow); // changes dropped: the scan lists everything again
        }
        if (!$subtree && str_contains($rel, '/')) {
            return null; // FSEvents is recursive, but a target without a subtree is about its directory and the entries in it, which a change deeper down doesn't change
        }
        if ($flags & self::HISTORY_DONE) {
            return null;
        }
        if ($flags & self::ROOT_CHANGED) {
            return new Notice($id, name: $rel); // moved, renamed or replaced: the scan looks, and ends the watch when it is gone
        }
        if ($flags & self::UNMOUNT) {
            return new Notice($id, Notice::LOST, $rel);
        }
        // Created, removed and renamed are dirty, the scan looking: a write on APFS sets ItemCreated too, a metadata change can carry ItemModified, so the structural flags win, and only a plain content write is a write.
        if (($flags & (self::ITEM_REMOVED | self::ITEM_RENAMED | self::ITEM_CREATED)) === 0 && ($flags & self::ITEM_MODIFIED) && ($flags & self::ITEM_IS_FILE)) {
            return new Notice($id, Notice::WRITE, $rel);
        }
        return new Notice($id, name: $rel);
    }

    public function add(Target $t): void
    {
        if (!$t->directory) {
            throw new Unsupported; // covered by the stream of the directory holding it, enrolled before it was listed; FSEvents watches trees, not files
        }
        $path = self::canonical($t->path); // FSEvents reports paths as they are on disk, which a path on a case-insensitive volume may not be
        foreach ($this->streams as $s) {
            if ($path === $s['path'] || str_starts_with($path, rtrim($s['path'], '/') . '/')) {
                return; // a stream above already watches its tree
            }
        }
        if (!Fs::same(Fs::lstat($path), $t->info)) {
            throw new RuntimeException('watch target identity changed');
        }
        $c = self::c();
        $cfPath = $c->CFStringCreateWithCString(null, $path, self::UTF8) ?? throw new RuntimeException('cannot make the watch path a CFString');
        $values = $c->new('void*[1]');
        $values[0] = $cfPath;
        $cfPaths = $c->CFArrayCreate(null, $values, 1, null); // FSEvents copies what it needs of it
        $ctx = $c->new('FSEventStreamContext');
        $key = $c->new('intptr_t', false); // freed once the stream is released
        $key->cdata = $t->id;
        $ctx->info = FFI::addr($key); // handed back to the callback, which finds the stream by the id it points to
        $stream = $c->FSEventStreamCreate(null, $this->callback, FFI::addr($ctx), $cfPaths, self::SINCE_NOW, self::LATENCY,
            self::CREATE_FILE_EVENTS | self::CREATE_WATCH_ROOT | self::CREATE_NO_DEFER); // since now: the scan's snapshot is taken already
        $c->CFRelease($cfPaths);
        $c->CFRelease($cfPath);
        if ($stream === null) {
            FFI::free(FFI::addr($key));
            throw new RuntimeException('cannot create an FSEventStream');
        }
        $c->FSEventStreamScheduleWithRunLoop($stream, $c->CFRunLoopGetCurrent(), $this->mode);
        if (!$c->FSEventStreamStart($stream)) {
            self::stop($stream, $key);
            throw new RuntimeException('cannot start an FSEventStream');
        }
        $this->streams[$t->id] = ['stream' => $stream, 'key' => $key, 'path' => $path, 'subtree' => $t->subtree];
    }

    public function remove(int $id): void
    {
        if (($s = $this->streams[$id] ?? null) === null) {
            return;
        }
        unset($this->streams[$id]);
        self::stop($s['stream'], $s['key']);
    }

    public function poll(float $timeout): array
    {
        $c = self::c();
        if ($this->streams === []) {
            if ($timeout > 0) { usleep((int)(min($timeout, 0.05) * 1e6)); } // nothing to wait on, as the run loop would return at once
        } else {
            $c->CFRunLoopRunInMode($this->mode, 0.0, 0); // the events that came
            if ($this->queue === [] && !$this->overflow && $timeout > 0) {
                $c->CFRunLoopRunInMode($this->mode, is_infinite($timeout) ? 1e10 : $timeout, 1);
            }
        }
        if ($this->overflow) { // what was held is covered by the scan looking at everything
            [$this->overflow, $this->queue] = [false, []];
            return [new Notice(err: new Overflow)];
        }
        [$q, $this->queue] = [$this->queue, []];
        return $q;
    }

    public function close(): void
    {
        foreach ($this->streams as $s) {
            self::stop($s['stream'], $s['key']);
        }
        $this->streams = [];
    }

    /** Stops and releases $stream, then frees the cell its context points to. */
    private static function stop(CData $stream, CData $key): void
    {
        $c = self::c();
        $c->FSEventStreamStop($stream);
        $c->FSEventStreamInvalidate($stream); // unscheduled from the run loop: its callback runs no more
        $c->FSEventStreamRelease($stream);
        FFI::free(FFI::addr($key));
    }

    /** $path as it is on disk, with libc's realpath, or as it is when that fails. */
    private static function canonical(string $path): string
    {
        $c = self::c();
        $buf = $c->new('char[1024]'); // PATH_MAX
        return $c->realpath($path, $buf) === null ? $path : FFI::string($buf);
    }

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct { long version; void *info; void *retain; void *release; void *copyDescription; } FSEventStreamContext;
            typedef void (*FSEventStreamCallback)(void *stream, void *info, size_t n, void *paths, const uint32_t *flags, const uint64_t *ids);
            void *FSEventStreamCreate(void *alloc, FSEventStreamCallback cb, FSEventStreamContext *ctx, void *paths, uint64_t since, double latency, uint32_t flags);
            void FSEventStreamScheduleWithRunLoop(void *stream, void *runLoop, void *mode);
            uint8_t FSEventStreamStart(void *stream);
            void FSEventStreamStop(void *stream);
            void FSEventStreamInvalidate(void *stream);
            void FSEventStreamRelease(void *stream);
            void *CFStringCreateWithCString(void *alloc, const char *s, uint32_t encoding);
            void *CFArrayCreate(void *alloc, void **values, long n, void *callbacks);
            void CFRelease(void *cf);
            void *CFRunLoopGetCurrent(void);
            int32_t CFRunLoopRunInMode(void *mode, double seconds, uint8_t returnAfterSourceHandled);
            char *realpath(const char *path, char *resolved);
        ', '/System/Library/Frameworks/CoreServices.framework/CoreServices');
    }
}
