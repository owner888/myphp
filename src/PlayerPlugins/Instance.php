<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\App;
use MyPHP\Logger;
use MyPHP\UI\MediaPlayerState;
use Throwable;

/**
 * A plugin running, as IINA's JavascriptPluginInstance: its entry for a
 * player ($player), or its globalEntry for the app (none); the entry a
 * PHP file returning a function of the Iina it is given, run once the
 * instance is made. What it does runs as its own: what it throws logged
 * under its subsystem ("player0 - Name", "global - Name", IINA's), its
 * timers, menu items, event listeners and players made gone with it.
 */
final class Instance
{
    public readonly Iina $iina;
    public readonly bool $isGlobal;
    public readonly string $subsystem;
    /** @var list<PluginMenuItem> the items of its menu (IINA's menuItems) */
    public array $menuItems = [];
    /** @var array<string, bool> its timers by id, false once cleared */
    private array $timers = [];
    private bool $gone = false;
    /** @var list<Closure(): void> what to undo as it goes */
    private array $cleanUps = [];
    /** Told what it logs, and its level (IINA's logHandler: its developer tool's). @var ?Closure(string, int): void */
    public ?Closure $logHandler = null;
    /** Told what its code throws (its developer tool's, IINA's exceptionHandler). @var ?Closure(Throwable): void */
    public ?Closure $exceptionHandler = null;

    public function __construct(public readonly Plugin $plugin, public readonly ?MediaPlayerState $player)
    {
        $this->isGlobal = $player === null;
        $this->subsystem = ($player === null ? 'global' : $player->logName()) . " - {$plugin->name}";
        $this->iina = new Iina($this);
        $this->evaluate($player === null ? (string)$plugin->globalEntryURL : $plugin->entryURL);
    }

    /** Runs entry file $file: the function it returns called with the Iina; what is wrong logged. */
    private function evaluate(string $file): void
    {
        $this->call(function () use ($file): void {
            $fn = (static fn(string $f): mixed => include $f)($file);
            if (!$fn instanceof Closure) {
                throw new PluginError("The entry file " . basename($file) . " should return a function of an Iina");
            }
            $fn($this->iina);
        });
    }

    /**
     * Calls $fn of the plugin's with $args: in a fiber of its own where the
     * app runs (its requests wait without holding the app up, as IINA's
     * promises), what it throws logged as IINA logs an exception of a
     * plugin's (its message and its trace). Its result where it was called
     * at once (no app running, or already in a fiber), else null.
     */
    public function call(Closure $fn, mixed ...$args): mixed
    {
        if ($this->gone) { return null; }
        $run = function () use ($fn, $args): mixed {
            try {
                return $fn(...$args);
            } catch (Throwable $e) {
                $this->thrown($e);
                return null;
            }
        };
        if (App::running() && \Fiber::getCurrent() === null) {
            App::go(function () use ($run): void { $run(); });
            return null;
        }
        return $run();
    }

    /** Calls $fn of the plugin's with $args at once, what it returns returned (an input listener's, which says whether it took the input); what it throws logged, null. */
    public function callNow(Closure $fn, mixed ...$args): mixed
    {
        if ($this->gone) { return null; }
        try {
            return $fn(...$args);
        } catch (Throwable $e) {
            $this->thrown($e);
            return null;
        }
    }

    /** What its code threw: logged as IINA logs an exception of a plugin's (its message and its trace), its developer tool told. */
    private function thrown(Throwable $e): void
    {
        if ($this->exceptionHandler !== null) { ($this->exceptionHandler)($e); }
        Logger::log(Logger::ERROR, "{$e->getMessage()}\n---Stack Trace---\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n-----------------", $this->subsystem);
    }

    /** Logs $message under its subsystem. */
    public function log(string $message, int $level = Logger::DEBUG): void
    {
        Logger::log($level, $message, $this->subsystem);
        if ($this->logHandler !== null) { ($this->logHandler)($message, $level); }
    }

    /** Has $fn run as it goes (an API's cleanUp). */
    public function onCleanUp(Closure $fn): void
    {
        $this->cleanUps[] = $fn;
    }

    /** A timer of $fn after $ms milliseconds, again every $ms where $repeats (setTimeout, setInterval): its id. */
    public function timer(Closure $fn, float $ms, bool $repeats): string
    {
        $id = bin2hex(random_bytes(8));
        $this->timers[$id] = true;
        $fire = function () use ($id, $fn, $ms, $repeats, &$fire): void {
            if (!($this->timers[$id] ?? false) || $this->gone) { return; }
            if ($repeats) { App::after($ms / 1000, $fire); } else { unset($this->timers[$id]); }
            $this->call($fn);
        };
        App::after(max(0.0, $ms) / 1000, $fire);
        return $id;
    }

    /** Clears timer $id. */
    public function clearTimer(string $id): void
    {
        unset($this->timers[$id]);
    }

    /** Whether it was unloaded. */
    public function gone(): bool
    {
        return $this->gone;
    }

    /** Unloads it (IINA's deinit): its timers, listeners and the rest of what its APIs did undone. */
    public function cleanUp(): void
    {
        if ($this->gone) { return; }
        $this->gone = true;
        $this->timers = [];
        foreach (array_reverse($this->cleanUps) as $fn) {
            try { $fn(); } catch (Throwable $e) { $this->log($e->getMessage(), Logger::ERROR); }
        }
        $this->cleanUps = [];
        $this->menuItems = [];
        Logger::debug("Unload {$this->plugin->name}", $this->subsystem);
    }
}
