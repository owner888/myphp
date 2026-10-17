<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * How a value goes to a new target (mygo's Animation): along an easing
 * curve for $duration seconds, or, when $duration is zero, with a spring,
 * which keeps the value's velocity when the target changes again on the
 * way. new Animation() is smooth()'s spring:
 *
 *     $x = $c->animateTo($card, 'x', $target, Animation::snappy());
 *     $fade = $c->animateTo($card, 'fade', $target, new Animation(0.15, Easing::easeInOut(...)));
 */
final class Animation
{
    public function __construct(
        /** With $ease, a curve: $ease, easeOut when null, over $duration seconds. */
        public readonly float $duration = 0.0,
        public readonly ?Closure $ease = null,
        /** The spring of an Animation without a duration. */
        public readonly SpringOptions $spring = new SpringOptions(),
        /** How long, in seconds, the value keeps going as it was after its target changes, before it heads for the new one, as a list's rows do one after another with a delay of $i * 0.03. */
        public readonly float $delay = 0.0,
    ) {}

    /** A spring that settles without bouncing, as SwiftUI's smooth; new Animation()'s. */
    public static function smooth(): self
    {
        return new self(spring: new SpringOptions(SpringOptions::DEFAULT_DURATION));
    }

    /** A spring with a little bounce, as SwiftUI's snappy. */
    public static function snappy(): self
    {
        return new self(spring: new SpringOptions(SpringOptions::DEFAULT_DURATION, 0.15));
    }

    /** A spring with a visible bounce, as SwiftUI's bouncy. */
    public static function bouncy(): self
    {
        return new self(spring: new SpringOptions(SpringOptions::DEFAULT_DURATION, 0.3));
    }
}
