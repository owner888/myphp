<?php
// File watches (mygo's watch_test.go): the scan against a fake backend,
// whose notices the tests send, then the system's (inotify here, kqueue on
// macOS) on a real directory.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, ErrWatchLimit, FileEvent, FileOp, FileWatcher, FileWatchOptions};
use MyPHP\Native\FileWatch\{Backend, Backends, FsError, Notice, Overflow, Scan, Target, Unsupported};

/** A backend whose notices the test sends (mygo's fake.FileWatch). */
final class FakeFileWatch implements Backend
{
    public static ?self $last = null;
    /** Whether it watches a tree with one target. */
    public static bool $subtree = false;
    /** Called before each add, as mygo's FileWatchAddHook; what it throws is add's. @var ?\Closure(Target): void */
    public static ?\Closure $addHook = null;
    /** @var array<int, Target> */
    public array $targets = [];
    /** @var list<Notice> */
    public array $queue = [];
    public bool $closed = false;
    /** @var ?\Closure(Target): void this one's, as mygo's AddHook */
    public ?\Closure $hook = null;

    public function add(Target $t): void
    {
        if ($t->subtree && !self::$subtree) {
            throw new Unsupported;
        }
        if (self::$addHook !== null) { (self::$addHook)($t); }
        if ($this->hook !== null) { ($this->hook)($t); }
        $this->targets[$t->id] = $t;
    }

    public function remove(int $id): void { unset($this->targets[$id]); }

    public function poll(float $timeout): array
    {
        if ($this->queue === [] && $timeout > 0) { usleep((int)(min($timeout, 0.005) * 1e6)); }
        [$q, $this->queue] = [$this->queue, []];
        return $q;
    }

    public function close(): void { $this->closed = true; $this->targets = []; }
}

/** A temporary directory, removed at the end. */
function watchDir(): string
{
    $d = sys_get_temp_dir() . '/myphp-watch-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($d);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($d)));
    return realpath($d);
}

/** Sends $n for the target at $path, found by path. */
function watchNotice(FileWatcher $w, string $path, Notice $n): void
{
    $want = match ($path) { '.' => $w->root(), '..' => dirname($w->root()), default => $w->root() . '/' . $path };
    foreach (FakeFileWatch::$last->targets as $t) {
        if ($t->path === $want) {
            FakeFileWatch::$last->queue[] = new Notice($t->id, $n->kind, $n->name, $n->err);
            return;
        }
    }
    check(false, "no watch target for $path");
}

/** The paths the fake watches. @return list<string> */
function watchTargets(): array
{
    return array_values(array_map(fn(Target $t) => $t->path, FakeFileWatch::$last->targets));
}

/** The events of batches not yet looked at. @var array<int, list<FileEvent>> */
$GLOBALS['watchPending'] = [];

/** The next event, from the batch next() returned last or the next one. */
function watchNext(FileWatcher $w): ?FileEvent
{
    $k = spl_object_id($w);
    while (($GLOBALS['watchPending'][$k] ?? []) === []) {
        $batch = $w->next(2);
        if ($batch === null || $batch === []) { check(false, 'no batch: ' . json_encode($batch)); return null; }
        $GLOBALS['watchPending'][$k] = $batch;
    }
    return array_shift($GLOBALS['watchPending'][$k]);
}

/** The JSON of events, for comparing. */
function watchJson(mixed $events): string
{
    return json_encode($events);
}

function ev(FileOp $op, string $path, bool $isDir = false, string $old = ''): FileEvent
{
    return new FileEvent($op, $path, $old, $isDir);
}

Backends::$fake = fn() => FakeFileWatch::$last = new FakeFileWatch;
$noDebounce = fn(bool $recursive = false, ?\Closure $ignore = null, int $max = 0) => new FileWatchOptions(recursive: $recursive, ignore: $ignore, debounce: -1, maxEntries: $max);

(function () use ($noDebounce) {
    // The life of a watch.
    $dir = watchDir();
    $w = App::watchFiles($dir . '/.', $noDebounce());
    same($dir, $w->root(), 'the root, resolved');
    file_put_contents("$dir/a", 'hello');
    watchNotice($w, '.', new Notice);
    // Directories written are not reported: their entries are.
    same(watchJson([ev(FileOp::FileCreate, 'a')]), watchJson($w->next(2)), 'a batch');
    same([], $w->next(0), 'no batch yet');
    $w->close();
    $w->close();
    same([null, null, true], [$w->next(), $w->err(), $w->done()], 'closed');
    check(FakeFileWatch::$last->closed, 'the backend closed');
})();

(function () use ($noDebounce) {
    // A failure of a target removed since is ignored; validation.
    $w = App::watchFiles(watchDir());
    FakeFileWatch::$last->queue[] = new Notice(99999, err: new \RuntimeException('retired target'));
    same([], $w->next(0.025), 'a stale failure does not stop the watch');
    $w->close();
    foreach ([fn() => App::watchFiles(watchDir(), new FileWatchOptions(maxEntries: -1)), fn() => App::watchFiles('')] as $bad) {
        try { $bad(); check(false, 'accepted'); } catch (\InvalidArgumentException) { check(true, 'rejected'); }
    }
    $w = App::watchFiles(watchDir(), new FileWatchOptions(debounce: 1, maxDelay: 0.001));
    same(1.0, \Closure::bind(fn() => $this->maxDelay, $w, FileWatcher::class)(), 'a maxDelay shorter than the debounce is the debounce');
    $w->close();
    $dir = watchDir();
    mkdir("$dir/a/b", 0777, true);
    try { App::watchFiles($dir, $noDebounce(true, null, 2)); check(false, 'limit'); } catch (ErrWatchLimit) { check(true, 'the entry limit'); }
})();

(function () use ($noDebounce) {
    // What changed while next() wasn't called comes in its next batch.
    $dir = watchDir();
    $w = App::watchFiles($dir, $noDebounce());
    foreach (['a', 'b'] as $name) {
        touch("$dir/$name");
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: $name));
    }
    same(watchJson([ev(FileOp::FileCreate, 'a'), ev(FileOp::FileCreate, 'b')]), watchJson($w->next(2)), 'one batch');
    for ($i = 0; $i < 20; $i++) {
        touch("$dir/c$i");
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: "c$i"));
    }
    $seen = 0;
    while ($seen < 20 && ($b = $w->next(2)) !== null && $b !== []) { $seen += count($b); }
    same(20, $seen, 'every event');
    $w->close();
})();

(function () use ($noDebounce) {
    // A watched directory removed ends the watch after an event saying so.
    $dir = watchDir() . '/root';
    mkdir($dir);
    $w = App::watchFiles($dir, $noDebounce());
    rmdir($dir);
    watchNotice($w, '.', new Notice(kind: Notice::REMOVE));
    same(watchJson([ev(FileOp::FileRemove, '.', true)]), watchJson($w->next(2)), 'the root removed');
    same([null, null], [$w->next(), $w->err()], 'then the end');
})();

(function () use ($noDebounce) {
    // An entry replaced or removed between the scan seeing it and the backend enrolling it doesn't stop the watch.
    foreach ([false, true] as $recursive) {
        foreach ([false, true] as $replace) {
            foreach ([false, true] as $isDir) {
                $name = "recursive=$recursive replace=$replace dir=$isDir";
                $dir = watchDir();
                $w = App::watchFiles($dir, $noDebounce($recursive));
                $path = "$dir/entry";
                $create = fn(string $p) => $isDir ? mkdir($p) : file_put_contents($p, 'data');
                $raced = false;
                FakeFileWatch::$last->hook = function (Target $t) use (&$raced, $w, $path, $replace, $isDir, $create, $dir): void {
                    if ($t->path !== $w->root() . '/entry' || $raced) { return; }
                    $raced = true;
                    if (!$replace) { exec('rm -rf ' . escapeshellarg($path)); return; }
                    $create("$dir/replacement");
                    if ($isDir) { rename($path, watchDir() . '/retired'); }
                    rename("$dir/replacement", $path);
                };
                $create($path);
                watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'entry'));
                // An entry removed meanwhile was never there.
                while ($replace && ($e = watchNext($w)) !== null && $e->path !== 'entry') {}
                touch("$dir/later");
                watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'later'));
                while (($e = watchNext($w)) !== null && !($e->path === 'later' && $e->op === FileOp::FileCreate)) {}
                check($e !== null && $raced, "enrollment race: $name");
                $w->close();
            }
        }
    }
})();

(function () use ($noDebounce) {
    // A notice for a file looks only at it, and one for a directory only at its entries.
    $dir = watchDir();
    foreach (['a', 'b/c', 'd/e'] as $p) {
        @mkdir(dirname("$dir/$p"), 0777, true);
        touch("$dir/$p");
    }
    $w = App::watchFiles($dir, $noDebounce(true));
    // Changes the watch isn't told about stay unseen.
    file_put_contents("$dir/a", 'unseen');
    touch("$dir/d/f");
    file_put_contents("$dir/b/c", 'seen');
    watchNotice($w, 'b', new Notice(kind: Notice::WRITE, name: 'c'));
    same(watchJson(ev(FileOp::FileWrite, 'b/c')), watchJson(watchNext($w)), 'the file notified');
    watchNotice($w, 'd', new Notice);
    same(watchJson(ev(FileOp::FileCreate, 'd/f')), watchJson(watchNext($w)), 'the directory notified');
    // Losing changes looks at everything.
    FakeFileWatch::$last->queue[] = new Notice(err: new Overflow);
    same(watchJson(ev(FileOp::FileWrite, 'a')), watchJson(watchNext($w)), 'an overflow');
    $w->close();
})();

(function () use ($noDebounce) {
    // A directory renamed is one rename, not one for everything in it.
    $dir = watchDir();
    mkdir("$dir/a/b", 0777, true);
    touch("$dir/a/b/c");
    $w = App::watchFiles($dir, $noDebounce(true));
    rename("$dir/a", "$dir/z");
    watchNotice($w, '.', new Notice(kind: Notice::MOVE_FROM, name: 'a'));
    same(watchJson(ev(FileOp::FileRename, 'z', true, 'a')), watchJson(watchNext($w)), 'a directory renamed');
    // Its entries are enrolled again under their new paths.
    $enrolled = watchTargets();
    foreach (['z', 'z/b', 'z/b/c'] as $p) {
        check(in_array("$dir/$p", $enrolled, true), "$p enrolled");
    }
    check(!in_array("$dir/a", $enrolled, true), 'a no longer enrolled');
    $w->close();
})();

(function () use ($noDebounce) {
    // A backend that watches a tree with one target is given only the root, and names what changes below by its path.
    FakeFileWatch::$subtree = true;
    try {
        $dir = watchDir();
        mkdir("$dir/a/b", 0777, true);
        $w = App::watchFiles($dir, $noDebounce(true));
        same([$dir], watchTargets(), 'only the root enrolled');
        check(array_values(FakeFileWatch::$last->targets)[0]->subtree, 'as a subtree target');
        mkdir("$dir/a/b/c/d", 0777, true);
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'a/b/c'));
        same(watchJson(ev(FileOp::FileCreate, 'a/b/c', true)), watchJson(watchNext($w)), 'a path below');
        same(watchJson(ev(FileOp::FileCreate, 'a/b/c/d', true)), watchJson(watchNext($w)), 'and what is in it');
        // The root's target lost enrolls it again and looks at everything.
        touch("$dir/a/f");
        watchNotice($w, '.', new Notice(kind: Notice::LOST));
        same(watchJson(ev(FileOp::FileCreate, 'a/f')), watchJson(watchNext($w)), 'everything looked at');
        same([$dir], watchTargets(), 'the root enrolled again');
        watchNotice($w, '.', new Notice(name: '../x'));
        try { $w->next(1); check(false, 'a name out of the tree'); } catch (\RuntimeException $e) { same('myphp: invalid name in a file watch notice', $e->getMessage(), 'a name out of the tree ends the watch'); }
        $w->close();
    } finally {
        FakeFileWatch::$subtree = false;
    }
})();

(function () {
    // A rename that also wrote.
    $dir = watchDir();
    file_put_contents("$dir/a", 'x');
    $old = ['a' => \MyPHP\Native\FileWatch\Fs::lstat("$dir/a")];
    rename("$dir/a", "$dir/b");
    $events = Scan::diff($old, ['b' => \MyPHP\Native\FileWatch\Fs::lstat("$dir/b")], ['a' => true]);
    same(watchJson([ev(FileOp::FileRename, 'b', false, 'a'), ev(FileOp::FileWrite, 'b')]), watchJson($events), 'a rename, then a write');
})();

(function () {
    // A file replaced at its path by another, as an atomic save renames a temporary file over it: a write (mygo's c0c4c42).
    $dir = watchDir();
    file_put_contents("$dir/doc", 'old');
    $old = ['doc' => \MyPHP\Native\FileWatch\Fs::lstat("$dir/doc")];
    file_put_contents("$dir/.doc.tmp", 'new');
    rename("$dir/.doc.tmp", "$dir/doc");
    $events = Scan::diff($old, ['doc' => \MyPHP\Native\FileWatch\Fs::lstat("$dir/doc")], []);
    same(watchJson([ev(FileOp::FileWrite, 'doc')]), watchJson($events), 'replaced at its path: written, not removed and created');
})();

(function () use ($noDebounce) {
    // A watched file renamed away: the batch says what it was as the watch last saw it, the file a document follows (mygo's 924b727).
    $dir = watchDir();
    file_put_contents("$dir/doc", 'x');
    $st = stat("$dir/doc");
    $w = App::watchFiles("$dir/doc", $noDebounce());
    rename("$dir/doc", "$dir/moved");
    watchNotice($w, '..', new Notice(kind: Notice::REMOVE, name: 'doc'));
    $events = $w->next(2);
    check($events !== null && end($events)->op === FileOp::FileRemove, 'removed');
    same([$st['dev'], $st['ino'], $st['mode'] & 0o170000], $w->gone(), 'what it was, as the watch saw it');
    file_put_contents("$dir/doc", 'y');
    watchNotice($w, '..', new Notice(kind: Notice::CREATE, name: 'doc'));
    $w->next(2);
    same(null, $w->gone(), 'a batch that removes nothing: none');
    $w->close();
})();

(function () {
    // A directory of many files renamed pairs them by identity, not by comparing every entry with every other (mygo's d7b4d54); a hard link stays unpaired.
    $before = ['d' => [1, 1, 0o040755, 0, 0]]; $after = ['e' => [1, 1, 0o040755, 0, 0]];
    for ($i = 0; $i < 20000; $i++) { $before["d/$i"] = $after["e/$i"] = [1, 10 + $i, 0o100644, 1, 1]; }
    $t = hrtime(true);
    $events = Scan::diff($before, $after, []);
    $ms = (hrtime(true) - $t) / 1e6;
    check(count($events) === 1 && $events[0]->op === FileOp::FileRename && $events[0]->path === 'e' && $ms < 2000, sprintf('20,000 files renamed with their directory: the directory\'s rename, in %.0f ms', $ms));
    $linked = ['a' => [1, 5, 0o100644, 1, 1], 'b' => [1, 5, 0o100644, 1, 1]];
    same(watchJson([ev(FileOp::FileRemove, 'a'), ev(FileOp::FileCreate, 'c')]), watchJson(Scan::diff($linked, ['b' => $linked['b'], 'c' => $linked['a']], [])), 'a hard link: removed and created, not renamed');
})();

(function () {
    // A file system such as ext4 gives a freed inode to the next file created: a directory replaced by a symlink with its inode is a removal and a creation, not a write (mygo's TestFileWatchDiffReusedInode).
    $before = ['a' => [1, 2, 0o040700, 0, 0], 'a/f' => [1, 3, 0o100644, 0, 0]];
    $after = ['a' => [1, 2, 0o120777, 0, 0]];
    same(watchJson([ev(FileOp::FileRemove, 'a', true), ev(FileOp::FileCreate, 'a'), ev(FileOp::FileRemove, 'a/f')]), watchJson(Scan::diff($before, $after, [])), 'a directory, then a symlink with its inode');
})();

(function () use ($noDebounce) {
    // A file is watched through its directory: written, replaced as editors save, removed and created again, then gone with its directory.
    $dir = watchDir() . '/dir';
    mkdir($dir);
    $file = "$dir/config.json";
    file_put_contents($file, 'a');
    touch("$dir/sibling");
    $w = App::watchFiles($file, $noDebounce());
    $enrolled = watchTargets();
    sort($enrolled);
    same([$dir, $file], $enrolled, 'the file and its directory enrolled');
    $next = fn(FileEvent $want, string $what) => same(watchJson([$want]), watchJson($w->next(2)), $what);
    $quiet = fn(string $what) => same([], $w->next(0.03), $what);
    file_put_contents($file, 'bb');
    watchNotice($w, '.', new Notice(kind: Notice::WRITE));
    $next(ev(FileOp::FileWrite, '.'), 'written');
    // Other files of the directory are not reported.
    file_put_contents("$dir/sibling", 'x');
    watchNotice($w, '..', new Notice(kind: Notice::WRITE, name: 'sibling'));
    watchNotice($w, '..', new Notice);
    $quiet('a sibling');
    file_put_contents("$file.tmp", 'ccc');
    rename("$file.tmp", $file);
    watchNotice($w, '..', new Notice(kind: Notice::MOVE_TO, name: 'config.json'));
    $next(ev(FileOp::FileWrite, '.'), 'replaced, as editors save');
    unlink($file);
    watchNotice($w, '..', new Notice(kind: Notice::REMOVE, name: 'config.json'));
    $next(ev(FileOp::FileRemove, '.'), 'removed');
    touch($file);
    watchNotice($w, '..', new Notice(kind: Notice::CREATE, name: 'config.json'));
    $next(ev(FileOp::FileCreate, '.'), 'created again');
    check(in_array($file, watchTargets(), true), 'the new file is enrolled');
    exec('rm -rf ' . escapeshellarg($dir));
    watchNotice($w, '..', new Notice(kind: Notice::LOST));
    $next(ev(FileOp::FileRemove, '.'), 'gone with its directory');
    same([null, null], [$w->next(), $w->err()], 'then the end');
})();

(function () use ($noDebounce) {
    // What Ignore ignores is never enrolled, counted or reported.
    $dir = watchDir();
    foreach (['node_modules/a/b/c', 'node_modules/d', 'src/main.go', 'debug.log'] as $p) {
        @mkdir(dirname("$dir/$p"), 0777, true);
        touch("$dir/$p");
    }
    $asked = [];
    $ignore = function (string $p, bool $isDir) use (&$asked): bool {
        $asked[] = $p;
        return $p === 'node_modules' && $isDir || str_ends_with($p, '.log');
    };
    // Only the root, src, src/main.go and src/new.go, created below, count.
    $w = App::watchFiles($dir, $noDebounce(true, $ignore, 4));
    check(array_filter($asked, fn($p) => str_starts_with($p, 'node_modules/')) === [], 'never asked about what is in node_modules');
    check(array_filter(watchTargets(), fn($p) => str_contains($p, 'node_modules') || str_ends_with($p, '.log')) === [], 'nothing ignored enrolled');
    touch("$dir/node_modules/e");
    touch("$dir/other.log");
    watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'other.log'));
    watchNotice($w, '.', new Notice(kind: Notice::WRITE, name: 'node_modules'));
    touch("$dir/src/new.go");
    watchNotice($w, 'src', new Notice(kind: Notice::CREATE, name: 'new.go'));
    same(watchJson([ev(FileOp::FileCreate, 'src/new.go')]), watchJson($w->next(2)), 'only what is not ignored');
    $w->close();
})();

(function () use ($noDebounce) {
    // Notices in an ignored directory, which a subtree target reports, are dropped.
    FakeFileWatch::$subtree = true;
    try {
        $dir = watchDir();
        mkdir("$dir/.git/objects", 0777, true);
        $w = App::watchFiles($dir, $noDebounce(true, fn(string $p) => $p === '.git'));
        touch("$dir/.git/objects/x");
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: '.git/objects/x'));
        touch("$dir/y");
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'y'));
        same(watchJson([ev(FileOp::FileCreate, 'y')]), watchJson($w->next(2)), 'an ignored subtree');
        $w->close();
    } finally {
        FakeFileWatch::$subtree = false;
    }
})();

(function () use ($noDebounce) {
    // Changes of permissions alone are not writes.
    $dir = watchDir();
    file_put_contents("$dir/a", 'x');
    $w = App::watchFiles($dir, $noDebounce());
    chmod("$dir/a", 0400);
    watchNotice($w, 'a', new Notice);
    same([], $w->next(0.03), 'metadata');
    watchNotice($w, 'a', new Notice(kind: Notice::WRITE));
    same(watchJson([ev(FileOp::FileWrite, 'a')]), watchJson($w->next(2)), 'a write the system reports');
    $w->close();
})();

(function () use ($noDebounce) {
    // What the watch can't look at is skipped rather than ending the watch.
    $dir = watchDir();
    touch("$dir/busy");
    FakeFileWatch::$addHook = function (Target $t): void {
        if (basename($t->path) === 'busy') { throw new FsError("open {$t->path}: permission denied", 13); }
    };
    try {
        $w = App::watchFiles($dir, $noDebounce(true));
        check(!in_array("$dir/busy", watchTargets(), true), 'busy not enrolled');
        touch("$dir/x");
        watchNotice($w, '.', new Notice(kind: Notice::CREATE, name: 'x'));
        watchNotice($w, '.', new Notice);
        same(watchJson([ev(FileOp::FileCreate, 'x')]), watchJson($w->next(2)), 'the watch goes on');
        $w->close();
    } finally {
        FakeFileWatch::$addHook = null;
    }
})();

Backends::$fake = null;

(function () {
    // The system's watch, on a real directory.
    $dir = watchDir();
    mkdir("$dir/sub/deep", 0777, true);
    file_put_contents("$dir/a", 'x');
    $w = App::watchFiles($dir, new FileWatchOptions(recursive: true, debounce: 0.01));
    file_put_contents("$dir/b", 'y');
    file_put_contents("$dir/a", 'xx');
    touch("$dir/sub/deep/c");
    same(watchJson([ev(FileOp::FileWrite, 'a'), ev(FileOp::FileCreate, 'b'), ev(FileOp::FileCreate, 'sub/deep/c')]), watchJson($w->next(2)), 'system: created and written');
    rename("$dir/sub", "$dir/moved");
    same(watchJson([ev(FileOp::FileRename, 'moved', true, 'sub')]), watchJson($w->next(2)), 'system: a directory renamed');
    file_put_contents("$dir/moved/deep/c", 'z');
    same(watchJson([ev(FileOp::FileWrite, 'moved/deep/c')]), watchJson($w->next(2)), 'system: watched under its new path');
    unlink("$dir/b");
    same(watchJson([ev(FileOp::FileRemove, 'b')]), watchJson($w->next(2)), 'system: removed');
    same([], $w->next(0.05), 'system: quiet');
    exec('rm -rf ' . escapeshellarg($dir));
    same(watchJson([ev(FileOp::FileRemove, '.', true)]), watchJson($w->next(2)), 'system: the root gone');
    same([null, true], [$w->next(), $w->done()], 'system: then the end');

    // A file, saved as editors do.
    $dir = watchDir();
    $file = "$dir/settings.json";
    file_put_contents($file, '{}');
    $w = App::watchFiles($file, new FileWatchOptions(debounce: 0.01));
    touch("$dir/other");
    same([], $w->next(0.1), 'system: a sibling is not reported');
    file_put_contents("$file.tmp", '{"a":1}');
    rename("$file.tmp", $file);
    same(watchJson([ev(FileOp::FileWrite, '.')]), watchJson($w->next(2)), 'system: a file replaced is written');
    $w->close();
})();
