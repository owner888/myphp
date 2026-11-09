<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\{App, Logger, Window};
use PhpToken;

/**
 * A file that returns a view (`return function (Context $c, …): void
 * { … };`), loaded bound to a state: in it, and in the closures it makes,
 * `$this` is the state, private members and `self::` included. In
 * development (App::isDev) it loads again when `myphp run` or `myphp dev`
 * say it changed (a hot reload: r, or a save), the state kept. A file that
 * declares a named class, function, interface, trait or enum cannot be
 * loaded twice: its change is said in the log and waits for a restart (R);
 * a file that fails to load keeps the view it had, the error in the log.
 * Content::file() is a window's; a Router's table of pages takes them too.
 */
final class ViewFile
{
    /** The view as the file last loaded. */
    private Closure $loaded;

    /** @var \WeakMap<object, array<string, self>>|null the files loaded bound to a state, by path: gone with the state */
    private static ?\WeakMap $bound = null;
    /** @var array<string, self> the files loaded bound to nothing, by path */
    private static array $unbound = [];
    /** The hot reload's request (hotReloadFile()) as last seen: its time and size. */
    private static string $seen = '';
    private static bool $watching = false;

    private function __construct(public readonly string $path, private readonly ?object $state)
    {
        $this->loaded = $this->load();
    }

    /** The file at $path bound to $state, loaded the first time it is asked for, the same after. */
    public static function of(string $path, ?object $state = null): self
    {
        $real = realpath($path);
        if ($real === false) {
            throw new \InvalidArgumentException("myphp: no view file $path");
        }
        if ($state === null) {
            return self::$unbound[$real] ??= self::made($real, null);
        }
        self::$bound ??= new \WeakMap();
        $files = self::$bound[$state] ?? [];
        if (!isset($files[$real])) {
            $files[$real] = self::made($real, $state);
            self::$bound[$state] = $files;
        }
        return $files[$real];
    }

    private static function made(string $path, ?object $state): self
    {
        $file = new self($path, $state);
        self::watch();
        return $file;
    }

    /** @return list<self> the files loaded */
    private static function all(): array
    {
        $all = array_values(self::$unbound);
        foreach (self::$bound ?? [] as $files) {
            array_push($all, ...array_values($files));
        }
        return $all;
    }

    /** The view as the file last loaded. */
    public function view(): Closure
    {
        return $this->loaded;
    }

    /** The file's view, run in $state's scope. */
    private function load(): Closure
    {
        require_once __DIR__ . '/Declarative/functions.php'; // VStack, Text, Button…: global, as SwiftUI's need no import
        $path = $this->path;
        $require = static fn(): mixed => require $path;
        if ($this->state !== null) {
            $scope = (new \ReflectionClass($this->state))->isInternal() ? null : $this->state::class; // a stdClass (object) [...]: $this alone, as PHP binds no closure to an internal class's scope
            $require = Closure::bind(function () use ($path): mixed { return require $path; }, $this->state, $scope);
        }
        $view = $require();
        if (!$view instanceof Closure) {
            throw new \UnexpectedValueException("myphp: $path returns " . get_debug_type($view) . ', not a view: return function (Context $c): void { … };');
        }
        return $view;
    }

    /** Loads the file again (a hot reload); its error, or what it declares, said, keeps the view it had. Whether it loaded. */
    private function reload(): bool
    {
        $declared = self::declared((string)file_get_contents($this->path));
        if ($declared !== []) {
            Logger::warn(basename($this->path) . ' declares ' . implode(', ', $declared) . ': PHP cannot load it twice, so the change waits for a restart (R); keep the view a file of closures', 'ui');
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
        try {
            $this->loaded = $this->load();
            return true;
        } catch (\Throwable $e) {
            Logger::error(basename($this->path) . ' did not load, the view stays as it was: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'ui');
            return false;
        }
    }

    /**
     * The named classes, interfaces, traits, enums and functions $code
     * declares at its top level, which a second require would declare again
     * (anonymous classes and closures are not). @internal
     * @return list<string>
     */
    public static function declared(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn(PhpToken $t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $out = [];
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($t->id === 123 || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) { $depth++; continue; } // {, not a { inside a string
            if ($t->id === 125) { $depth--; continue; }
            if ($depth > 0 || !$t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION])) { continue; }
            $next = $tokens[$i + 1] ?? null;
            if ($t->is(T_FUNCTION) && $next?->text === '&') { $next = $tokens[$i + 2] ?? null; }
            if ($next !== null && $next->is(T_STRING) && ($tokens[$i - 1] ?? null)?->is([T_NEW, T_DOUBLE_COLON]) !== true) {
                $out[] = strtolower($t->text) . ' ' . $next->text;
            }
        }
        return $out;
    }

    /**
     * The file through which `myphp run` and `myphp dev` ask for a hot
     * reload: MYPHP_HOT_RELOAD (which `myphp dev` sets, and the phones'
     * launchers, beside app.php), or .myphp-reload beside the script PHP
     * runs. Its lines are the changed files, absolute or from its own
     * directory, or * for every view of a file; a line of # a comment, as
     * the time of the request. @internal
     */
    public static function hotReloadFile(): string
    {
        $env = (string)getenv('MYPHP_HOT_RELOAD');
        return $env !== '' ? $env : dirname((string)(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: getcwd() . '/.')) . '/.myphp-reload';
    }

    /** The directory the hot reload's relative lines start from: its file's. */
    private static function root(): string
    {
        return dirname(self::hotReloadFile());
    }

    /** In development, looks at the hot reload's file three times a second, from when the app is ready. */
    private static function watch(): void
    {
        if (self::$watching || !App::isDev()) {
            return;
        }
        self::$watching = true;
        self::$seen = self::stamp();
        $tick = null;
        $tick = static function () use (&$tick): void {
            self::check();
            App::after(0.3, $tick);
        };
        App::whenReady(static fn() => App::after(0.3, $tick));
    }

    private static function stamp(): string
    {
        clearstatcache(true, self::hotReloadFile());
        $f = self::hotReloadFile();
        return is_file($f) ? filemtime($f) . ':' . filesize($f) . ':' . md5((string)file_get_contents($f)) : '';
    }

    /** Loads again the views of the files the hot reload's file names, if it changed, and draws the windows; says which other files changed, which a restart takes. @internal */
    public static function check(): void
    {
        $stamp = self::stamp();
        if ($stamp === self::$seen || $stamp === '') {
            return;
        }
        self::$seen = $stamp;
        $root = self::root();
        $changed = [];
        $all = false;
        foreach (file(self::hotReloadFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#') { continue; } // a comment: the request's time, which tells one request from the next of the same files
            if ($line === '*') { $all = true; continue; } // r with nothing changed: every view of a file
            $p = \MyPHP\Native\Paths::absolute($line, $root);
            $changed[realpath($p) ?: $p] = $line;
        }
        $loaded = [];
        $views = [];
        foreach (self::all() as $file) {
            $views[$file->path] = true;
            if (($all || isset($changed[$file->path])) && $file->reload()) {
                $loaded[$file->path] = true;
            }
        }
        $others = array_values(array_diff_key($changed, $views));
        if ($loaded !== []) {
            Logger::info('hot reload: ' . implode(', ', array_map('basename', array_keys($loaded))), 'ui');
            foreach (Window::all() as $w) { $w->invalidate(); }
        }
        if ($others !== []) {
            Logger::warn('changed outside the views of files (ViewFile): ' . implode(', ', array_slice($others, 0, 3)) . (count($others) > 3 ? ' and ' . (count($others) - 3) . ' more' : '') . '; a restart (R) takes them', 'ui');
        }
    }
}
