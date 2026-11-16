<?php
declare(strict_types=1);

namespace MyPHP\UI\Reactive;

use MyPHP\UI\Runtime;
use WeakReference;

/**
 * A reactive tracking scope for a retained view subtree (Computed) or a
 * window's root build. Holds WeakReferences to its Runtime, parent Scope,
 * and plain state objects so no cycle ever forms (gc_collect_cycles() === 0).
 */
final class Scope
{
    public int $version = 0;
    public bool $dirty = true;
    /** @var ?WeakReference<Runtime> */
    private ?WeakReference $runtime;
    /** @var ?WeakReference<self> */
    private ?WeakReference $parent = null;
    private ?string $closureKey = null;
    /** @var list<array{0: WeakReference<object>, 1: string}>|null */
    private ?array $targets = null;
    /** @var list<array{0: WeakReference<object>, 1: string, 2: mixed}> */
    private array $plainDeps = [];

    public function __construct(?Runtime $runtime = null)
    {
        $this->runtime = $runtime !== null ? WeakReference::create($runtime) : null;
    }

    /** Links this scope to the enclosing parent scope during build. @internal */
    public function setParent(?self $parent): void
    {
        $this->parent = ($parent !== null && $parent !== $this) ? WeakReference::create($parent) : null;
    }

    /** Whether this scope has already bound its static closure property targets for $key. @internal */
    public function hasClosureKey(string $key): bool
    {
        return $this->closureKey === $key && $this->targets !== null;
    }

    /** @param list<array{0: WeakReference<object>, 1: string}> $targets @internal */
    public function setTargets(string $key, array $targets): void
    {
        $this->closureKey = $key;
        $this->targets = $targets;
    }

    /** @return list<array{0: WeakReference<object>, 1: string}> @internal */
    public function targets(): array
    {
        return $this->targets ?? [];
    }

    /** Snapshots the current values of all plain properties this scope depends on. @internal */
    public function snapshotPlain(): void
    {
        $this->plainDeps = [];
        foreach ($this->targets ?? [] as [$ref, $prop]) {
            $obj = $ref->get();
            if ($obj !== null) {
                $val = Observer::snapshot($obj->$prop ?? null);
                $this->plainDeps[] = [$ref, $prop, $val];
                Observer::watchPlain($obj, $prop, $val);
            }
        }
    }

    /** Whether this scope is clean (no hooked/State trigger and no plain property change). */
    public function isClean(): bool
    {
        if ($this->dirty) {
            return false;
        }
        foreach ($this->plainDeps as [$ref, $prop, $was]) {
            $obj = $ref->get();
            if ($obj !== null && Observer::snapshot($obj->$prop ?? null) !== $was) {
                $this->invalidate();
                return false;
            }
        }
        return true;
    }

    /** Marks this scope (and its ancestor chain) dirty and wakes its owning window's runtime. */
    public function invalidate(): void
    {
        if (!$this->dirty) {
            $this->dirty = true;
            $this->version++;
        }
        $this->parent?->get()?->invalidate();
        $this->runtime?->get()?->invalidate();
    }

    /** Marks this scope clean as its build begins. */
    public function begin(): void
    {
        $this->dirty = false;
    }
}
