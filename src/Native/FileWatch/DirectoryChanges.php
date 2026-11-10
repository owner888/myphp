<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use FFI;
use FFI\CData;
use MyPHP\Native\Mem;
use MyPHP\Platform\Windows\Win32;
use RuntimeException;

/**
 * Windows's backend (mygo's windows watch.go): ReadDirectoryChangesW on
 * each directory, their I/O completing on one completion port, which
 * poll() waits on. A subtree target watches a whole tree with one handle:
 * Windows doesn't let a directory be renamed while a handle is open on
 * something beneath it, so a handle per directory would keep the tree's
 * directories from being renamed. Files are watched through their
 * directory, which names them.
 *
 * @internal
 */
final class DirectoryChanges implements Backend
{
    private const FILE_LIST_DIRECTORY = 0x1, FILE_READ_ATTRIBUTES = 0x80, SHARE_ALL = 0x7, OPEN_EXISTING = 3,
        FILE_FLAG_BACKUP_SEMANTICS = 0x02000000, FILE_FLAG_OVERLAPPED = 0x40000000, FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000,
        FILE_ATTRIBUTE_DIRECTORY = 0x10, FILE_ATTRIBUTE_REPARSE_POINT = 0x400;
    /** FILE_NOTIFY_CHANGE_FILE_NAME | DIR_NAME | SIZE | LAST_WRITE: not ATTRIBUTES, which antivirus and indexers change, and which would be taken for writes. */
    private const FILTER = 0x1b;
    /** 64 KiB, the most a network share takes. */
    private const BUFFER = 65536;
    private const INFINITE = 0xFFFFFFFF, WAIT_TIMEOUT = 258, ERROR_NOTIFY_ENUM_DIR = 1022;

    private static ?FFI $k = null;
    /** @var list<array{buffer: CData, overlap: CData}> the buffers of I/O that never completed after close(), which Windows may still write to */
    private static array $leaked = [];

    private CData $port;
    private int $next = 0;
    /** @var array<int, array{target: int, handle: CData, subtree: bool, removed: bool, buffer: CData, overlap: CData}> by completion key, until their I/O completes */
    private array $sessions = [];
    /** @var array<int, int> the key of each target */
    private array $targets = [];

    public function __construct()
    {
        $port = self::k()->CreateIoCompletionPort(self::invalid(), null, 0, 1);
        if ($port === null || FFI::isNull($port)) {
            throw new RuntimeException('CreateIoCompletionPort: error ' . self::k()->GetLastError());
        }
        $this->port = $port;
    }

    public function add(Target $t): void
    {
        if (!$t->directory) {
            throw new Unsupported; // files are watched through their directory
        }
        $k = self::k();
        $h = $k->CreateFileW(Win32::wide($t->path), self::FILE_LIST_DIRECTORY, self::SHARE_ALL, null, self::OPEN_EXISTING,
            self::FILE_FLAG_BACKUP_SEMANTICS | self::FILE_FLAG_OVERLAPPED | self::FILE_FLAG_OPEN_REPARSE_POINT, null);
        if (self::bad($h)) {
            throw Fs::windowsError('watch', $t->path, $k->GetLastError());
        }
        try {
            $this->sameDirectory($h, $t);
        } catch (\Throwable $e) {
            $k->CloseHandle($h);
            throw $e;
        }
        $key = ++$this->next;
        $port = $k->CreateIoCompletionPort($h, $this->port, $key, 0);
        if ($port === null || FFI::isNull($port)) {
            $err = $k->GetLastError();
            $k->CloseHandle($h);
            throw new RuntimeException("CreateIoCompletionPort: error $err");
        }
        $s = ['target' => $t->id, 'handle' => $h, 'subtree' => $t->subtree, 'removed' => false,
            'buffer' => $k->new('uint8_t[' . self::BUFFER . ']', false), 'overlap' => $k->new('OVERLAPPED', false)];
        if (!$this->arm($s)) {
            $err = $k->GetLastError();
            $k->CloseHandle($h);
            FFI::free($s['buffer']);
            FFI::free($s['overlap']);
            throw Fs::windowsError('watch', $t->path, $err);
        }
        $this->sessions[$key] = $s;
        $this->targets[$t->id] = $key;
    }

    /**
     * Checks that $h is the directory the scan saw and not a link to one
     * (mygo's sameDirectory): a directory to lstat, which a junction, a
     * symlink or a mount point is not, while a placeholder of cloud files
     * (OneDrive) is, the handle being that of the reparse point, which
     * fetches nothing; the same file index as the scan's lstat gave.
     */
    private function sameDirectory(CData $h, Target $t): void
    {
        $k = self::k();
        $info = $k->new('BY_HANDLE_FILE_INFORMATION');
        if (!$k->GetFileInformationByHandle($h, FFI::addr($info))) {
            throw Fs::windowsError('watch', $t->path, $k->GetLastError());
        }
        if (!($info->attributes & self::FILE_ATTRIBUTE_DIRECTORY)) {
            throw new RuntimeException("watch {$t->path}: not a directory");
        }
        $now = Fs::lstat($t->path);
        if (!Fs::isDir($now)) {
            throw new RuntimeException("watch {$t->path}: not a directory");
        }
        $index = ($info->indexHigh << 32) | $info->indexLow;
        if ($index !== $t->info[1] || !Fs::same($now, $t->info)) {
            throw new RuntimeException('watch target identity changed');
        }
    }

    /** Starts the next read of $s's changes. */
    private function arm(array $s): bool
    {
        FFI::memset($s['overlap'], 0, FFI::sizeof($s['overlap']));
        return (bool)self::k()->ReadDirectoryChangesW($s['handle'], $s['buffer'], self::BUFFER, $s['subtree'] ? 1 : 0, self::FILTER, null, FFI::addr($s['overlap']), null);
    }

    /** Closes $s's handle, which cancels its I/O; it stays in sessions until that completes. */
    private function release(int $key): void
    {
        $s = &$this->sessions[$key];
        if (!$s['removed']) {
            $s['removed'] = true;
            unset($this->targets[$s['target']]);
            self::k()->CloseHandle($s['handle']);
        }
    }

    /** Releases $key's session, which has no I/O pending. */
    private function finish(int $key): void
    {
        $this->release($key);
        FFI::free($this->sessions[$key]['buffer']);
        FFI::free($this->sessions[$key]['overlap']);
        unset($this->sessions[$key]);
    }

    public function remove(int $id): void
    {
        if (isset($this->targets[$id])) {
            $this->release($this->targets[$id]);
        }
    }

    public function poll(float $timeout): array
    {
        $notices = [];
        $wait = is_infinite($timeout) ? self::INFINITE : max(0, (int)ceil($timeout * 1000));
        while (true) {
            [$ok, $size, $key, $err] = $this->dequeue($notices === [] ? $wait : 0);
            if ($key === null) {
                if (!$ok && $err !== self::WAIT_TIMEOUT) {
                    throw new RuntimeException("GetQueuedCompletionStatus: error $err");
                }
                return $notices;
            }
            $wait = 0;
            if (!isset($this->sessions[$key])) {
                continue;
            }
            $s = $this->sessions[$key];
            if ($s['removed']) {
                $this->finish($key);
                continue;
            }
            if (!$ok && $err !== self::ERROR_NOTIFY_ENUM_DIR) {
                // ERROR_ACCESS_DENIED once the directory is deleted, for example.
                $this->finish($key);
                $notices[] = new Notice($s['target'], Notice::LOST);
                continue;
            }
            if (!$ok || $size === 0) {
                $notices[] = new Notice(err: new Overflow); // more changed than the buffer held
            } else {
                try {
                    array_push($notices, ...self::parse(FFI::string($s['buffer'], min($size, self::BUFFER)), $s['target']));
                } catch (RuntimeException) {
                    $notices[] = new Notice(err: new Overflow);
                }
            }
            if (!$this->arm($s)) {
                $this->finish($key);
                $notices[] = new Notice($s['target'], Notice::LOST);
            }
        }
    }

    /** @return array{0: bool, 1: int, 2: ?int, 3: int} whether the I/O succeeded, its size, its key (null for none dequeued), the error */
    private function dequeue(int $ms): array
    {
        $k = self::k();
        $size = $k->new('uint32_t');
        $key = $k->new('uintptr_t');
        $ov = $k->new('OVERLAPPED *');
        $ok = (bool)$k->GetQueuedCompletionStatus($this->port, FFI::addr($size), FFI::addr($key), FFI::addr($ov), $ms);
        $err = $ok ? 0 : $k->GetLastError();
        return [$ok, $size->cdata, FFI::isNull($ov) ? null : $key->cdata, $err];
    }

    /**
     * The FILE_NOTIFY_INFORMATION records in $data (mygo's
     * parseDirectoryChanges); names below a subtree's directory are paths,
     * which it separates with slashes. Throws for a record that is not one.
     * @return list<Notice>
     */
    public static function parse(string $data, int $target): array
    {
        $out = [];
        for ($at = 0; ; ) {
            if (strlen($data) - $at < 12) {
                throw new RuntimeException('truncated directory notification');
            }
            ['next' => $next, 'action' => $action, 'size' => $size] = unpack('Vnext/Vaction/Vsize', $data, $at);
            if ($size === 0 || $size % 2 !== 0 || $size > strlen($data) - $at - 12) {
                throw new RuntimeException('invalid directory notification name length');
            }
            $raw = substr($data, $at + 12, $size);
            $name = @iconv('UTF-16LE', 'UTF-8', $raw);
            if ($name === false || str_contains($name, "\0")) {
                throw new RuntimeException('invalid UTF-16 directory notification');
            }
            $kind = match ($action) {
                1 => Notice::CREATE, 2 => Notice::REMOVE, 3 => Notice::WRITE, 4 => Notice::MOVE_FROM, 5 => Notice::MOVE_TO,
                default => throw new RuntimeException('invalid directory notification action'),
            };
            $out[] = new Notice($target, $kind, str_replace('\\', '/', $name));
            if ($next === 0) {
                return $out;
            }
            if ($next % 4 !== 0 || $next < 12 + $size || $next > strlen($data) - $at - 12) {
                throw new RuntimeException('invalid directory notification offset');
            }
            $at += $next;
        }
    }

    public function close(): void
    {
        foreach (array_keys($this->sessions) as $key) {
            $this->release($key);
        }
        // Wait for the canceled I/O, which still writes to the sessions' buffers.
        $deadline = microtime(true) + 5;
        while ($this->sessions !== [] && ($left = $deadline - microtime(true)) > 0) {
            [$ok, , $key, $err] = $this->dequeue((int)ceil($left * 1000));
            if ($key === null) {
                if (!$ok && $err !== self::WAIT_TIMEOUT) {
                    break;
                }
                continue;
            }
            if (isset($this->sessions[$key])) {
                $this->finish($key);
            }
        }
        foreach ($this->sessions as $s) {
            self::$leaked[] = ['buffer' => $s['buffer'], 'overlap' => $s['overlap']];
        }
        $this->sessions = [];
        if (!FFI::isNull($this->port)) {
            self::k()->CloseHandle($this->port);
            $this->port = Mem::pointer(0);
        }
    }

    private static function invalid(): CData
    {
        return Mem::pointer(-1); // INVALID_HANDLE_VALUE
    }

    private static function bad(?CData $h): bool
    {
        return $h === null || FFI::isNull($h) || Mem::addr($h) === -1; // INVALID_HANDLE_VALUE
    }

    private static function k(): FFI
    {
        return self::$k ??= FFI::cdef(<<<'C'
            typedef struct { uintptr_t Internal, InternalHigh; uint32_t Offset, OffsetHigh; void *hEvent; } OVERLAPPED;
            typedef struct { uint32_t attributes; uint32_t created[2], accessed[2], written[2]; uint32_t volume, sizeHigh, sizeLow, links, indexHigh, indexLow; } BY_HANDLE_FILE_INFORMATION;
            void *CreateIoCompletionPort(void *file, void *port, uintptr_t key, uint32_t threads);
            void *CreateFileW(const void *name, uint32_t access, uint32_t share, void *sa, uint32_t disposition, uint32_t flags, void *tmpl);
            int32_t GetFileInformationByHandle(void *file, BY_HANDLE_FILE_INFORMATION *info);
            int32_t ReadDirectoryChangesW(void *dir, void *buffer, uint32_t length, int32_t subtree, uint32_t filter, uint32_t *returned, OVERLAPPED *overlapped, void *routine);
            int32_t GetQueuedCompletionStatus(void *port, uint32_t *size, uintptr_t *key, OVERLAPPED **overlapped, uint32_t ms);
            int32_t CloseHandle(void *h);
            uint32_t GetLastError(void);
            C, 'kernel32.dll');
    }
}
