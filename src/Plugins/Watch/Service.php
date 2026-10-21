<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

use Closure;
use Generator;
use MyPHP\{App, CallContext, Channel, ChannelClosed, ErrWatchLimit, FileWatcher, FileWatchOptions};
use MyPHP\Native\FileWatch\{Fs, FsError, Unsupported};
use RuntimeException;
use Throwable;

/**
 * The watch plugin's service (mygo's watch service), bound as
 * "plugin:watch": watch() watches what a page asks for and streams
 * "ready" once everything is watched, then a part per batch of events.
 *
 * @internal
 */
final class Service
{
    private const ALIAS = '/^[a-z][a-z0-9-]{0,63}\z/';
    /** How soon a watch is looked at again while changes come, and at most while none do. */
    private const POLL_BUSY = 0.01, POLL_IDLE = 0.1;

    /** @var array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> what each root was at setup */
    private array $identities = [];
    /** @var array<int, CallContext> the running watches, by key, with their page: one counts against its page's quota while the page is there */
    private array $active = [];
    private int $nextActive = 0;
    /** Starts a watch; tests replace it (mygo's factory). @var Closure(string, FileWatchOptions): FileWatcher */
    private Closure $factory;

    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts = clone $opts; // the app's own stay as they are
        if ($opts->maxWatchesPerPage === 0) { $this->opts->maxWatchesPerPage = 8; }
        if ($opts->maxWatches === 0) { $this->opts->maxWatches = 128; }
        $this->factory = fn(string $path, FileWatchOptions $o): FileWatcher => App::watchFiles($path, $o);
    }

    /** Checks the options, and resolves the roots (App::use runs it). */
    private function setup(): void
    {
        if ($this->opts->maxWatchesPerPage < 0 || $this->opts->maxWatches < 0) {
            throw new RuntimeException('watch: negative quota');
        }
        foreach ($this->opts->roots as $name => $root) {
            $name = (string)$name;
            if (preg_match(self::ALIAS, $name) !== 1) {
                throw new RuntimeException("watch: invalid root alias \"$name\"");
            }
            try {
                [$root, $info] = self::canonical($root);
            } catch (Throwable $e) {
                throw new RuntimeException("watch: root \"$name\": {$e->getMessage()}", 0, $e);
            }
            $this->opts->roots[$name] = $root;
            $this->identities[$name] = $info;
        }
    }

    /** $root with its path's symlinks resolved, and what it is. @return array{0: Root, 1: array{0: int, 1: int, 2: int, 3: int, 4: int}} */
    private static function canonical(Root $root): array
    {
        if (!str_starts_with($root->path, '/')) {
            throw new RuntimeException('root must be absolute');
        }
        $root = new Root(Fs::realpath($root->path), $root->recursive, $root->ignore);
        return [$root, self::validateRoot($root->path)];
    }

    /** Checks that $path is a file or a directory in ordinary directories, none a symlink, and returns what it is. @return array{0: int, 1: int, 2: int, 3: int, 4: int} */
    private static function validateRoot(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException('root must be absolute');
        }
        for ($p = $path; ; $p = dirname($p)) {
            $info = Fs::lstat($p);
            if (!Fs::isDir($info) && ($p !== $path || !Fs::isRegular($info))) {
                throw new RuntimeException('root components must be ordinary directories');
            }
            if (dirname($p) === $p) {
                break;
            }
        }
        return Fs::lstat($path);
    }

    /** Whether $p is a path relative to a root, separated by slashes, that stays in it. */
    private static function validPath(string $p): bool
    {
        if ($p === '.') {
            return true;
        }
        if (!mb_check_encoding($p, 'UTF-8') || str_contains($p, '\\') || str_contains($p, "\0")) {
            return false;
        }
        foreach (explode('/', $p) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    /** What $p names in $root, through its directories without following symlinks: its path and whether it is a directory. @return array{0: string, 1: bool} */
    private static function resolve(Root $root, string $p): array
    {
        if ($p === '.') {
            return [$root->path, Fs::isDir(Fs::lstat($root->path))];
        }
        $parts = explode('/', $p);
        foreach ($parts as $i => $_) {
            $info = Fs::lstat($root->path . '/' . implode('/', array_slice($parts, 0, $i + 1)));
            if (!Fs::isDir($info) && ($i < count($parts) - 1 || !Fs::isRegular($info))) {
                throw new Failure(Failure::DENIED);
            }
        }
        return [$root->path . '/' . $p, Fs::isDir($info)];
    }

    /** The code of what failed. */
    private static function codeFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof Failure => $e->failure,
            FsError::is($e, ErrWatchLimit::class) => Failure::LIMIT,
            FsError::is($e, Unsupported::class) => Failure::UNSUPPORTED,
            FsError::notExist($e) => Failure::NOT_FOUND,
            default => Failure::IO,
        };
    }

    /**
     * Watches what the request names for the calling page, streaming
     * {type: "ready"} once everything is watched, then {type: "events",
     * events} per batch, until the page closes $parts or goes away, or the
     * watch ends. It returns what failed, if anything: {code, message}.
     *
     * @param array{root?: string, path?: string, recursive?: bool, debounceMs?: int} $req
     * @param Channel $parts
     */
    public function watch(CallContext $ctx, array $req, Channel $parts): Generator
    {
        return yield from $this->run($ctx, $req);
    }

    /** The parts, with waits between them (Channel::wait); returns the outcome. @param array<string, mixed> $req */
    private function run(CallContext $ctx, array $req): Generator
    {
        try {
            yield from $this->stream($ctx, $req);
        } catch (ChannelClosed) {
            // the page stopped the watch
        } catch (Throwable $e) {
            if (!$ctx->done()) {
                $code = self::codeFor($e);
                return (object)['code' => $code, 'message' => (new Failure($code))->getMessage()];
            }
        }
        return (object)[];
    }

    /** The root named $name, which it checks is still what it was. */
    private function root(CallContext $ctx, string $name): Root
    {
        if (preg_match(self::ALIAS, $name) !== 1) {
            throw new Failure(Failure::DENIED);
        }
        $root = $this->opts->roots[$name] ?? null;
        if ($root !== null) {
            $info = self::validateRoot($root->path);
            // A directory replaced since setup is another one; a file is replaced whenever an editor saves it.
            $known = $this->identities[$name];
            if (Fs::isDir($known) && !Fs::same($info, $known)) {
                throw new Failure(Failure::IO);
            }
            return $root;
        }
        $root = $this->opts->resolve === null ? null : ($this->opts->resolve)($ctx, $name);
        if ($root === null) {
            throw new Failure(Failure::DENIED);
        }
        return self::canonical($root)[0];
    }

    /** @param array<string, mixed> $req */
    private function stream(CallContext $ctx, array $req): Generator
    {
        $path = (string)($req['path'] ?? '');
        if ($path === '') {
            $path = '.';
        }
        $name = (string)($req['root'] ?? '');
        $recursive = (bool)($req['recursive'] ?? false);
        $debounceMs = $req['debounceMs'] ?? 0;
        if (!is_int($debounceMs)) {
            throw new Failure(Failure::DENIED);
        }
        if (!self::validPath($path) || preg_match(self::ALIAS, $name) !== 1) {
            throw new Failure(Failure::DENIED);
        }
        if ($debounceMs > 60000) {
            throw new Failure(Failure::LIMIT);
        }
        $root = $this->root($ctx, $name);
        [$target, $isDir] = self::resolve($root, $path);
        // A root that isn't recursive lets pages watch itself and the files in it, not the directories.
        if ($recursive && !$root->recursive || !$root->recursive && $path !== '.' && ($isDir || str_contains($path, '/'))) {
            throw new Failure(Failure::DENIED);
        }
        if ($this->opts->allow !== null && !($this->opts->allow)($ctx, new Request($name, $path, $recursive))) {
            throw new Failure(Failure::DENIED);
        }
        if ($ctx->done()) {
            return;
        }
        $count = 0;
        foreach ($this->active as $page) {
            if ($page === $ctx && !$page->done()) {
                $count++;
            }
        }
        if (count($this->active) >= $this->opts->maxWatches || $count >= $this->opts->maxWatchesPerPage) {
            throw new Failure(Failure::LIMIT);
        }
        $key = $this->nextActive++;
        $this->active[$key] = $ctx;
        try {
            $ignore = $root->ignore;
            $opts = new FileWatchOptions(
                recursive: $recursive,
                ignore: $ignore === null ? null : fn(string $p, bool $dir): bool => $ignore($path === '.' ? $p : "$path/$p", $dir), // relative to the root
                debounce: $debounceMs / 1000,
            );
            $w = ($this->factory)($target, $opts);
            try {
                if ($w->root() !== $target) {
                    throw new Failure(Failure::DENIED); // a symlink appeared since
                }
                $stop = $ctx->afterFunc(fn() => $w->close());
                try {
                    yield ['type' => 'ready'];
                    $wait = self::POLL_BUSY;
                    while (($batch = $w->next(0)) !== null) { // null: closed, or the root went away
                        if ($batch === []) {
                            yield Channel::wait($wait);
                            $wait = min($wait * 2, self::POLL_IDLE);
                            continue;
                        }
                        foreach ($batch as $e) {
                            if (!mb_check_encoding($e->path, 'UTF-8') || !mb_check_encoding($e->oldPath, 'UTF-8')) {
                                throw new Failure(Failure::ENCODING);
                            }
                        }
                        yield ['type' => 'events', 'events' => $batch];
                        $wait = self::POLL_BUSY;
                    }
                } finally {
                    $stop();
                }
            } finally {
                $w->close();
            }
        } finally {
            unset($this->active[$key]);
        }
    }
}
