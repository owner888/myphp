<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * macOS's backend (mygo's internal/darwin vnodeWatch): kqueue, through
 * libc, with a descriptor opened for events only (O_EVTONLY) per target,
 * which doesn't keep a volume from being unmounted. A directory reports
 * that its entries changed but not which, so the scan lists it again.
 * Polled without blocking while the app runs.
 *
 * @internal
 */
final class Kqueue implements Backend
{
    private const EVFILT_VNODE = -4, EV_ADD = 0x1, EV_CLEAR = 0x20, EV_RECEIPT = 0x40, EV_ERROR = 0x4000;
    private const NOTE_DELETE = 0x1, NOTE_WRITE = 0x2, NOTE_EXTEND = 0x4, NOTE_ATTRIB = 0x8, NOTE_LINK = 0x10, NOTE_RENAME = 0x20, NOTE_REVOKE = 0x40;
    private const O_EVTONLY = 0x8000, O_NOFOLLOW = 0x100, O_CLOEXEC = 0x1000000, EINTR = 4;
    private const RLIMIT_NOFILE = 8, OPEN_MAX = 10240;

    private static ?FFI $c = null;
    private static bool $raised = false;
    private int $queue;
    /** @var array<int, int> the descriptor of each target */
    private array $fds = [];
    /** @var array<int, int> the target of each descriptor */
    private array $ids = [];

    public function __construct()
    {
        self::raiseLimit();
        $this->queue = self::c()->kqueue();
        if ($this->queue < 0) {
            throw Fs::error('kqueue', '');
        }
    }

    /**
     * Raises the soft limit of open files to the hard one, at most
     * OPEN_MAX, as Go does at startup: kqueue takes a descriptor per file
     * watched, and macOS gives a process 256 by default.
     */
    private static function raiseLimit(): void
    {
        if (self::$raised) {
            return;
        }
        self::$raised = true;
        $c = self::c();
        $lim = $c->new('struct rlimit');
        if ($c->getrlimit(self::RLIMIT_NOFILE, FFI::addr($lim)) === 0 && $lim->rlim_cur < min($lim->rlim_max, self::OPEN_MAX)) {
            $lim->rlim_cur = min($lim->rlim_max, self::OPEN_MAX);
            $c->setrlimit(self::RLIMIT_NOFILE, FFI::addr($lim));
        }
    }

    public function add(Target $t): void
    {
        if ($t->subtree) {
            throw new Unsupported;
        }
        if (!$t->directory && !Fs::isRegular($t->info)) {
            return;
        }
        $c = self::c();
        $fd = $c->open($t->path, self::O_EVTONLY | self::O_CLOEXEC | self::O_NOFOLLOW);
        if ($fd < 0) {
            throw Fs::error('open', $t->path);
        }
        $st = $c->new('struct mystat');
        if ($c->fstat($fd, FFI::addr($st)) !== 0) {
            $e = Fs::error('fstat', $t->path);
            $c->close($fd);
            throw $e;
        }
        if ($st->st_dev !== $t->info[0] || $st->st_ino !== $t->info[1]) {
            $c->close($fd);
            throw new RuntimeException('watch target identity changed');
        }
        $change = $c->new('struct kevent');
        $change->ident = $fd;
        $change->filter = self::EVFILT_VNODE;
        $change->flags = self::EV_ADD | self::EV_CLEAR | self::EV_RECEIPT;
        $change->fflags = self::NOTE_WRITE | self::NOTE_EXTEND | self::NOTE_ATTRIB | self::NOTE_LINK | self::NOTE_RENAME | self::NOTE_DELETE | self::NOTE_REVOKE;
        $receipt = $c->new('struct kevent');
        do {
            $n = $c->kevent($this->queue, FFI::addr($change), 1, FFI::addr($receipt), 1, null);
        } while ($n < 0 && Fs::errno() === self::EINTR);
        $errno = 0;
        if ($n < 0) {
            $errno = Fs::errno();
        } elseif (($receipt->flags & self::EV_ERROR) !== 0 && $receipt->data !== 0) {
            $errno = $receipt->data;
        }
        if ($errno !== 0) {
            $c->close($fd);
            throw new FsError("kevent {$t->path}: " . Fs::strerror($errno), $errno);
        }
        $this->fds[$t->id] = $fd;
        $this->ids[$fd] = $t->id;
    }

    public function remove(int $id): void
    {
        $fd = $this->fds[$id] ?? null;
        if ($fd === null) {
            return;
        }
        unset($this->fds[$id], $this->ids[$fd]);
        self::c()->close($fd); // which removes its events
    }

    public function poll(float $timeout): array
    {
        $c = self::c();
        $events = $c->new('struct kevent[128]');
        $ts = null;
        if (!is_infinite($timeout)) {
            $ts = $c->new('struct timespec');
            $ts->tv_sec = (int)$timeout;
            $ts->tv_nsec = (int)(($timeout - (int)$timeout) * 1e9);
        }
        do {
            $n = $c->kevent($this->queue, null, 0, $events, 128, $ts === null ? null : FFI::addr($ts));
        } while ($n < 0 && Fs::errno() === self::EINTR);
        if ($n < 0) {
            throw new RuntimeException('kevent: ' . Fs::strerror(Fs::errno()));
        }
        $notices = [];
        for ($i = 0; $i < $n; $i++) {
            $notice = $this->notice($events[$i]);
            if ($notice !== null) {
                $notices[] = $notice;
            }
        }
        return $notices;
    }

    /** What one vnode record says, null for a descriptor no longer watched. */
    private function notice(CData $e): ?Notice
    {
        $id = $this->ids[$e->ident] ?? null;
        if ($id === null) {
            return null;
        }
        if (($e->flags & self::EV_ERROR) !== 0 && $e->data !== 0 || ($e->fflags & self::NOTE_REVOKE) !== 0) {
            return new Notice($id, Notice::LOST); // the volume went away, or kqueue gave up on the descriptor
        }
        if (($e->fflags & (self::NOTE_WRITE | self::NOTE_EXTEND)) !== 0) {
            return new Notice($id, Notice::WRITE);
        }
        // Other changes (NOTE_ATTRIB, NOTE_LINK, NOTE_RENAME, NOTE_DELETE) are dirty: the scan looks, and metadata alone is not a write.
        return new Notice($id);
    }

    public function close(): void
    {
        $c = self::c();
        foreach ($this->fds as $fd) {
            $c->close($fd);
        }
        $this->fds = $this->ids = [];
        if ($this->queue >= 0) {
            $c->close($this->queue);
            $this->queue = -1;
        }
    }

    private static function c(): FFI
    {
        // struct stat of arm64's 64-bit inodes: st_dev, st_mode, st_nlink, st_ino, then the rest.
        return self::$c ??= FFI::cdef('
            struct kevent { uintptr_t ident; int16_t filter; uint16_t flags; uint32_t fflags; intptr_t data; void *udata; };
            struct timespec { long tv_sec; long tv_nsec; };
            struct rlimit { uint64_t rlim_cur; uint64_t rlim_max; };
            struct mystat { int32_t st_dev; uint16_t st_mode; uint16_t st_nlink; uint64_t st_ino; char rest[128]; };
            int kqueue(void);
            int kevent(int, const struct kevent *, int, struct kevent *, int, const struct timespec *);
            int open(const char *, int);
            int fstat(int, struct mystat *);
            int close(int);
            int getrlimit(int, struct rlimit *);
            int setrlimit(int, const struct rlimit *);
        ');
    }
}
