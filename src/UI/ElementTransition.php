<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Animates a node (Node::transition) from what the last frame showed to
 * what the layout makes of it in this one (mygo's ElementTransition): where
 * it is in its parent, its size, and its background and border colors.
 * $enter and $exit animate it as it appears and as it goes:
 *
 *     $c->key($item->id)->row()->transition(new ElementTransition(
 *         enter: new TransitionMotion(collapse: true),
 *         exit: new TransitionMotion(collapse: true),
 *     ));
 */
final class ElementTransition
{
    public const DEFAULT_DURATION = 0.2;

    public function __construct(
        /** How long a change takes, in seconds: 200 ms when zero. */
        public readonly float $duration = 0.0,
        /** How the node goes, as for animateWith: easeOut when null. */
        public readonly ?Closure $ease = null,
        /** If set, how changes go instead of the duration and the ease: with a spring (Animation::snappy(), …), a change on the way keeps the node going as it went; with a delay, changes wait, as the rows of a list entering one after another. */
        public readonly ?Animation $animation = null,
        /** What animates: where the node is in its parent, its width and height (its content laid out at each size on the way), and its background and border colors. None set animates them all. */
        public readonly bool $position = false,
        public readonly bool $size = false,
        public readonly bool $colors = false,
        /** If set, where the node comes from as it appears in a parent that the last frame showed: it starts there, and goes to its place. */
        public readonly ?TransitionMotion $enter = null,
        /** If set, where the node goes once it is no longer built, while its parent still is: a copy of it, as the last frame showed it, goes there under its siblings, taking neither room, the pointer nor the focus, and is then gone. */
        public readonly ?TransitionMotion $exit = null,
        /** If set, an identity the node shares with one that the frame before showed elsewhere, even in another parent, as a photo's thumbnail in a grid and the photo in a detail view: the node appearing comes from where that one was, at its size and in its colors, as SwiftUI's matchedGeometryEffect and Motion's layoutId. */
        public readonly string|int|null $match = null,
    ) {}
}
