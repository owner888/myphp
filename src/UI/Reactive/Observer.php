<?php
declare(strict_types=1);

namespace MyPHP\UI\Reactive;

use Closure;
use PhpToken;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use WeakMap;
use WeakReference;

/**
 * Fine-grained reactive dependency tracker for MyPHP.
 *
 * Supports both:
 * 1. Zero-boilerplate plain PHP properties (`public int $count = 0;`) via
 *    cached closure token inspection + fine-grained property diffing.
 * 2. Explicit PHP 8.4+ Property Hooks (`Observer::get` / `Observer::set`)
 *    and `State<T>` values.
 *
 * Uses WeakMap<object, ...> and WeakReference<Scope> so neither state
 * objects nor view scopes ever form reference cycles (gc_collect_cycles() === 0).
 */
final class Observer
{
    /** @var ?WeakMap<object, array<string|int, array<int, WeakReference<Scope>>>> */
    private static ?WeakMap $deps = null;
    /** @var ?WeakMap<object, array<string, mixed>> plain objects watched for async/event mutations */
    private static ?WeakMap $plainObjects = null;
    private static ?Scope $active = null;
    /** @var list<?Scope> */
    private static array $stack = [];
    private static bool $untracked = false;
    private static int $batchDepth = 0;
    /** @var array<int, Scope> */
    private static array $batched = [];

    /** @var array<string, list<PhpToken>> cached tokens by file path */
    private static array $fileTokens = [];
    /** @var array<string, list<array{props: list<array{0: string, 1: string}>, methods: list<array{0: string, 1: string}>, bare: array<string, true>}>> */
    private static array $closureCache = [];
    /** @var array<string, int> cursor for disambiguating multiple closures on the same line */
    private static array $lineCursor = [];
    /** @var array<string, list<string>> cached property names read by Class::method */
    private static array $methodCache = [];

    private const EVENT_MODIFIERS = [
        'onTapGesture' => true, 'onAppear' => true, 'onDisappear' => true, 'task' => true,
        'onChange' => true, 'onHover' => true, 'onSubmit' => true, 'onKeyPress' => true,
        'refreshable' => true, 'onMove' => true, 'onChanged' => true, 'onEnded' => true,
    ];

    /** Runs $fn within reactive $scope, recording both hooked and plain property dependencies. */
    public static function run(Scope $scope, Closure $fn, ?Closure $userClosure = null): mixed
    {
        $scope->setParent(self::$active);
        self::$stack[] = self::$active;
        self::$active = $scope;
        $scope->begin();
        if (!self::$untracked && $userClosure !== null) {
            self::bindClosure($scope, $userClosure);
        }
        try {
            return $fn();
        } finally {
            if (!self::$untracked) {
                $scope->snapshotPlain();
            }
            self::$active = array_pop(self::$stack);
        }
    }

    /** Binds a window's root view closure and optional state object to its root reactive scope. @internal */
    public static function bindRoot(Scope $scope, Closure $view, ?object $state = null): void
    {
        if (self::$untracked) {
            return;
        }
        self::bindClosure($scope, $view);
        if ($state !== null && !self::isEngineObject($state)) {
            $targets = $scope->targets();
            $seen = [];
            foreach ($targets as [$ref, $prop]) {
                if (($o = $ref->get()) !== null) {
                    $seen[spl_object_id($o) . ':' . $prop] = true;
                }
            }
            foreach (array_keys(get_object_vars($state)) as $prop) {
                self::addTarget($scope, $targets, $seen, $state, (string)$prop);
            }
            $scope->setTargets('root:' . spl_object_id($state), $targets);
        }
    }

    /** Runs $fn without recording any reactive dependencies. */
    public static function untrack(Closure $fn): mixed
    {
        $was = self::$untracked;
        self::$untracked = true;
        try {
            return $fn();
        } finally {
            self::$untracked = $was;
        }
    }

    /** Batches multiple state mutations inside $fn and invalidates affected scopes once at the end. */
    public static function batch(Closure $fn): mixed
    {
        self::$batchDepth++;
        try {
            return $fn();
        } finally {
            if (--self::$batchDepth === 0 && self::$batched !== []) {
                $pending = self::$batched;
                self::$batched = [];
                foreach ($pending as $scope) {
                    $scope->invalidate();
                }
            }
        }
    }

    /** Records that the currently building Scope depends on $target::$property. */
    public static function track(object $target, string|int $property): void
    {
        if (self::$active === null || self::$untracked) {
            return;
        }
        self::subscribe(self::$active, $target, $property);
    }

    private static function subscribe(Scope $scope, object $target, string|int $property): void
    {
        self::$deps ??= new WeakMap();
        $map = self::$deps[$target] ?? [];
        $id = spl_object_id($scope);
        if (!isset($map[$property][$id])) {
            $map[$property][$id] = WeakReference::create($scope);
            self::$deps[$target] = $map;
        }
    }

    /** Notifies all scopes subscribed to $target::$property that its value changed. */
    public static function trigger(object $target, string|int $property): void
    {
        if (self::$deps === null || !isset(self::$deps[$target])) {
            return;
        }
        $map = self::$deps[$target];
        if (!isset($map[$property])) {
            return;
        }
        $pruned = false;
        foreach ($map[$property] as $id => $ref) {
            $scope = $ref->get();
            if ($scope === null) {
                unset($map[$property][$id]);
                $pruned = true;
                continue;
            }
            if (self::$batchDepth > 0) {
                self::$batched[$id] = $scope;
            } else {
                $scope->invalidate();
            }
        }
        if ($pruned) {
            if ($map[$property] === []) {
                unset($map[$property]);
            }
            self::$deps[$target] = $map;
        }
    }

    /**
     * PHP 8.4 Property Hook helper for `get`:
     * `public int $count = 0 { get => Observer::get($this, 'count', $this->count); }`
     */
    public static function get(object $target, string|int $property, mixed $value): mixed
    {
        self::track($target, $property);
        return $value;
    }

    /**
     * PHP 8.4 Property Hook helper for `set`:
     * `public int $count = 0 { set => Observer::set($this, 'count', $this->count, $value); }`
     */
    public static function set(object $target, string|int $property, mixed $old, mixed $value): mixed
    {
        if ($old !== $value) {
            self::trigger($target, $property);
        }
        return $value;
    }

    /** Whether a reactive scope is currently active. */
    public static function isTracking(): bool
    {
        return self::$active !== null && !self::$untracked;
    }

    /** The currently active reactive scope, or null outside a reactive build. */
    public static function current(): ?Scope
    {
        return self::$active;
    }

    /** Value snapshot that also captures 1-level object properties inside arrays (e.g. list<Message>). @internal */
    public static function snapshot(mixed $val): mixed
    {
        if (is_array($val)) {
            $out = [];
            foreach ($val as $k => $v) {
                $out[$k] = is_object($v) ? [spl_object_id($v), get_object_vars($v)] : $v;
            }
            return $out;
        }
        return $val;
    }

    /** Registers a plain property snapshot for async/event diffing in sync(). @internal */
    public static function watchPlain(object $target, string $property, mixed $snapshot): void
    {
        self::$plainObjects ??= new WeakMap();
        $props = self::$plainObjects[$target] ?? [];
        $props[$property] = $snapshot;
        self::$plainObjects[$target] = $props;
    }

    /**
     * Diffs watched plain properties after async timers, Fibers, process or
     * menu callbacks and triggers only the specific properties that mutated.
     */
    public static function sync(): void
    {
        if (self::$plainObjects === null) {
            return;
        }
        foreach (self::$plainObjects as $obj => $props) {
            $changed = false;
            foreach ($props as $prop => $was) {
                $now = self::snapshot($obj->$prop ?? null);
                if ($now !== $was) {
                    $props[$prop] = $now;
                    $changed = true;
                    self::trigger($obj, $prop);
                }
            }
            if ($changed) {
                self::$plainObjects[$obj] = $props;
            }
        }
    }

    private static function bindClosure(Scope $scope, Closure $fn): void
    {
        $rf = new ReflectionFunction($fn);
        $file = (string)$rf->getFileName();
        $start = (int)$rf->getStartLine();
        $end = (int)$rf->getEndLine();
        $key = "$file:$start:$end";
        if ($scope->hasClosureKey($key)) {
            foreach ($scope->targets() as [$ref, $prop]) {
                if (($obj = $ref->get()) !== null) {
                    self::subscribe($scope, $obj, $prop);
                }
            }
            return;
        }
        $thisObj = $rf->getClosureThis();
        $used = $rf->getClosureUsedVariables();
        $vars = $used;
        if ($thisObj !== null) {
            $vars['this'] = $thisObj;
        }
        $accesses = self::parseClosureAt($file, $start, $end);
        $targets = [];
        $seen = [];
        $explicitVars = [];
        if ($accesses !== null) {
            foreach ($accesses['props'] as [$varName, $prop]) {
                $v = substr($varName, 1);
                $explicitVars[$v] = true;
                $obj = $vars[$v] ?? null;
                if (is_object($obj) && !self::isEngineObject($obj)) {
                    self::addTarget($scope, $targets, $seen, $obj, $prop);
                }
            }
            foreach ($accesses['methods'] as [$varName, $method]) {
                $v = substr($varName, 1);
                $explicitVars[$v] = true;
                $obj = $vars[$v] ?? null;
                if (is_object($obj) && !self::isEngineObject($obj) && method_exists($obj, $method)) {
                    foreach (self::parseMethodProps($obj, $method) as $prop) {
                        self::addTarget($scope, $targets, $seen, $obj, $prop);
                    }
                }
            }
            foreach ($accesses['bare'] as $varName => $_) {
                $v = substr($varName, 1);
                if (!isset($explicitVars[$v]) && isset($vars[$v]) && is_object($vars[$v]) && !self::isEngineObject($vars[$v])) {
                    foreach (array_keys(get_object_vars($vars[$v])) as $prop) {
                        self::addTarget($scope, $targets, $seen, $vars[$v], (string)$prop);
                    }
                }
            }
        } else {
            foreach ($vars as $obj) {
                if (is_object($obj) && !self::isEngineObject($obj)) {
                    foreach (array_keys(get_object_vars($obj)) as $prop) {
                        self::addTarget($scope, $targets, $seen, $obj, (string)$prop);
                    }
                }
            }
        }
        $scope->setTargets($key, $targets);
    }

    /**
     * @param list<array{0: WeakReference<object>, 1: string}> $targets
     * @param array<string, true> $seen
     */
    private static function addTarget(Scope $scope, array &$targets, array &$seen, object $obj, string $prop): void
    {
        $id = spl_object_id($obj) . ':' . $prop;
        if (isset($seen[$id])) {
            return;
        }
        if (!property_exists($obj, $prop) && !isset($obj->$prop)) {
            return;
        }
        if (property_exists($obj, $prop)) {
            $rp = new ReflectionProperty($obj, $prop);
            if (!$rp->isPublic()) {
                return;
            }
        }
        $seen[$id] = true;
        $targets[] = [WeakReference::create($obj), $prop];
        self::subscribe($scope, $obj, $prop);
    }

    private static function isEngineObject(object $obj): bool
    {
        if ($obj instanceof Closure || $obj instanceof State || $obj instanceof Scope) {
            return true;
        }
        $cls = $obj::class;
        return str_starts_with($cls, 'MyPHP\\UI\\') || str_starts_with($cls, 'MyPHP\\Platform\\') || $cls === 'MyPHP\\Window';
    }

    /** @return ?array{props: list<array{0: string, 1: string}>, methods: list<array{0: string, 1: string}>, bare: array<string, true>} */
    private static function parseClosureAt(string $file, int $start, int $end): ?array
    {
        if ($file === '' || str_contains($file, "eval()'d code") || str_contains($file, 'Command line code') || !is_file($file)) {
            return null;
        }
        $key = "$file:$start:$end";
        if (!isset(self::$closureCache[$key])) {
            $tokens = self::$fileTokens[$file] ??= array_values(array_filter(
                PhpToken::tokenize((string)file_get_contents($file)),
                static fn(PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
            ));
            $found = [];
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if ($t->line < $start) {
                    continue;
                }
                if ($t->line > $start) {
                    break;
                }
                if ($t->is(T_FN) || ($t->is(T_FUNCTION) && self::isAnonymousFunction($tokens, $i))) {
                    [$bodyStart, $bodyEnd, $endLine] = self::closureBounds($tokens, $i);
                    if ($bodyStart !== null && ($endLine === $end || $start === $end)) {
                        $found[] = self::scanRange($tokens, $bodyStart, $bodyEnd);
                    }
                }
            }
            self::$closureCache[$key] = $found;
        }
        $list = self::$closureCache[$key];
        if ($list === []) {
            return null;
        }
        if (count($list) === 1) {
            return $list[0];
        }
        $idx = (self::$lineCursor[$key] ?? 0) % count($list);
        self::$lineCursor[$key] = $idx + 1;
        return $list[$idx];
    }

    /** @return list<string> */
    private static function parseMethodProps(object $obj, string $method, array &$visited = []): array
    {
        $key = $obj::class . '::' . $method;
        if (isset($visited[$key])) {
            return [];
        }
        $visited[$key] = true;
        if (isset(self::$methodCache[$key])) {
            return self::$methodCache[$key];
        }
        $rm = new ReflectionMethod($obj, $method);
        $file = (string)$rm->getFileName();
        if ($file === '' || !is_file($file)) {
            return self::$methodCache[$key] = [];
        }
        $start = (int)$rm->getStartLine();
        $end = (int)$rm->getEndLine();
        $tokens = self::$fileTokens[$file] ??= array_values(array_filter(
            PhpToken::tokenize((string)file_get_contents($file)),
            static fn(PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $props = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->line < $start) { continue; }
            if ($t->line > $end) { break; }
            if ($t->is(T_VARIABLE) && $t->text === '$this' && isset($tokens[$i + 2])
                && $tokens[$i + 1]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                && $tokens[$i + 2]->is(T_STRING)) {
                $name = $tokens[$i + 2]->text;
                if (($tokens[$i + 3] ?? null)?->text === '(') {
                    if (($tokens[$i + 4] ?? null)?->is(T_ELLIPSIS) && ($tokens[$i + 5] ?? null)?->text === ')') {
                        continue;
                    }
                    if (method_exists($obj, $name)) {
                        foreach (self::parseMethodProps($obj, $name, $visited) as $mp) {
                            $props[$mp] = true;
                        }
                    }
                } else {
                    $props[$name] = true;
                }
            }
        }
        return self::$methodCache[$key] = array_keys($props);
    }

    /** @param list<PhpToken> $tokens */
    private static function isAnonymousFunction(array $tokens, int $i): bool
    {
        $next = $tokens[$i + 1] ?? null;
        if ($next?->text === '&') {
            $next = $tokens[$i + 2] ?? null;
        }
        return $next?->text === '(';
    }

    /**
     * Finds the body token range [$bodyStart, $bodyEnd, $endLine] of the closure at $i.
     * @param list<PhpToken> $tokens
     * @return array{0: ?int, 1: int, 2: int}
     */
    private static function closureBounds(array $tokens, int $i): array
    {
        $n = count($tokens);
        $isArrow = $tokens[$i]->is(T_FN);
        $j = $i + 1;
        if ($j < $n && $tokens[$j]->text === '&') { $j++; }
        if ($j >= $n || $tokens[$j]->text !== '(') {
            return [null, $i, $tokens[$i]->line];
        }
        for ($d = 0; $j < $n; $j++) {
            if ($tokens[$j]->text === '(') { $d++; }
            elseif ($tokens[$j]->text === ')' && --$d === 0) { $j++; break; }
        }
        if ($isArrow) {
            while ($j < $n && !$tokens[$j]->is(T_DOUBLE_ARROW)) { $j++; }
            if ($j >= $n) { return [null, $i, $tokens[$i]->line]; }
            $bodyStart = $j + 1;
            $d = 0;
            for ($k = $bodyStart; $k < $n; $k++) {
                $tk = $tokens[$k];
                if (in_array($tk->text, ['(', '['], true) || $tk->id === 123 || $tk->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $d++;
                } elseif (in_array($tk->text, [')', ']'], true) || $tk->id === 125) {
                    if ($d === 0) {
                        return [$bodyStart, $k - 1, $tokens[max($bodyStart, $k - 1)]->line];
                    }
                    $d--;
                } elseif (($tk->text === ',' || $tk->text === ';') && $d === 0) {
                    return [$bodyStart, $k - 1, $tokens[max($bodyStart, $k - 1)]->line];
                }
            }
            return [$bodyStart, $n - 1, $tokens[$n - 1]->line];
        }
        while ($j < $n && $tokens[$j]->id !== 123) { $j++; }
        if ($j >= $n) { return [null, $i, $tokens[$i]->line]; }
        $bodyStart = $j + 1;
        $d = 1;
        for ($k = $bodyStart; $k < $n; $k++) {
            $tk = $tokens[$k];
            if ($tk->id === 123 || $tk->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $d++;
            } elseif ($tk->id === 125 && --$d === 0) {
                return [$bodyStart, $k - 1, $tk->line];
            }
        }
        return [$bodyStart, $n - 1, $tokens[$n - 1]->line];
    }

    /**
     * Scans tokens[$from..$to], skipping nested 0-arg closures and event handler closures.
     * @param list<PhpToken> $tokens
     * @return array{props: list<array{0: string, 1: string}>, methods: list<array{0: string, 1: string}>, bare: array<string, true>}
     */
    private static function scanRange(array $tokens, int $from, int $to): array
    {
        $props = [];
        $methods = [];
        $bare = [];
        for ($i = $from; $i <= $to; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_FN) || ($t->is(T_FUNCTION) && self::isAnonymousFunction($tokens, $i))) {
                [, $nestedEnd] = self::closureBounds($tokens, $i);
                if (self::shouldSkipNestedClosure($tokens, $i)) {
                    $i = $nestedEnd;
                    continue;
                }
            }
            if ($t->is(T_VARIABLE)) {
                $var = $t->text;
                $op = $tokens[$i + 1] ?? null;
                $mem = $tokens[$i + 2] ?? null;
                if ($op !== null && $op->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $mem !== null && $mem->is(T_STRING)) {
                    $after = $tokens[$i + 3] ?? null;
                    if ($after?->text === '(') {
                        if (($tokens[$i + 4] ?? null)?->is(T_ELLIPSIS) && ($tokens[$i + 5] ?? null)?->text === ')') {
                            $i += 5;
                            continue;
                        }
                        $methods[] = [$var, $mem->text];
                    } else {
                        $props[] = [$var, $mem->text];
                    }
                    $i += 2;
                } else {
                    $bare[$var] = true;
                }
            }
        }
        return ['props' => $props, 'methods' => $methods, 'bare' => $bare];
    }

    /**
     * Whether nested closure at $i is a 0-arg child Computed closure or an event handler
     * (and therefore should not pollute the enclosing scope's direct dependencies).
     * @param list<PhpToken> $tokens
     */
    private static function shouldSkipNestedClosure(array $tokens, int $i): bool
    {
        $j = $i + 1;
        if (($tokens[$j] ?? null)?->text === '&') { $j++; }
        if (($tokens[$j] ?? null)?->text === '(' && ($tokens[$j + 1] ?? null)?->text === ')') {
            return true; // 0-arg closure: either a child Computed scope, an action callback, or a sheet/task
        }
        $prev1 = $tokens[$i - 1] ?? null;
        $prev2 = $tokens[$i - 2] ?? null;
        if ($prev1?->text === ':' && $prev2 !== null && in_array($prev2->text, ['action', 'onEditingChanged', 'onRelease', 'destination'], true)) {
            return true;
        }
        if ($prev1?->text === '(' && $prev2 !== null && isset(self::EVENT_MODIFIERS[$prev2->text])) {
            return true;
        }
        return false;
    }
}
