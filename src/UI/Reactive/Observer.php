<?php
declare(strict_types=1);

namespace MyPHP\UI\Reactive;

use Closure;
use WeakMap;
use WeakReference;

/**
 * Fine-grained reactive dependency tracker for PHP 8.4+ Property Hooks and
 * State<T> values.
 *
 * Uses WeakMap<object, ...> and WeakReference<Scope> so neither state
 * objects nor view scopes ever form reference cycles (gc_collect_cycles() === 0).
 */
final class Observer
{
    /** @var ?WeakMap<object, array<string|int, array<int, WeakReference<Scope>>>> */
    private static ?WeakMap $deps = null;
    private static ?Scope $active = null;
    /** @var list<?Scope> */
    private static array $stack = [];
    private static bool $untracked = false;
    private static int $batchDepth = 0;
    /** @var array<int, Scope> */
    private static array $batched = [];

    /** Runs $fn within reactive $scope, recording every tracked property read. */
    public static function run(Scope $scope, Closure $fn): mixed
    {
        $scope->setParent(self::$active);
        self::$stack[] = self::$active;
        self::$active = $scope;
        $scope->begin();
        try {
            return $fn();
        } finally {
            self::$active = array_pop(self::$stack);
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
        self::$deps ??= new WeakMap();
        $map = self::$deps[$target] ?? [];
        $id = spl_object_id(self::$active);
        if (!isset($map[$property][$id])) {
            $map[$property][$id] = WeakReference::create(self::$active);
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
}
