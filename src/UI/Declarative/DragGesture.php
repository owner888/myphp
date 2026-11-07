<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * DragGesture, as SwiftUI's: the pointer pressing the view and moving
 * $minimumDistance points at least; onChanged runs as it moves, onEnded as
 * it lets go, each with a DragGestureValue: where it is, where it started,
 * how far it moved, how fast, and where a throw would carry it.
 *
 *     ->gesture(DragGesture()->onChanged(fn(DragGestureValue $v) => $this->drag = $v->translation)->onEnded(…))
 */
final class DragGesture
{
    public ?Closure $changed = null, $ended = null;

    public function __construct(public readonly float $minimumDistance = 10) {}

    public function onChanged(Closure $action): static { $this->changed = $action; return $this; }

    public function onEnded(Closure $action): static { $this->ended = $action; return $this; }
}
