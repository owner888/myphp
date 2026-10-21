<?php
// The watch plugin (mygo's plugins/watch watch_test.go): what pages may
// watch, by the roots' names, the parts streamed, failures as codes that
// never say a path, and the quotas.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{CallContext, ChannelWait, ErrWatchLimit, FileEvent, FileOp, FileWatchOptions};
use MyPHP\Plugins\Watch\{Options, Request, Root, Service, Watch};

/** A watcher whose batches the test gives (mygo's fakeWatcher). */
final class FakeWatcher
{
    public bool $closed = false;

    /** @param list<list<FileEvent>> $batches */
    public function __construct(public string $root, public array $batches = [], public ?\Throwable $err = null) {}

    public function next(float $timeout = INF): ?array
    {
        if ($this->batches !== []) { return array_shift($this->batches); }
        if ($this->err !== null) { throw $this->err; }
        return null;
    }

    public function close(): void { $this->closed = true; }

    public function root(): string { return $this->root; }
}

/** A temporary directory, its symlinks resolved, removed at the end. */
function pluginDir(): string
{
    $d = sys_get_temp_dir() . '/myphp-watch-plugin-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($d);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($d) . ' ' . escapeshellarg("$d-old")));
    return realpath($d);
}

/** The plugin's service, set up, with the root "project" unless $opts has roots, and its directory. @return array{0: Service, 1: string} */
function watchFixture(Options $opts = new Options): array
{
    $dir = pluginDir();
    if ($opts->roots === []) { $opts->roots = ['project' => new Root($dir)]; }
    $p = Watch::new($opts);
    ($p->setup)();
    return [$p->service, $dir];
}

/** Sets the service's factory. */
function watchFactory(Service $s, \Closure $f): void
{
    \Closure::bind(fn() => $this->factory = $f, $s, Service::class)();
}

/** A run of the service's watch, as Ipc pumps it. */
function watchRun(Service $s, CallContext $ctx, array $req): \Generator
{
    return \Closure::bind(fn() => $this->run($ctx, $req), $s, Service::class)();
}

/** The parts a watch sent, and as the last one its outcome when it failed, as an "error" part. @return list<array<string, mixed>> */
function watchCollect(Service $s, CallContext $ctx, array $req): array
{
    $gen = watchRun($s, $ctx, $req);
    $parts = [];
    foreach ($gen as $p) {
        if (!$p instanceof ChannelWait) { $parts[] = $p; }
    }
    $o = (array)$gen->getReturn();
    if (isset($o['code'])) { $parts[] = ['type' => 'error'] + $o; }
    return $parts;
}

/** The number of running watches. */
function watchActive(Service $s): int
{
    return count(\Closure::bind(fn() => $this->active, $s, Service::class)());
}

(function () {
    // The parts, and the cleanup.
    [$s, $dir] = watchFixture();
    same('watch', Watch::plugin()->name, 'the plugin is watch');
    $w = new FakeWatcher($dir, [[new FileEvent(FileOp::FileCreate, 'a'), new FileEvent(FileOp::FileWrite, 'b')]]);
    $asked = null;
    watchFactory($s, function (string $path, FileWatchOptions $o) use ($w, &$asked) { $asked = [$path, $o->debounce]; return $w; });
    $parts = watchCollect($s, new CallContext(null), ['root' => 'project', 'debounceMs' => -1]);
    same([$dir, -0.001], $asked, 'the watch asked of App::watchFiles');
    same('[{"type":"ready"},{"type":"events","events":[{"op":"create","path":"a","isDir":false},{"op":"write","path":"b","isDir":false}]}]', json_encode($parts), 'ready, then the events');
    check($w->closed, 'closed');
    same(0, watchActive($s), 'the quota let go of');

    // Failures say a code, never a path.
    watchFactory($s, fn() => throw new \RuntimeException('watch /secret/private: secret'));
    $parts = watchCollect($s, new CallContext(null), ['root' => 'project']);
    same([['type' => 'error', 'code' => 'io', 'message' => 'filesystem watch io']], $parts, 'an error is io, without its path');
    watchFactory($s, fn() => throw new ErrWatchLimit);
    same('limit', watchCollect($s, new CallContext(null), ['root' => 'project'])[0]['code'] ?? '', 'the entry limit');
})();

(function () {
    // Permissions and quotas.
    [$s, $dir] = watchFixture(new Options(maxWatchesPerPage: 1));
    mkdir("$dir/sub");
    touch("$dir/file");
    watchFactory($s, function () { check(false, 'an unauthorized watch'); return new FakeWatcher(''); });
    // A root without recursive lets pages watch it and its files, not its directories.
    $page = new CallContext(null);
    foreach ([['root' => 'project', 'recursive' => true], ['root' => 'project', 'path' => 'sub']] as $req) {
        same('denied', watchCollect($s, $page, $req)[0]['code'] ?? '', 'denied: ' . json_encode($req));
    }
    watchFactory($s, fn(string $path) => new FakeWatcher($path, [], new \RuntimeException('blocked')));
    // A watch that sent ready and waits counts.
    $running = watchRun($s, $page, ['root' => 'project', 'path' => 'file']);
    same(['type' => 'ready'], $running->current(), 'a watch running');
    same(1, watchActive($s), 'counted');
    same('limit', watchCollect($s, $page, ['root' => 'project'])[0]['code'] ?? '', 'the page\'s quota');
    // Another page has its own quota, and a page's watches whose page is gone no longer count, before they have stopped.
    same('ready', watchCollect($s, new CallContext(null), ['root' => 'project'])[0]['type'], 'another page');
    $page->cancel();
    same('ready', watchCollect($s, new CallContext(null), ['root' => 'project'])[0]['type'], 'a page gone counts no more');
    $running->next();
    check(!$running->valid() && (array)$running->getReturn() === [], 'a watch whose page went away ends without an error');
    same(0, watchActive($s), 'every quota let go of');
})();

(function () {
    // Paths in the root.
    [$s, $dir] = watchFixture();
    $set = fn(Root $r) => \Closure::bind(fn() => $this->opts->roots['project'] = $r, $s, Service::class)();
    $set(new Root($dir, true));
    mkdir("$dir/a/b/c", 0777, true);
    symlink("$dir/a", "$dir/link");
    $watched = [];
    watchFactory($s, function (string $path) use (&$watched) { $watched[] = $path; return new FakeWatcher($path); });
    foreach (['', '.', 'a', 'a/b/c'] as $p) {
        same('ready', watchCollect($s, new CallContext(null), ['root' => 'project', 'path' => $p, 'recursive' => true])[0]['type'] ?? '', "the path \"$p\"");
    }
    same([$dir, $dir, "$dir/a", "$dir/a/b/c"], $watched, 'what was watched');
    foreach (['..', '../x', '/etc', 'a/../..', 'a//b', './a', 'a\\b', 'link', 'link/b', 'missing', "a\0"] as $p) {
        $parts = watchCollect($s, new CallContext(null), ['root' => 'project', 'path' => $p]);
        check(($parts[0]['type'] ?? '') === 'error' && ($p !== 'missing' || $parts[0]['code'] === 'not-found'), 'refused: ' . json_encode($p) . ' ' . json_encode($parts));
    }
    // What Ignore is asked is relative to the root.
    $asked = '';
    $set(new Root($dir, true, function (string $p, bool $isDir) use (&$asked): bool { $asked = $p; return false; }));
    watchFactory($s, function (string $path, FileWatchOptions $o) { ($o->ignore)('c', true); return new FakeWatcher($path); });
    watchCollect($s, new CallContext(null), ['root' => 'project', 'path' => 'a/b']);
    same('a/b/c', $asked, 'Ignore relative to the root');
})();

(function () {
    // Allow gets the request, and Resolve grants roots at run time.
    $picked = pluginDir();
    mkdir("$picked/secret");
    $requests = [];
    [$s] = watchFixture(new Options(
        resolve: fn(CallContext $ctx, string $name) => $name === 'picked' ? new Root($picked, true) : null,
        allow: function (CallContext $ctx, Request $r) use (&$requests): bool { $requests[] = $r; return $r->path !== 'secret'; },
    ));
    watchFactory($s, fn(string $path) => new FakeWatcher($path));
    same('ready', watchCollect($s, new CallContext(null), ['root' => 'picked', 'recursive' => true])[0]['type'] ?? '', 'a resolved root');
    same('denied', watchCollect($s, new CallContext(null), ['root' => 'picked', 'path' => 'secret'])[0]['code'] ?? '', 'Allow refuses');
    same('denied', watchCollect($s, new CallContext(null), ['root' => 'other'])[0]['code'] ?? '', 'a name Resolve doesn\'t know');
    same([['picked', '.', true], ['picked', 'secret', false]], array_map(fn(Request $r) => [$r->root, $r->path, $r->recursive], $requests), 'what Allow got');
})();

(function () {
    // A root replaced since setup is not watched; unknown roots are denied.
    [$s, $dir] = watchFixture();
    rename($dir, "$dir-old");
    mkdir($dir);
    same('io', watchCollect($s, new CallContext(null), ['root' => 'project'])[0]['code'] ?? '', 'a replaced root');
    $p = Watch::plugin();
    ($p->setup)();
    foreach (['project', '../', '/tmp', 'C:\\secret', 'a:b', "project\n"] as $root) {
        $parts = watchCollect($p->service, new CallContext(null), ['root' => $root]);
        same([['type' => 'error', 'code' => 'denied', 'message' => 'filesystem watch denied']], $parts, 'an unknown root: ' . json_encode($root));
    }
})();

(function () {
    // Configuration: roots canonical, copied, and checked.
    $parent = pluginDir();
    mkdir("$parent/real");
    symlink("$parent/real", "$parent/alias");
    [$s] = watchFixture(new Options(roots: ['project' => new Root("$parent/alias")]));
    same("$parent/real", \Closure::bind(fn() => $this->opts->roots['project']->path, $s, Service::class)(), 'a root\'s symlinks resolved');
    touch("$parent/real/config.json");
    $opts = new Options(roots: ['project' => new Root("$parent/real"), 'config' => new Root("$parent/real/config.json")]);
    $p = Watch::new($opts);
    $opts->roots['project'] = new Root('relative');
    ($p->setup)();
    check(true, 'the options are the plugin\'s own');
    foreach ([new Options(roots: ['../' => new Root($parent)]), new Options(roots: ['project' => new Root('relative')]), new Options(maxWatchesPerPage: -1), new Options(maxWatches: -1)] as $bad) {
        try { (Watch::new($bad)->setup)(); check(false, 'accepted'); } catch (\RuntimeException $e) { check(true, "refused: {$e->getMessage()}"); }
    }
})();

(function () {
    // A file root replaced, as editors save, is still watched.
    $dir = pluginDir();
    $file = "$dir/settings.json";
    touch($file);
    [$s] = watchFixture(new Options(roots: ['settings' => new Root($file)]));
    watchFactory($s, fn(string $path) => new FakeWatcher($path));
    file_put_contents("$file.tmp", '{}');
    rename("$file.tmp", $file);
    same('ready', watchCollect($s, new CallContext(null), ['root' => 'settings'])[0]['type'] ?? '', 'a file root replaced');
    foreach ([['root' => 'settings', 'path' => 'x'], ['root' => 'settings', 'recursive' => true]] as $req) {
        same('error', watchCollect($s, new CallContext(null), $req)[0]['type'] ?? '', 'refused: ' . json_encode($req));
    }
})();

(function () {
    // The real thing, through App::watchFiles: a page's watch and its end with the page.
    [$s, $dir] = watchFixture();
    $page = new CallContext(null);
    $gen = watchRun($s, $page, ['root' => 'project', 'debounceMs' => -1]);
    same(['type' => 'ready'], $gen->current(), 'ready');
    touch("$dir/new");
    $events = null;
    for ($t = microtime(true); microtime(true) - $t < 2; ) {
        $gen->next();
        $p = $gen->current();
        if ($p instanceof ChannelWait) { usleep((int)($p->seconds * 1e6)); continue; }
        $events = $p;
        break;
    }
    same('{"type":"events","events":[{"op":"create","path":"new","isDir":false}]}', json_encode($events), 'a change streamed');
    $page->cancel();
    $gen->next();
    $gen->next();
    check(!$gen->valid(), 'the page gone ends it');
})();
