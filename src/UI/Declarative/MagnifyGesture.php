<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * MagnifyGesture, as SwiftUI's: tracks pinch-to-zoom / magnification changes;
 * onChanged and onEnded receive a MagnifyGestureValue ($v->magnification).
 */
final class MagnifyGesture
{
    public ?Closure $changed = null, $ended = null;

    public function __construct(public readonly float $minimumScaleDelta = 0.01) {}

    public function onChanged(Closure $action): static { $this->changed = $action; return $this; }

    public function onEnded(Closure $action): static { $this->ended = $action; return $this; }
}

final class MagnifyGestureValue
{
    public function __construct(
        public readonly float $magnification = 1.0,
        public readonly float $velocity = 0.0,
        public readonly \UnitPoint $startAnchor = \UnitPoint::center,
        public readonly CGPoint $startLocation = new CGPoint(0, 0),
    ) {}
}