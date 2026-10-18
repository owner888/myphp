<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What a node's ElementTransition keeps from frame to frame, by the node's
 * id (mygo's transition). It outlives the node while a copy of it leaves.
 *
 * A box is what a transition moves: the node's place in its parent's box,
 * its size, its opacity, and the scale and rotation of a TransitionMotion,
 * as [x, y, w, h, alpha, scale, rot].
 */
final class TransitionState
{
    /** What moves each channel: where it is, its size, its opacity, its turn. */
    public const MOVE_POS = 1, MOVE_SIZE = 2, MOVE_ALPHA = 4, MOVE_TURN = 8, MOVE_ALL = 15;
    public const GROUP = [self::MOVE_POS, self::MOVE_POS, self::MOVE_SIZE, self::MOVE_SIZE, self::MOVE_ALPHA, self::MOVE_TURN, self::MOVE_TURN];
    /** The axes a TransitionMotion collapses a node along. */
    public const COLLAPSE_WIDTH = 1, COLLAPSE_HEIGHT = 2;
    /** What a transition animates. */
    public const PROP_POSITION = 1, PROP_SIZE = 2, PROP_COLORS = 4;

    public ElementTransition $t;
    /** The last frame that built the node, and the last that laid it out: $elem is its node then, and $parent the id of its parent. */
    public int $built = 0, $laid = 0;
    public ?Node $elem = null;
    public int $parent = 0;
    /** Where the layout put the node in frame $laid, $next where it puts it in the frame being laid out, and $shown where it shows: $ch move the components of $moving there. */
    public array $target = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 0.0], $next = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 0.0], $shown = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 0.0];
    /** @var list<MotionChannel> */
    public array $ch = [];
    public int $moving = 0;
    public bool $fresh = false;
    /** The axis a TransitionMotion collapses the node along, while it does. */
    public int $collapse = 0;
    /** The background and border colors, each [rgb, alpha], which move k of the way from $colorFrom. */
    public array $colorTarget = [[0, 0.0], [0, 0.0]], $colorFrom = [[0, 0.0], [0, 0.0]], $colorShown = [[0, 0.0], [0, 0.0]];
    public ?MotionChannel $colorK = null;
    public bool $colorMoving = false, $colorSet = false;
    public int $colorFrame = 0;
    /** The node going, which moves from $gone, where it showed as it went; $opacity is its own, and $turn its transform then. */
    public ?Node $ghost = null;
    public array $gone = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 0.0];
    public float $opacity = 1.0;
    public ?Transform $turn = null;
    /** Moves a node appearing where the one it matches showed: its box in the window, from the box that one had. @var list<MotionChannel> */
    public array $match = [];
    public bool $matching = false;

    /** How the transition's changes go. */
    public function animation(): Animation
    {
        if ($this->t->animation !== null) {
            return $this->t->animation;
        }
        return new Animation($this->duration(), $this->t->ease ?? Easing::easeOut(...));
    }

    /** How long, in seconds, the transition's changes take, about as long for a spring. */
    public function duration(): float
    {
        if (($a = $this->t->animation) !== null) {
            if ($a->duration > 0) { return $a->duration; }
            if ($a->spring->duration > 0) { return $a->spring->duration; }
            if ($a->spring->stiffness == 0 && $a->spring->damping == 0) { return SpringOptions::DEFAULT_DURATION; }
            [$m, $k] = $a->spring->physics();
            return 2 * M_PI * sqrt($m / $k);
        }
        return $this->t->duration > 0 ? $this->t->duration : ElementTransition::DEFAULT_DURATION;
    }

    /** Sends the channels of $move from where they are to $target, as the transition's animation says, from $now. */
    public function head(array $target, int $move, int $now): void
    {
        $a = $this->animation();
        foreach ($this->ch as $i => $ch) {
            if (($move & self::GROUP[$i]) !== 0) {
                $ch->headFor($target[$i], $a, $now);
            }
        }
        $this->moving |= $move;
    }

    /**
     * Shifts what moves that the transition does not animate, as an
     * enter's place, by as much as the layout moved it since the frame
     * before: that follows the layout at once. A size scales instead, so
     * that one collapsed opens on in proportion, never below nothing.
     */
    public function follow(array $next, int $props): void
    {
        $own = (($props & self::PROP_POSITION) !== 0 ? self::MOVE_POS : 0) | (($props & self::PROP_SIZE) !== 0 ? self::MOVE_SIZE : 0);
        foreach ($this->ch as $i => $ch) {
            $g = self::GROUP[$i];
            if (($g & (self::MOVE_POS | self::MOVE_SIZE)) === 0 || ($own & $g) !== 0 || ($this->moving & $g) === 0) {
                continue;
            }
            $d = $next[$i] - $this->target[$i];
            if ($d == 0) {
                continue;
            }
            if ($g === self::MOVE_SIZE && $this->target[$i] > 0) {
                $ch->scale($this->target[$i], $next[$i]);
            } else {
                $ch->shift($d);
            }
        }
    }

    /** Puts every channel at rest at $b. */
    public function rest(array $b, int $now): void
    {
        $this->ch = [];
        foreach ($b as $v) {
            $this->ch[] = new MotionChannel($v, $now);
        }
        $this->moving = 0; $this->collapse = 0;
    }

    /**
     * Moves the channels to $now, and returns where they show the node
     * going to $target: the components not moving there at once. The moves
     * over, the transition no longer moves.
     */
    public function sample(array $target, int $now): array
    {
        $v = []; $still = self::MOVE_ALL;
        foreach ($this->ch as $i => $ch) {
            if (($this->moving & self::GROUP[$i]) === 0) {
                $ch = $this->ch[$i] = new MotionChannel($target[$i], $now);
            } else {
                $ch->advance($now);
                if ($ch->moving || $ch->pending) {
                    $still &= ~self::GROUP[$i];
                }
            }
            $v[$i] = $ch->value;
        }
        $this->moving &= ~$still;
        if ($this->moving === 0) {
            $this->collapse = 0;
            return $target;
        }
        return $v;
    }

    public function properties(): int
    {
        $t = $this->t;
        if (!$t->position && !$t->size && !$t->colors) {
            return self::PROP_POSITION | self::PROP_SIZE | self::PROP_COLORS;
        }
        return ($t->position ? self::PROP_POSITION : 0) | ($t->size ? self::PROP_SIZE : 0) | ($t->colors ? self::PROP_COLORS : 0);
    }

    /** $b moved as $m says, collapsed along $axis, or, without motion ($still), only faded. */
    public static function moved(array $b, TransitionMotion $m, int $axis, bool $still): array
    {
        $b[4] = max(0.0, min($m->opacity, 1.0));
        if ($still) {
            return $b;
        }
        $b[0] += $m->x; $b[1] += $m->y;
        if ($m->scale != 0) { $b[5] = $m->scale; }
        $b[6] = $m->rotate;
        if ($axis === self::COLLAPSE_WIDTH) { $b[2] = 0.0; }
        if ($axis === self::COLLAPSE_HEIGHT) { $b[3] = 0.0; }
        return $b;
    }

    /** The transform of a box's scale and rotation, unless it has none. */
    public static function turnOf(array $b): ?Transform
    {
        if ($b[5] == 1 && $b[6] == 0) {
            return null;
        }
        return Transform::scaling($b[5], $b[5])->then(Transform::rotation($b[6]));
    }

    /** The axis a TransitionMotion collapses a child of $parent along: a row's width, other parents' height. */
    public static function collapseAxis(TransitionMotion $m, Node $parent): int
    {
        if (!$m->collapse) {
            return 0;
        }
        return $parent->dir === Node::ROW && !($parent->scroll instanceof ListState) && $parent->gridCols === null ? self::COLLAPSE_WIDTH : self::COLLAPSE_HEIGHT;
    }
}
