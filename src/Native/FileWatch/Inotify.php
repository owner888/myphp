<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use FFI;
use RuntimeException;

/**
 * Linux's backend (mygo's internal/linux watch), where the tests run: an
 * inotify instance per watch, through libc, read without blocking. Its
 * targets are directories, and files are watched through their directory,
 * which names them.
 *
 * @internal
 */
final class Inotify implements Backend
{
    private const IN_MODIFY = 0x2, IN_ATTRIB = 0x4, IN_CLOSE_WRITE = 0x8, IN_MOVED_FROM = 0x40, IN_MOVED_TO = 0x80,
        IN_CREATE = 0x100, IN_DELETE = 0x200, IN_DELETE_SELF = 0x400, IN_MOVE_SELF = 0x800, IN_UNMOUNT = 0x2000,
        IN_Q_OVERFLOW = 0x4000, IN_IGNORED = 0x8000, IN_ONLYDIR = 0x1000000, IN_DONT_FOLLOW = 0x2000000;
    private const IN_NONBLOCK = 0x800, IN_CLOEXEC = 0x80000, EAGAIN = 11, EINTR = 4, POLLIN = 1;

    private static ?FFI $c = null;
    private int $fd;
    /** @var array<int, int> the descriptor of each target */
    private array $targets = [];
    /** @var array<int, list<int>> the targets of each descriptor, which inotify gives a directory however often it is added */
    private array $users = [];
    /** @var array<int, true> descriptors whose directory was deleted, whose IN_IGNORED is expected */
    private array $retired = [];

    public function __construct()
    {
        $this->fd = self::c()->inotify_init1(self::IN_NONBLOCK | self::IN_CLOEXEC);
        if ($this->fd < 0) {
            throw Fs::error('inotify_init1', '');
        }
    }

    public function add(Target $t): void
    {
        if ($t->subtree) {
            throw new Unsupported;
        }
        if (!$t->directory) {
            return;
        }
        $mask = self::IN_CREATE | self::IN_MODIFY | self::IN_CLOSE_WRITE | self::IN_ATTRIB | self::IN_DELETE | self::IN_MOVED_FROM | self::IN_MOVED_TO
            | self::IN_DELETE_SELF | self::IN_MOVE_SELF | self::IN_UNMOUNT | self::IN_ONLYDIR | self::IN_DONT_FOLLOW;
        $wd = self::c()->inotify_add_watch($this->fd, $t->path, $mask);
        if ($wd < 0) {
            throw Fs::error('inotify_add_watch', $t->path);
        }
        try {
            $info = Fs::lstat($t->path);
        } catch (FsError $e) {
            $this->release($wd);
            throw $e;
        }
        if (!Fs::same($info, $t->info)) {
            $this->release($wd);
            throw new RuntimeException('watch target identity changed');
        }
        unset($this->retired[$wd]);
        $this->users[$wd][] = $t->id;
        $this->targets[$t->id] = $wd;
    }

    /** Removes $wd from inotify unless a target uses it. */
    private function release(int $wd): void
    {
        if (($this->users[$wd] ?? []) === []) {
            unset($this->users[$wd]);
            self::c()->inotify_rm_watch($this->fd, $wd);
        }
    }

    public function remove(int $id): void
    {
        $wd = $this->targets[$id] ?? null;
        if ($wd === null) {
            return;
        }
        unset($this->targets[$id]);
        $this->users[$wd] = array_values(array_diff($this->users[$wd], [$id]));
        $this->release($wd);
    }

    public function poll(float $timeout): array
    {
        $c = self::c();
        $buf = $c->new('char[65536]');
        $notices = [];
        while (true) {
            $n = $c->read($this->fd, $buf, 65536);
            if ($n < 0) {
                $errno = Fs::errno();
                if ($errno === self::EINTR) {
                    continue;
                }
                if ($errno !== self::EAGAIN) {
                    throw new RuntimeException('inotify: ' . Fs::strerror($errno));
                }
                if ($notices !== [] || $timeout <= 0) {
                    return $notices;
                }
                // Nothing yet: wait for something, once.
                $pfd = $c->new('struct pollfd');
                $pfd->fd = $this->fd;
                $pfd->events = self::POLLIN;
                $c->poll(FFI::addr($pfd), 1, is_infinite($timeout) ? -1 : (int)ceil($timeout * 1000));
                $timeout = 0;
                continue;
            }
            if ($n === 0) {
                throw new RuntimeException('inotify stream ended');
            }
            foreach (self::parse(FFI::string($buf, $n)) as [$wd, $mask, $name]) {
                $this->dispatch($wd, $mask, $name, $notices);
            }
        }
    }

    /** The records in what read() gave: [wd, mask, name]. @return list<array{0: int, 1: int, 2: string}> */
    private static function parse(string $data): array
    {
        $records = [];
        for ($at = 0, $end = strlen($data); $at < $end;) {
            if ($end - $at < 16) {
                throw new RuntimeException('truncated inotify record');
            }
            ['wd' => $wd, 'mask' => $mask, 'len' => $len] = unpack('lwd/Vmask/Vcookie/Vlen', $data, $at);
            if ($len > $end - $at - 16) {
                throw new RuntimeException('oversized inotify name');
            }
            $name = '';
            if ($len > 0) {
                $raw = substr($data, $at + 16, $len);
                $nul = strpos($raw, "\0");
                if ($nul === false) {
                    throw new RuntimeException('unterminated inotify name');
                }
                $name = substr($raw, 0, $nul);
            }
            $records[] = [$wd, $mask, $name];
            $at += 16 + $len;
        }
        return $records;
    }

    /** Adds what a record says to $notices. @param list<Notice> $notices */
    private function dispatch(int $wd, int $mask, string $name, array &$notices): void
    {
        if ($mask & self::IN_Q_OVERFLOW) {
            $notices[] = new Notice(err: new Overflow);
            return;
        }
        $users = $this->users[$wd] ?? [];
        if ($mask & self::IN_IGNORED) {
            if ($users !== [] && !isset($this->retired[$wd])) {
                // The directory's filesystem was unmounted, or inotify dropped the watch for another reason.
                foreach ($users as $id) {
                    $notices[] = new Notice($id, Notice::LOST);
                    unset($this->targets[$id]);
                }
            }
            unset($this->users[$wd], $this->retired[$wd]);
            return;
        }
        if ($users === [] || isset($this->retired[$wd])) {
            return;
        }
        $kind = match (true) {
            (bool)($mask & self::IN_UNMOUNT) => null, // IN_IGNORED follows
            (bool)($mask & self::IN_MOVED_FROM) => Notice::MOVE_FROM,
            (bool)($mask & self::IN_MOVED_TO) => Notice::MOVE_TO,
            (bool)($mask & self::IN_CREATE) => Notice::CREATE,
            (bool)($mask & (self::IN_DELETE | self::IN_DELETE_SELF)) => Notice::REMOVE,
            (bool)($mask & (self::IN_MODIFY | self::IN_CLOSE_WRITE)) => Notice::WRITE,
            default => Notice::DIRTY, // IN_ATTRIB: the scan looks, and metadata alone is not a write
        };
        if ($kind === null) {
            return;
        }
        // A directory added twice is heard of once, through its latest target.
        $notices[] = new Notice($users[count($users) - 1], $kind, $name);
        if ($mask & self::IN_DELETE_SELF) {
            $this->retired[$wd] = true;
        }
    }

    public function close(): void
    {
        if ($this->fd >= 0) {
            self::c()->close($this->fd);
            $this->fd = -1;
        }
    }

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            struct pollfd { int fd; short events; short revents; };
            int inotify_init1(int);
            int inotify_add_watch(int, const char *, uint32_t);
            int inotify_rm_watch(int, int);
            long read(int, void *, unsigned long);
            int poll(struct pollfd *, unsigned long, int);
            int close(int);
        ');
    }
}
