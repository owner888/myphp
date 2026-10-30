<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use Closure;
use MyPHP\{ErrWatchLimit, FileEvent, FileOp};
use RuntimeException;
use Throwable;

/**
 * What a watch saw of its tree, and which entries the backend watches
 * (mygo's fileWatchScan). The system only says where something changed, so
 * the watch lists or stats those places again and compares what it finds
 * with what it saw: the events are the differences.
 *
 * Paths are relative to the root, separated by slashes ("." for the root);
 * a stat is Fs::lstat's. PHP arrays turn keys like "42" into integers, so
 * keys are read back through keys().
 *
 * @internal
 */
final class Scan implements Looker
{
    use Paths;

    /** The watched directory, or the directory of the watched file ($file), with its identity, and the file's name. */
    private string $dir;
    private array $identity;
    private bool $file = false;
    private string $name = '';
    /** @var array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> what the scan saw, by path */
    private array $entries = [];
    /** @var array<string, array<string, true>> the names in each listed directory */
    private array $children = [];
    /** @var array<string, Target> */
    private array $targets = [];
    /** @var array<int, string> */
    private array $ids = [];
    private int $next = 0;
    /** The root's target watches the whole tree, so no other directory is enrolled; $noSubtree that the backend can't, and $noFiles that it watches files only through their directories, so no file is enrolled. */
    private bool $subtree = false;
    private bool $noSubtree = false;
    private bool $noFiles = false;
    /** What to look at again in the next update: directories to list, entries to stat, entries the system said were written, and whether to list every directory, after the system lost changes. @var array<string, true> */
    private array $lists = [];
    /** @var array<string, true> */
    private array $stats = [];
    /** @var array<string, true> */
    private array $writes = [];
    private bool $all = false;
    /** Entries that changed while the scan looked at them, reported as written. @var array<string, true> */
    private array $races = [];
    /** The directories Ignore ignored, by name in each listed directory, so that notices about what is in them are dropped. @var array<string, array<string, true>> */
    private array $ignored = [];
    /** What an update changed, as it was before (null for an entry that didn't exist); null during the first scan, whose contents are not events. @var ?array<string, ?array> */
    private ?array $before = [];
    /** The watched file the last update found gone, as it was before: its stat, or null. */
    private ?array $removed = null;

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

    /** Enrolls the root and, below it, what the watch covers. The events are for entries that changed while it looked at them. @return list<FileEvent> */
    public function start(): array
    {
        $info = Fs::lstat($this->root);
        $this->dir = $this->root;
        if (!Fs::isDir($info)) {
            // A file is watched through its directory, which names it.
            $this->file = true;
            $this->dir = dirname($this->root);
            $this->name = basename($this->root);
        }
        $this->identity = Fs::lstat($this->dir);
        if ($this->file) {
            $info = $this->identity;
        }
        if (!Fs::isDir($this->identity) || !Fs::same($info, $this->identity)) {
            throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
        }
        $this->watchParent();
        if ($this->file) {
            $info = Fs::lstat($this->path('.'));
        }
        // The initial contents are not events, so what the scan sees is not kept as it was before (touch).
        $this->before = null;
        $this->visit('.', $info);
        $this->before = [];
        return $this->events();
    }

    /** Enrolls the directory of a watched file as "..", whose notices about other entries are ignored. */
    private function watchParent(): void
    {
        if (!$this->file) {
            return;
        }
        $parent = dirname($this->root);
        if ($parent === $this->root) {
            return;
        }
        $this->add('..', Fs::lstat($parent));
    }

    /** Whether the watched directory, or the directory of the watched file, went away or was replaced. */
    public function gone(): bool
    {
        try {
            $info = Fs::lstat($this->dir);
        } catch (FsError $e) {
            return FsError::notExist($e);
        }
        return !Fs::isDir($info) || !Fs::same($info, $this->identity);
    }

    /** The last events of a watch whose directory went away. @return list<FileEvent> */
    public function goneEvents(): array
    {
        $info = $this->entries['.'] ?? null;
        if ($info === null) {
            return []; // a watched file that was already gone
        }
        return [new FileEvent(FileOp::FileRemove, '.', '', Fs::isDir($info))];
    }

    /** The path on disk of $p. */
    private function path(string $p): string
    {
        if ($this->file) {
            return $this->dir . '/' . $this->name;
        }
        return $p === '.' ? $this->dir : rtrim($this->dir, '/') . '/' . $p;
    }

    /** Whether $p is in a directory Ignore ignored. */
    private function ignores(string $p): bool
    {
        while ($p !== '.') {
            if (isset($this->ignored[self::dir($p)][self::base($p)])) {
                return true;
            }
            $p = self::dir($p);
        }
        return false;
    }

    /** Whether Ignore ignores $child, remembering the directories it does among those ignored in $in. */
    private function skip(string $child, bool $isDir, string $in): bool
    {
        if ($this->ignore === null || !($this->ignore)($child, $isDir)) {
            return false;
        }
        if ($isDir) {
            $this->ignored[$in][self::base($child)] = true;
        }
        return true;
    }

    /** Records what $n says changed, for the next update. It throws when $n ends the watch. */
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
                // The next update enrolls it again, or ends the watch.
                $this->unenroll('..');
            } elseif ($n->name !== basename($this->root) && ($n->name !== '' || !$this->file)) {
                return;
            }
            if ($this->file) {
                $this->stats['.'] = true;
                if ($n->kind === Notice::WRITE && $n->name !== '') { // a write named for the file, as the stream of its directory names it; one naming nothing is a sibling's as well: the stat tells
                    $this->writes['.'] = true;
                }
            } else {
                $this->lists['.'] = true;
            }
            return;
        }
        if ($this->file) {
            // The file itself (macOS).
            if ($n->kind === Notice::LOST) {
                $this->unenroll('.');
            }
            $this->stats['.'] = true;
            if ($n->kind === Notice::WRITE) {
                $this->writes['.'] = true;
            }
            return;
        }
        $t = $this->targets[$p];
        $listable = $t->directory && ($p === '.' || $this->recursive);
        if ($n->kind === Notice::LOST) {
            // The next update enrolls it again if it still exists, and looks at everything if it watched the whole tree.
            if ($p === '.' && $this->subtree) {
                $this->all = true;
            }
            $this->unenroll($p);
            $n = new Notice(kind: Notice::DIRTY);
        }
        if ($n->name === '' || !$listable) {
            if ($listable) {
                $this->lists[$p] = true;
            } else {
                $this->stats[$p] = true;
            }
            if ($n->kind === Notice::WRITE) {
                $this->writes[$p] = true;
            }
            return;
        }
        if (!self::validName($n->name, $t->subtree)) {
            throw new RuntimeException('myphp: invalid name in a file watch notice');
        }
        // Statting a child lists its directory if it appeared, went away or was replaced.
        $child = self::join($p, $n->name);
        if ($this->ignores($child)) {
            return;
        }
        $this->stats[$child] = true;
        if ($n->kind === Notice::WRITE) {
            $this->writes[$child] = true;
        }
        if (count($this->lists) + count($this->stats) + count($this->writes) > $this->maxEntries) {
            $this->all = true;
            $this->lists = $this->stats = $this->writes = [];
        }
    }

    /** Whether an update has anything to do. */
    public function pending(): bool
    {
        return $this->all || $this->lists !== [] || $this->stats !== [] || $this->writes !== [];
    }

    /** The watched file the last update found gone, as the watch last saw it (its stat), or null (mygo's removedFile). */
    public function removedFile(): ?array
    {
        return $this->removed;
    }

    /** Looks again at what changed. @return list<FileEvent> */
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
        $this->before = [];
        if ($this->file) {
            $all = $this->all;
            $this->all = false;
            if ($all || $this->stats !== []) {
                $this->statFile();
            }
            $this->stats = [];
            return $this->events();
        }
        if ($this->all) {
            $this->all = false;
            foreach (self::keys($this->entries) as $p) {
                if (Fs::isDir($this->entries[$p]) && ($p === '.' || $this->recursive)) {
                    $this->lists[$p] = true;
                }
            }
        }
        foreach (self::keys($this->stats) as $p) {
            $this->stat($p);
        }
        // Parents first: listing a directory again can replace those below it.
        $dirs = self::keys($this->lists);
        usort($dirs, fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
        foreach ($dirs as $p) {
            if (!isset($this->entries[$p])) {
                continue; // removed with its parent
            }
            $this->list($p);
        }
        $this->lists = $this->stats = [];
        return $this->events();
    }

    /** Compares what the last update changed with what it was, then forgets both. @return list<FileEvent> */
    private function events(): array
    {
        $writes = $this->writes;
        foreach (self::keys($this->races) as $p) {
            $writes[$p] = true;
        }
        foreach (self::keys($writes) as $p) {
            if (isset($this->entries[$p])) {
                $this->touch($p);
            }
        }
        $before = $after = [];
        foreach (self::keys($this->before) as $p) {
            if ($this->before[$p] !== null) {
                $before[$p] = $this->before[$p];
            }
            if (isset($this->entries[$p])) {
                $after[$p] = $this->entries[$p];
            }
        }
        $events = self::diff($before, $after, $writes);
        $a = $before['.'] ?? null;
        $b = $after['.'] ?? null;
        $this->removed = $this->file && $a !== null && $b === null ? $a : null;
        if ($this->file && $a !== null && $b !== null && !Fs::isDir($a) && !Fs::isDir($b) && !Fs::same($a, $b)) {
            // A file replaced, as editors save, is written.
            $events = [new FileEvent(FileOp::FileWrite, '.')];
        }
        $this->before = $this->writes = $this->races = [];
        return $events;
    }

    /** Looks at the watched file again. */
    private function statFile(): void
    {
        $old = $this->entries['.'] ?? null;
        try {
            $info = Fs::lstat($this->path('.'));
        } catch (FsError $e) {
            if (FsError::notExist($e)) {
                if ($old !== null) {
                    $this->removeTree('.');
                }
                return;
            }
            if ($this->inaccessible('.', $e)) {
                return;
            }
            throw $e;
        }
        if (Fs::same($old, $info)) {
            $this->set('.', $info);
            $this->enroll('.', $info);
            return;
        }
        if ($old !== null) {
            $this->removeTree('.');
        }
        $this->visit('.', $info);
    }

    /** Looks at $p again. For an entry it didn't know, or one that is gone or was replaced, it lists the directory that has it instead. */
    private function stat(string $p): void
    {
        $known = $p;
        while ($known !== '.' && !isset($this->entries[$known])) {
            $known = self::dir($known);
        }
        if ($known !== $p && self::dir($p) === $known && $this->ignore !== null && $this->listable($known)) {
            // Something new that Ignore may ignore, which needs no listing.
            try {
                if ($this->skip($p, Fs::isDir(Fs::lstat($this->path($p))), $known)) {
                    return;
                }
            } catch (FsError) {
                // listed below
            }
        }
        if ($known === '.' || $known !== $p && $this->listable($known)) {
            $this->lists[$known] = true;
            return;
        }
        $p = $known;
        $old = $this->entries[$p];
        $err = null;
        try {
            $info = Fs::lstat($this->path($p));
            if (Fs::same($old, $info)) {
                $this->set($p, $info);
                $this->enroll($p, $info);
                return;
            }
        } catch (FsError $e) {
            $err = $e;
        }
        if ($this->inaccessible($p, $err)) {
            return;
        }
        if ($err !== null && !FsError::notExist($err)) {
            throw $err;
        }
        $this->lists[self::dir($p)] = true;
    }

    /**
     * Whether $e says $p can't be looked at, as a directory without
     * permission: it is left as it was seen, if at all, rather than ending
     * the watch. The watched directory itself can't be.
     */
    private function inaccessible(string $p, ?Throwable $e): bool
    {
        return $e !== null && ($p !== '.' || $this->file) && FsError::permission($e);
    }

    /** Whether $p is a directory the watch lists. */
    private function listable(string $p): bool
    {
        $info = $this->entries[$p] ?? null;
        return $info !== null && $this->descends($p, $info);
    }

    /** Lists directory $p again: what appeared or was replaced in it is visited, what is gone removed, and the rest stat'ed again. */
    private function list(string $p): void
    {
        try {
            $info = $this->openDir($p, $this->entries[$p] ?? null);
        } catch (Throwable $e) {
            $this->listFailed($p, $e);
            return;
        }
        $this->enroll($p, $info);
        $this->set($p, $info);
        try {
            $names = $this->readDir($p, $info);
        } catch (Throwable $e) {
            $this->listFailed($p, $e);
            return;
        }
        $seen = [];
        $this->ignored[$p] = [];
        foreach ($names as $name) {
            $child = self::join($p, $name);
            try {
                $info = Fs::lstat($this->path($child));
            } catch (FsError $e) {
                if (FsError::notExist($e)) {
                    $this->races[$p] = true;
                    continue;
                }
                if (!$this->inaccessible($child, $e)) {
                    throw $e;
                }
                $known = $this->entries[$child] ?? null;
                if (!$this->skip($child, $known !== null && Fs::isDir($known), $p)) {
                    $seen[$name] = true; // as it was, if it was known
                }
                continue;
            }
            if ($this->skip($child, Fs::isDir($info), $p)) {
                continue;
            }
            $seen[$name] = true;
            $old = $this->entries[$child] ?? null;
            if ($old !== null) {
                if (Fs::same($old, $info)) {
                    $this->set($child, $info);
                    $this->enroll($child, $info);
                    continue;
                }
                $this->removeTree($child);
            }
            $this->visit($child, $info);
        }
        foreach (self::keys($this->children[$p] ?? []) as $name) {
            if (!isset($seen[$name])) {
                $this->removeTree(self::join($p, $name));
            }
        }
    }

    /** What listing $p failing means: gone or replaced, what has it knows. */
    private function listFailed(string $p, Throwable $e): void
    {
        if ($p === '.') {
            throw $e;
        }
        if (FsError::notExist($e) || $e instanceof IdentityChanged) {
            $this->list(self::dir($p));
            return;
        }
        if (!$this->inaccessible($p, $e)) {
            throw $e;
        }
    }

    /** Checks directory $p is still the directory $info, and returns what it is now. @return array{0: int, 1: int, 2: int, 3: int, 4: int} */
    private function openDir(string $p, ?array $info): array
    {
        $opened = Fs::lstat($this->path($p));
        if (!Fs::isDir($opened) || !Fs::same($info, $opened)) {
            throw new IdentityChanged("watch $p: watched root identity changed");
        }
        return $opened;
    }

    /** The names in directory $p, which must still be the directory $info once listed. @return list<string> */
    private function readDir(string $p, array $info): array
    {
        $names = Fs::readDir($this->path($p));
        $this->openDir($p, $info);
        return $names;
    }

    /**
     * Records $p, which wasn't known, and when the watch covers it what is
     * beneath it. An entry removed or replaced while it looks is skipped,
     * and its directory reported as written.
     */
    private function visit(string $p, array $info): void
    {
        if (($this->closed)()) {
            throw new Canceled;
        }
        if (count($this->entries) >= $this->maxEntries || count($this->races) > $this->maxEntries) {
            throw new ErrWatchLimit;
        }
        $gone = function () use ($p): void {
            $this->unenroll($p);
            $this->races[self::dir($p)] = true;
        };
        // Enroll first, then check the entry is still what was enrolled, so that nothing changes unseen in between. Replacements, as editors' atomic saves do, are retried a few times.
        $read = false;
        for ($attempt = 0; ; $attempt++) {
            if ($attempt === 3) {
                $gone();
                return;
            }
            $err = null;
            try {
                $this->enroll($p, $info);
            } catch (Throwable $e) {
                if (FsError::permission($e) || FsError::is($e, Unsupported::class) || FsError::is($e, Canceled::class)) {
                    throw $e;
                }
                $err = $e;
            }
            $statErr = null;
            $checked = null;
            try {
                $checked = Fs::lstat($this->path($p));
            } catch (FsError $e) {
                $statErr = $e;
            }
            if (($p !== '.' || $this->file) && (FsError::notExist($statErr) || FsError::notExist($err))) {
                $gone();
                return;
            }
            if ($statErr !== null) {
                throw $statErr;
            }
            if (!Fs::same($info, $checked)) {
                if ($p === '.' && !$this->file) {
                    throw new IdentityChanged("watch {$this->dir}: watched root identity changed");
                }
                $this->races[$p] = true;
                $info = $checked;
                continue;
            }
            if ($err !== null) {
                throw $err;
            }
            $info = $checked;
            if (!$this->descends($p, $info)) {
                break;
            }
            try {
                $info = $this->openDir($p, $info);
                $read = true;
                break;
            } catch (Throwable $e) {
                if ($this->inaccessible($p, $e)) {
                    break; // known, but not what is in it
                }
                if ($p === '.') {
                    throw $e;
                }
                if (FsError::notExist($e)) {
                    $gone();
                    return;
                }
                if (!$e instanceof IdentityChanged) {
                    throw $e;
                }
            }
            $this->races[$p] = true;
            try {
                $info = Fs::lstat($this->path($p));
            } catch (FsError $e) {
                if (FsError::notExist($e)) {
                    $gone();
                    return;
                }
                throw $e;
            }
        }
        $this->set($p, $info);
        if (!$read) {
            return;
        }
        try {
            $names = $this->readDir($p, $info);
        } catch (Throwable $e) {
            if ($this->inaccessible($p, $e)) {
                return; // what it could list
            }
            if ($p === '.' || !$e instanceof IdentityChanged) {
                throw $e;
            }
            $this->races[$p] = true; // replaced while listed: its directory's notice lists it again
            return;
        }
        $this->ignored[$p] ??= [];
        foreach ($names as $name) {
            $child = self::join($p, $name);
            try {
                $info = Fs::lstat($this->path($child));
            } catch (FsError $e) {
                if (FsError::notExist($e)) {
                    $this->races[$p] = true;
                    continue;
                }
                if ($this->inaccessible($child, $e)) {
                    continue;
                }
                throw $e;
            }
            if ($this->skip($child, Fs::isDir($info), $p)) {
                continue;
            }
            $this->visit($child, $info);
        }
    }

    /** Whether the watch covers what is in $p. Symlinks are not directories to lstat. */
    private function descends(string $p, array $info): bool
    {
        return Fs::isDir($info) && !$this->file && ($p === '.' || $this->recursive);
    }

    /** Remembers $p as it is before an update changes it; nothing during the first scan. */
    private function touch(string $p): void
    {
        if ($this->before !== null && !array_key_exists($p, $this->before)) {
            $this->before[$p] = $this->entries[$p] ?? null;
        }
    }

    private function set(string $p, array $info): void
    {
        $this->touch($p);
        $this->entries[$p] = $info;
        if ($p !== '.') {
            $this->children[self::dir($p)][self::base($p)] = true;
        }
    }

    /** Forgets $p and what is beneath it. */
    private function removeTree(string $p): void
    {
        foreach (self::keys($this->children[$p] ?? []) as $name) {
            $this->removeTree(self::join($p, $name));
        }
        unset($this->children[$p], $this->ignored[$p]);
        $this->touch($p);
        unset($this->entries[$p]);
        if ($p !== '.') {
            unset($this->children[self::dir($p)][self::base($p)]);
        }
        $this->unenroll($p);
    }

    /**
     * Has the backend watch $p, which the scan saw as $info, unless the
     * root's target covers it, it is a file the backend watches through its
     * directory, or a symlink or a special file. A watched file is enrolled
     * only while it is a file.
     */
    private function enroll(string $p, array $info): void
    {
        if (!(Fs::isRegular($info) && !$this->noFiles || Fs::isDir($info) && !$this->file) || $this->subtree && $p !== '.') {
            $this->unenroll($p);
            return;
        }
        try {
            $this->add($p, $info);
        } catch (Throwable $e) {
            if (!$this->inaccessible($p, $e)) {
                throw $e;
            }
            // seen, but not watched
        }
    }

    private function add(string $p, array $info): void
    {
        if (isset($this->targets[$p])) {
            if (Fs::same($this->targets[$p]->info, $info)) {
                return;
            }
            $this->unenroll($p);
        }
        $path = match ($p) {
            '.' => $this->root,
            '..' => dirname($this->root),
            default => rtrim($this->root, '/') . '/' . $p,
        };
        $id = ++$this->next;
        if ($p === '.' && $this->recursive && !$this->noSubtree) {
            $t = new Target($id, $path, Fs::isDir($info), $info, true);
            try {
                $this->native->add($t);
                // Directories below enrolled while the root had no target are covered by it now.
                foreach (self::keys($this->targets) as $q) {
                    if ($q !== '.' && $q !== '..') {
                        $this->unenroll($q);
                    }
                }
                $this->subtree = true;
                $this->record($p, $t);
                return;
            } catch (Unsupported) {
                $this->noSubtree = true;
            } catch (Throwable $e) {
                throw self::wrap($path, $e);
            }
        }
        $t = new Target($id, $path, Fs::isDir($info), $info);
        try {
            $this->native->add($t);
        } catch (Canceled $e) {
            throw $e;
        } catch (Unsupported $e) {
            if ($t->directory) {
                throw self::wrap($path, $e);
            }
            $this->noFiles = true; // its directory, enrolled before it was listed, watches it
            return;
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

    /**
     * What an update changed, as it was before and is after, as events
     * (mygo's fileWatchDiff). An entry whose identity moved to another path
     * is a rename, when nothing else has that identity; a file replaced at
     * its path by another file is a write.
     *
     * @param array<string, array> $before
     * @param array<string, array> $after
     * @param array<string, true> $writes
     * @return list<FileEvent>
     */
    public static function diff(array $before, array $after, array $writes): array
    {
        $removed = $created = $replaced = [];
        foreach (self::keys($before) as $p) {
            $b = $after[$p] ?? null;
            if ($b !== null && !Fs::same($before[$p], $b) && !Fs::isDir($before[$p]) && !Fs::isDir($b)) {
                // A file replaced at its path by another (mygo's c0c4c42), as editors and atomic saves rename a temporary file
                // over it: written, not removed and created, which would have an app close a document it still has.
                $replaced[$p] = true;
            } elseif (!Fs::same($before[$p], $b)) {
                $removed[$p] = $before[$p];
            }
        }
        foreach (self::keys($after) as $p) {
            if (!isset($replaced[$p]) && !Fs::same($before[$p] ?? null, $after[$p])) {
                $created[$p] = $after[$p];
            }
        }
        // Pairing looks each removed entry up by identity: comparing it with every entry instead would make a directory of
        // n files renamed n² comparisons (mygo's d7b4d54). The entries before and after counted by identity, with the
        // path of one.
        $ids = [[], []];
        if ($removed !== [] && $created !== []) {
            foreach ([$before, $after] as $side => $m) {
                foreach ($m as $p => $info) {
                    $k = Fs::identityKey($info);
                    $ids[$side][$k] = [(string)$p, ($ids[$side][$k][1] ?? 0) + 1];
                }
            }
        }
        /** @var list<array{0: string, 1: list<FileEvent>}> $groups */
        $groups = [];
        $paired = [];
        $keys = self::keys($removed);
        sort($keys, SORT_STRING);
        foreach ($keys as $old) {
            $a = $removed[$old];
            // A hard link makes pairing by identity ambiguous: the identity must be the only one of its kind before, after and among what was created.
            $k = Fs::identityKey($a);
            if (($ids[0][$k][1] ?? 0) !== 1 || ($ids[1][$k][1] ?? 0) !== 1 || !isset($created[$match = $ids[1][$k][0]])) {
                continue;
            }
            // A replacement is a removal and a creation even if the old name survives elsewhere.
            if (isset($after[$old]) || isset($before[$match])) {
                continue;
            }
            // Moved with a directory renamed above it, which says it all.
            $descendant = false;
            for ($parent = self::dir($old); $parent !== '.'; $parent = self::dir($parent)) {
                if (isset($paired[$parent]) && $match === $paired[$parent] . substr($old, strlen($parent))) {
                    $descendant = true;
                    break;
                }
            }
            $paired[$old] = $match;
            unset($removed[$old]);
            $b = $created[$match];
            unset($created[$match]);
            $events = [];
            if (!$descendant) {
                $events[] = new FileEvent(FileOp::FileRename, $match, $old, Fs::isDir($a));
            }
            if (!Fs::isDir($b) && (isset($writes[$old]) || isset($writes[$match]) || Fs::changed($a, $b))) {
                $events[] = new FileEvent(FileOp::FileWrite, $match);
            }
            $groups[] = [$match, $events];
        }
        $paths = self::keys($before + $after);
        foreach ($paths as $p) {
            $events = [];
            if (isset($removed[$p])) {
                $events[] = new FileEvent(FileOp::FileRemove, $p, '', Fs::isDir($removed[$p]));
            }
            if (isset($created[$p])) {
                $events[] = new FileEvent(FileOp::FileCreate, $p, '', Fs::isDir($created[$p]));
            }
            $a = $before[$p] ?? null;
            $b = $after[$p] ?? null;
            if (isset($replaced[$p]) || ($a !== null && $b !== null && !Fs::isDir($b) && Fs::same($a, $b) && (isset($writes[$p]) || Fs::changed($a, $b)))) {
                $events[] = new FileEvent(FileOp::FileWrite, $p);
            }
            if ($events !== []) {
                $groups[] = [$p, $events];
            }
        }
        usort($groups, fn(array $x, array $y): int => strcmp($x[0], $y[0]));
        return array_merge([], ...array_map(fn(array $g): array => $g[1], $groups));
    }
}
