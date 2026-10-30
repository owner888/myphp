<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use Closure;
use MyPHP\{ErrWatchLimit, FileEvent, FileOp};
use RuntimeException;
use Throwable;

/**
 * What a watch with FileWatchOptions::$hintsOnly keeps (mygo's
 * fileWatchHints): no snapshot, only the directories it enrolls, and where
 * to look next. It stats no file: its events are FileHint, each naming a
 * directory in or under which the system said something changed.
 *
 * @internal
 */
final class Hints implements Looker
{
    use Paths;

    /** The watched directory, or the directory of the watched file ($file), with its identity. */
    private string $dir;
    private array $identity;
    private bool $file = false;
    /** @var array<string, Target> the enrolled directories, by path ("." for the root, ".." for its parent) */
    private array $targets = [];
    /** @var array<int, string> */
    private array $ids = [];
    private int $next = 0;
    private bool $subtree = false;
    private bool $noSubtree = false;
    /** What the next update does: the directories it hints at, the paths it enrolls if they are directories (walks), the enrolled directories it checks are still there (checks), whether it checks the root (check) and looks at everything (all). @var array<string, true> */
    private array $hints = [];
    /** @var array<string, true> */
    private array $walks = [];
    /** @var array<string, true> */
    private array $checks = [];
    private bool $check = false;
    private bool $all = false;

    /**
     * @param ?Closure(string, bool): bool $ignore
     * @param Closure(): bool $closed whether the watch was closed meanwhile
     */
    public function __construct(
        private readonly string $root,
        private readonly bool $recursive,
        private readonly ?Closure $ignore,
        private readonly int $maxEntries,
        private readonly Backend $native,
        private readonly Closure $closed,
    ) {}

    public function start(): array
    {
        $info = Fs::lstat($this->root);
        $this->dir = $this->root;
        if (!Fs::isDir($info)) {
            $this->file = true;
            $this->dir = dirname($this->root);
        }
        $this->identity = Fs::lstat($this->dir);
        if ($this->file) {
            $info = $this->identity;
        }
        if (!Fs::isDir($this->identity) || !Fs::same($info, $this->identity)) {
            throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
        }
        $this->watchParent();
        if (!$this->file) { // a file is watched through its directory, enrolled as ".."
            $this->enrollTree('.', $this->identity);
        }
        return [];
    }

    /** Enrolls the directory of a watched file as "..". */
    private function watchParent(): void
    {
        if (!$this->file || isset($this->targets['..'])) {
            return;
        }
        $this->add('..', Fs::lstat($this->dir));
    }

    public function gone(): bool
    {
        try {
            $info = Fs::lstat($this->dir);
        } catch (FsError $e) {
            return FsError::notExist($e);
        }
        return !Fs::isDir($info) || !Fs::same($info, $this->identity);
    }

    public function goneEvents(): array
    {
        // A watched file went away with its directory.
        return [new FileEvent(FileOp::FileRemove, '.', '', !$this->file)];
    }

    public function removedFile(): ?array
    {
        return null; // it stats no file
    }

    /** The path on disk of directory $p. */
    private function path(string $p): string
    {
        return match ($p) {
            '.' => $this->root,
            '..' => $this->dir,
            default => rtrim($this->root, '/') . '/' . $p,
        };
    }

    /** Whether directory $p or one above it is one Ignore ignores. */
    private function ignores(string $p): bool
    {
        if ($this->ignore === null) {
            return false;
        }
        for (; $p !== '.'; $p = self::dir($p)) {
            if (($this->ignore)($p, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enrolls directory $p and, when recursive, the directories beneath it,
     * but no file: what is beneath $p is watched through it. $want, when
     * set, is what $p must still be; a directory gone, unreadable or
     * replaced in between is left to the hint of its parent. Directories
     * are added to $seen, when set.
     *
     * @param ?array<string, true> $seen
     */
    private function enrollTree(string $p, ?array $want, ?array &$seen = null): void
    {
        if (($this->closed)()) {
            throw new Canceled;
        }
        try {
            $info = Fs::lstat($this->path($p));
        } catch (FsError $e) {
            if ($p !== '.' && (FsError::notExist($e) || FsError::permission($e))) {
                return;
            }
            throw $e;
        }
        if (!Fs::isDir($info) || $want !== null && !Fs::same($want, $info)) {
            if ($p === '.') {
                throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
            }
            return; // replaced by a file or a symlink, which aren't enrolled, or by another directory
        }
        if ($seen !== null) {
            $seen[$p] = true;
        }
        $this->enroll($p, $info);
        if (!$this->recursive || $this->subtree) {
            return; // only the directory, or a tree its target watches whole
        }
        try {
            $names = Fs::subdirs($this->path($p));
        } catch (FsError $e) {
            if ($p !== '.' && (FsError::notExist($e) || FsError::permission($e))) {
                return;
            }
            throw $e;
        }
        foreach ($names as $name) {
            $q = self::join($p, $name);
            if ($this->ignore !== null && ($this->ignore)($q, true)) {
                continue;
            }
            $this->enrollTree($q, null, $seen);
        }
    }

    /** Has the backend watch directory $p, which is $info; one replaced as it is enrolled is left to the hint of its parent. */
    private function enroll(string $p, array $info): void
    {
        if (isset($this->targets[$p])) {
            if (Fs::same($this->targets[$p]->info, $info)) {
                return;
            }
            $this->unenroll($p);
        }
        if (count($this->targets) >= $this->maxEntries) {
            throw new ErrWatchLimit;
        }
        try {
            $this->add($p, $info);
            return;
        } catch (Canceled $e) {
            throw $e;
        } catch (Throwable $e) {
            $err = $e;
        }
        try {
            $checked = Fs::lstat($this->path($p));
        } catch (FsError $e) {
            $checked = FsError::notExist($e) ? null : $info;
        }
        if ($checked === null || !Fs::same($checked, $info) || FsError::permission($err)) {
            if ($p === '.') {
                throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
            }
            $this->hints[self::dir($p)] = true;
            return;
        }
        throw $err;
    }

    private function add(string $p, array $info): void
    {
        $id = ++$this->next;
        $path = $this->path($p);
        if ($p === '.' && $this->recursive && !$this->noSubtree) {
            $t = new Target($id, $path, true, $info, true);
            try {
                $this->native->add($t);
                $this->subtree = true;
                $this->record($p, $t);
                return;
            } catch (Unsupported) {
                $this->noSubtree = true;
            } catch (Throwable $e) {
                throw self::wrap($path, $e);
            }
        }
        $t = new Target($id, $path, true, $info);
        try {
            $this->native->add($t);
        } catch (Canceled $e) {
            throw $e;
        } catch (Throwable $e) {
            throw self::wrap($path, $e);
        }
        $this->record($p, $t);
    }

    private function record(string $p, Target $t): void
    {
        $this->targets[$p] = $t;
        $this->ids[$t->id] = $p;
    }

    private function unenroll(string $p): void
    {
        $t = $this->targets[$p] ?? null;
        if ($t === null) {
            return;
        }
        unset($this->targets[$p], $this->ids[$t->id]);
        $this->native->remove($t->id);
        if ($p === '.') {
            $this->subtree = false;
        }
    }

    /** Unenrolls $p and the directories beneath it. */
    private function unenrollTree(string $p): void
    {
        foreach (self::keys($this->targets) as $q) {
            if ($q === $p || str_starts_with($q, "$p/")) {
                $this->unenroll($q);
            }
        }
    }

    public function mark(Notice $n): void
    {
        if ($n->err !== null) {
            if ($n->err instanceof Overflow) {
                $this->all = true;
                return;
            }
            if (!isset($this->ids[$n->target]) && $n->target !== 0) {
                return; // a target removed since
            }
            throw $n->err;
        }
        $p = $this->ids[$n->target] ?? null;
        if ($p === null) {
            return;
        }
        if ($p === '..') {
            if ($n->kind === Notice::LOST) {
                $this->unenroll('..'); // enrolled again, or the watch ends
            } elseif ($n->name !== basename($this->root) && ($n->name !== '' || !$this->file)) {
                return; // another entry of the directory
            }
            if ($this->file) {
                $this->hints['.'] = true;
            }
            $this->check = true;
            return;
        }
        if ($n->kind === Notice::LOST) {
            if ($p === '.' && $this->subtree) {
                $this->all = true;
            }
            $this->unenroll($p);
            $this->walks[$p] = true;
            $this->hints[self::dir($p)] = true;
            return;
        }
        if ($n->name === '') {
            // The directory itself, which may have moved.
            $this->checks[$p] = true;
            $this->hints[$p] = true;
            return;
        }
        if (!self::validName($n->name, $this->subtree && $p === '.')) {
            throw new RuntimeException('myphp: invalid name in a file watch notice');
        }
        $child = self::join($p, $n->name);
        $dir = self::dir($child);
        if (!$this->recursive && $dir !== '.' || $this->ignores($dir)) {
            return;
        }
        $this->hints[$dir] = true;
        if ($this->recursive && !$this->subtree) {
            // It may be a directory to enroll, or one enrolled that moved.
            if (isset($this->targets[$child])) {
                $this->checks[$child] = true;
            }
            $this->walks[$child] = true;
        }
        if (count($this->walks) + count($this->checks) > $this->maxEntries) {
            $this->all = true;
            $this->walks = $this->checks = [];
        }
    }

    public function pending(): bool
    {
        return $this->all || $this->check || $this->hints !== [] || $this->walks !== [] || $this->checks !== [];
    }

    public function update(): array
    {
        if (($this->closed)()) {
            throw new Canceled;
        }
        $info = Fs::lstat($this->dir);
        if (!Fs::isDir($info) || !Fs::same($info, $this->identity)) {
            throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
        }
        $this->watchParent();
        $this->check = false;
        if ($this->file) {
            $hint = $this->all || $this->hints !== [];
            $this->all = false;
            $this->hints = [];
            return $hint ? [new FileEvent(FileOp::FileHint, '.')] : [];
        }
        if ($this->all) {
            // Everything may have changed: enroll the tree again, and drop the directories gone.
            $this->all = false;
            $this->walks = $this->checks = [];
            $this->hints = ['.' => true];
            $seen = [];
            $this->enrollTree('.', $this->identity, $seen);
            foreach (self::keys($this->targets) as $q) {
                if ($q !== '..' && !isset($seen[$q])) {
                    $this->unenroll($q);
                }
            }
        } else {
            if (!isset($this->targets['.'])) {
                $this->enrollTree('.', $this->identity); // the root's target was lost
            }
            foreach (self::shallowFirst($this->checks) as $p) {
                $t = $this->targets[$p] ?? null;
                if ($t === null) {
                    continue;
                }
                try {
                    $now = Fs::lstat($this->path($p));
                    if (Fs::isDir($now) && Fs::same($t->info, $now)) {
                        continue;
                    }
                } catch (FsError) {
                }
                // Moved, removed or replaced: what was enrolled there is stale.
                $this->unenrollTree($p);
                $this->walks[$p] = true;
            }
            $this->checks = [];
            foreach (self::shallowFirst($this->walks) as $p) {
                if (isset($this->targets[$p]) || $this->ignores($p)) {
                    continue;
                }
                try {
                    $now = Fs::lstat($this->path($p));
                } catch (FsError) {
                    continue; // gone: watched through its directory
                }
                if (Fs::isDir($now)) {
                    $this->enrollTree($p, $now);
                }
            }
            $this->walks = [];
        }
        $events = self::hintEvents($this->hints);
        $this->hints = [];
        return $events;
    }

    /** The paths of $m, parents first. @param array<string, true> $m @return list<string> */
    private static function shallowFirst(array $m): array
    {
        $paths = self::keys($m);
        usort($paths, fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
        return $paths;
    }

    /**
     * The directories hinted at as events, sorted, leaving out those under
     * another one: a hint covers what is beneath (mygo's fileWatchHintEvents).
     *
     * @param array<string, true> $hints
     * @return list<FileEvent>
     */
    public static function hintEvents(array $hints): array
    {
        if (isset($hints['.'])) {
            return [new FileEvent(FileOp::FileHint, '.', '', true)];
        }
        $paths = [];
        foreach (self::keys($hints) as $p) {
            for ($q = self::dir($p); $q !== '.'; $q = self::dir($q)) {
                if (isset($hints[$q])) {
                    continue 2;
                }
            }
            $paths[] = $p;
        }
        sort($paths, SORT_STRING);
        return array_map(fn(string $p): FileEvent => new FileEvent(FileOp::FileHint, $p, '', true), $paths);
    }
}
