<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * How a node's content changes (Node::contentTransition, mygo's
 * ContentTransition): its text, its icon, or its picture. By default, the
 * old content fades out as the new fades in; an icon shrinks away as the
 * new grows in, as SwiftUI's replace symbol effect. $numeric rolls the
 * characters of a line of text that change, as a counter's digits, those
 * that stay staying: the new come up from below, or, $down, from above, as
 * SwiftUI's numericText.
 */
final class ContentTransition
{
    public function __construct(
        public readonly bool $numeric = false,
        public readonly bool $down = false,
        /** How the content changes: a spring arriving in about 350 ms without bouncing when null. */
        public readonly ?Animation $animation = null,
    ) {}

    /** The animation of one without an animation. */
    public static function defaultAnimation(): Animation
    {
        return new Animation(spring: new SpringOptions(0.35));
    }
}
