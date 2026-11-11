<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * RotateGesture, as SwiftUI's: tracks two-finger rotation changes;
 * onChanged and onEnded receive a RotateGestureValue ($v->rotation in degrees).
 */
final class RotateGesture
{
    public ?Closure $changed = null, $ended = null;

    public function __construct(public readonly float $minimumAngleDelta = 1.0) {}

    public function onChanged(Closure $action): static { $this->changed = $action; return $this; }

    public function onEnded(Closure $action): static { $this->ended = $action; return $this; }
}

final class RotateGestureValue
{
    public function __construct(
        public readonly float $rotation = 0.0,
        public readonly float $velocity = 0.0,
        public readonly \UnitPoint $startAnchor = \UnitPoint::center,
        public readonly CGPoint $startLocation = new CGPoint(0, 0),
    ) {}
}