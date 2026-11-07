<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\TransitionMotion;

/**
 * How a view comes as it appears and goes as it is no longer built, as
 * SwiftUI's AnyTransition: AnyTransition::opacity(), scale(), slide(),
 * move(edge:), offset(), and combined() and asymmetric() of them.
 */
final class AnyTransition
{
    private function __construct(public readonly ?TransitionMotion $insertion, public readonly ?TransitionMotion $removal) {}

    private static function both(TransitionMotion $m): self { return new self($m, $m); }

    /** Fades in and out. */
    public static function opacity(): self { return self::both(new TransitionMotion(opacity: 0.0)); }

    /** Grows from $scale and shrinks back to it, fading. */
    public static function scale(float $scale = 0.001): self { return self::both(new TransitionMotion(opacity: 0.0, scale: $scale)); }

    /** Moves in from beyond the $edge of its place and out that way, as far as $distance points. */
    public static function move(\Edge $edge, float $distance = 60): self
    {
        [$x, $y] = match ($edge) { \Edge::top => [0, -$distance], \Edge::bottom => [0, $distance], \Edge::leading => [-$distance, 0], default => [$distance, 0] };
        return self::both(new TransitionMotion($x, $y, 1.0));
    }

    /** Comes from the leading side and goes to the trailing one. */
    public static function slide(float $distance = 60): self { return new self(new TransitionMotion(-$distance, 0, 1.0), new TransitionMotion($distance, 0, 1.0)); }

    /** Comes from and goes to $x, $y points off its place. */
    public static function offset(float $x = 0, float $y = 0): self { return self::both(new TransitionMotion($x, $y, 1.0)); }

    /** None: it shows and goes at once. */
    public static function identity(): self { return new self(null, null); }

    /** One way in, another out. */
    public static function asymmetric(self $insertion, self $removal): self { return new self($insertion->insertion, $removal->removal); }

    /** This and $other at once: moved, scaled and faded as both. */
    public function combined(self $other): self
    {
        $mix = static fn(?TransitionMotion $a, ?TransitionMotion $b): ?TransitionMotion => $a === null ? $b : ($b === null ? $a : new TransitionMotion(
            $a->x + $b->x, $a->y + $b->y, min($a->opacity, $b->opacity), $a->collapse || $b->collapse, $a->scale ?: $b->scale, $a->rotate + $b->rotate));
        return new self($mix($this->insertion, $other->insertion), $mix($this->removal, $other->removal));
    }
}
