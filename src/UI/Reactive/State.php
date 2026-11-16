<?php
declare(strict_types=1);

namespace MyPHP\UI\Reactive;

use Closure;
use MyPHP\UI\Declarative\Binding;

/**
 * Reactive state value powered by PHP 8.4 Property Hooks, as SwiftUI's @State:
 * reading `$state->value` (or interpolating `"{$state}"`) inside a view
 * automatically tracks the dependency; writing `$state->value = $new`
 * (including from an async Fiber or timer) automatically invalidates only the
 * subscribed view scopes and requests a window frame.
 *
 * @template T
 */
final class State
{
    /** @param T $raw */
    public function __construct(private mixed $raw = null) {}

    /** @var T */
    public mixed $value {
        get {
            Observer::track($this, 'value');
            return $this->raw;
        }
        set {
            if ($this->raw !== $value) {
                $this->raw = $value;
                Observer::trigger($this, 'value');
            }
        }
    }

    /** Reads the current value without recording a reactive dependency. @return T */
    public function peek(): mixed
    {
        return $this->raw;
    }

    /** Updates the value by applying $fn to the current value. @param Closure(T): T $fn */
    public function update(Closure $fn): void
    {
        $this->value = $fn($this->raw);
    }

    /** A two-way Binding for declarative controls. */
    public function binding(): Binding
    {
        return new Binding(
            get: fn(): mixed => $this->value,
            set: function (mixed $v): void { $this->value = $v; },
        );
    }

    public function __toString(): string
    {
        return (string)$this->value;
    }
}
