<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\App;
use MyPHP\Logger;
use Throwable;

/**
 * IINA's developer tool of a plugin's instance (JavascriptDevTool), of
 * PHP: code run in the instance, $iina its Iina, the variables it sets
 * kept for the code run after it; an expression's value, else what the
 * statements return, shown; what it throws shown as an exception; the
 * instance's log and what its code throws meanwhile shown as they come.
 * One for each instance (for()), its entries numbered as IINA's.
 */
final class DevTool
{
    /** @var list<array{id: int, index: int, kind: string, text?: string, value?: mixed, level?: int, message?: string, stack?: string}> prompts, results, logs and exceptions, in order */
    public array $entries = [];
    public int $counter = 0;
    private int $ids = 0;
    /** @var array<string, mixed> the variables its code set */
    private array $vars = [];
    /** Told as an entry is added (the window shows it). */
    public ?\Closure $changed = null;
    /** @var \WeakMap<Instance, DevTool>|null */
    private static ?\WeakMap $tools = null;

    private function __construct(public readonly Instance $instance)
    {
        $instance->logHandler = function (string $message, int $level): void { $this->add(['kind' => 'log', 'message' => $message, 'level' => $level]); };
        $instance->exceptionHandler = function (Throwable $e): void { $this->add(['kind' => 'exception', 'message' => $e->getMessage(), 'stack' => self::stack($e)]); };
    }

    /** The developer tool of $instance, made once (IINA's windows[ctx]). */
    public static function for(Instance $instance): self
    {
        self::$tools ??= new \WeakMap();
        return self::$tools[$instance] ??= new self($instance);
    }

    /**
     * Runs $source in the instance (IINA's executePrompt): an expression's
     * value, else the statements', where the app runs in a fiber of its
     * own (a request it makes waits); "$global" shows what is defined:
     * its variables and $iina.
     */
    public function run(string $source): void
    {
        $source = trim($source);
        if ($source === '') { return; }
        $this->counter++;
        $this->add(['kind' => 'prompt', 'text' => $source]);
        if ($source === '$global') {
            $this->add(['kind' => 'result', 'value' => ['iina' => $this->instance->iina] + $this->vars]);
            return;
        }
        App::go(function () use ($source): void {
            try {
                $fn = self::compile($source);
                $value = $fn($this->vars, $this->instance->iina);
                $this->add(['kind' => 'result', 'value' => $value]);
            } catch (Throwable $e) {
                $this->add(['kind' => 'exception', 'message' => $e->getMessage(), 'stack' => self::stack($e)]);
            }
        });
    }

    /** Empties it (IINA's Clear history). */
    public function clear(): void
    {
        $this->entries = [];
        if ($this->changed !== null) { ($this->changed)(); }
    }

    /** The prompts run, the last first (what the up arrow recalls). @return list<string> */
    public function history(): array
    {
        return array_reverse(array_values(array_map(fn(array $e) => (string)$e['text'], array_filter($this->entries, fn(array $e) => $e['kind'] === 'prompt'))));
    }

    /** $source as a function of the variables kept and the Iina: an expression returning its value, else statements. */
    private static function compile(string $source): \Closure
    {
        $body = rtrim($source, "; \t\n");
        foreach (["return ($body);", "$source\n;"] as $code) {
            try {
                return eval('return static function (array &$__vars, $iina) { extract($__vars, EXTR_REFS); try { ' . $code . ' } finally { $__vars = array_diff_key(get_defined_vars(), [\'__vars\' => true, \'iina\' => true]); } };');
            } catch (\ParseError $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /** @param array<string, mixed> $entry */
    private function add(array $entry): void
    {
        $this->entries[] = ['id' => ++$this->ids, 'index' => $this->counter] + $entry;
        if ($this->changed !== null) { ($this->changed)(); }
    }

    private static function stack(Throwable $e): string
    {
        return "{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}";
    }

    /**
     * $value as IINA's console shows a value: [the kind ('number',
     * 'string', 'boolean', 'null', 'array', 'object'), the text, its
     * members [key, value as text] for an array or object].
     * @return array{0: string, 1: string, 2: list<array{0: string, 1: string}>}
     */
    public static function describe(mixed $value): array
    {
        return match (true) {
            is_int($value), is_float($value) => ['number', (string)$value, []],
            is_string($value) => ['string', $value, []],
            is_bool($value) => ['boolean', $value ? 'true' : 'false', []],
            $value === null => ['null', 'null', []],
            is_array($value) => ['array', 'Array (' . count($value) . ')', array_map(fn($k) => [(string)$k, self::short($value[$k])], array_keys($value))],
            default => ['object', $value::class, array_map(fn($k) => [(string)$k, self::short($value->$k ?? null)], array_keys(get_object_vars($value)))],
        };
    }

    /** $value in one line. */
    private static function short(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'Array (' . count($value) . ')',
            is_object($value) => $value::class,
            is_string($value) => $value,
            default => var_export($value, true),
        };
    }

    /** The log level's color of IINA's (error and fault red, debug and verbose gray, warning orange), else none (info). */
    public static function levelColor(int $level): ?int
    {
        return match ($level) { Logger::ERROR, Logger::FAULT => 0xff3b30, Logger::WARNING => 0xff9500, Logger::DEBUG, Logger::VERBOSE => 0x8e8e93, default => null };
    }
}
