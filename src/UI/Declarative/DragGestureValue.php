<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * Where a DragGesture is, as SwiftUI's DragGesture.Value: $location and
 * $startLocation in the view's points, $translation in the window's from
 * the start, $velocity in points per second, and where a throw let go now
 * would come to rest ($predictedEndLocation, $predictedEndTranslation),
 * as a scroll view's deceleration carries it.
 */
final class DragGestureValue
{
    public function __construct(
        public readonly CGPoint $location,
        public readonly CGPoint $startLocation,
        public readonly CGSize $translation,
        public readonly CGSize $velocity,
        public readonly CGPoint $predictedEndLocation,
        public readonly CGSize $predictedEndTranslation,
    ) {}
}
