<?php
declare(strict_types=1);

namespace MyPHP\UI\Reactive;

use MyPHP\UI\Runtime;
use WeakReference;

/**
 * A reactive tracking scope for a retained view subtree (Computed) or a
 * window's root build. Holds WeakReferences to its Runtime and parent Scope
 * so no cycle ever forms between state objects, scopes and the frame tree.
 */
final class Scope
{
    public int $version = 0;
    public bool $dirty = true;
    /** @var ?WeakReference<Runtime> */
    private ?WeakReference $runtime;
    /** @var ?WeakReference<self> */
    private ?WeakReference $parent = null;

    public function __construct(?Runtime $runtime = null)
    {
        $this->runtime = $runtime !== null ? WeakReference::create($runtime) : null;
    }

    /** Links this scope to the enclosing parent scope during build. @internal */
    public function setParent(?self $parent): void
    {
        $this->parent = ($parent !== null && $parent !== $this) ? WeakReference::create($parent) : null;
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
