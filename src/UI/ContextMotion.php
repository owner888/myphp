<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use InvalidArgumentException;

/**
 * Values that move (mygo's motion.go, and widgets.go's Animate, AnimateWith
 * and Loop): animated by a node and a key, which tells apart the node's
 * animated values, they keep frames coming while they move and go with
 * the node once it is no longer built. Without a runtime (a view measured
 * alone) a value is its target.
 */
trait ContextMotion
{
    /**
     * A value that moves to $target over $seconds, easing out, and keeps
     * frames coming while it moves (mygo's Animate). The value starts at
     * the first target. When the desktop asks for less motion, it goes to
     * $target at once. animateTo takes springs and delays too.
     */
    public function animate(Node $n, string|int $key, float $target, float $seconds): float
    {
        return $this->animateWith($n, $key, $target, $seconds, Easing::easeOut(...));
    }

    /** A value that moves to $target over $seconds as animate() does, along $ease. */
    public function animateWith(Node $n, string|int $key, float $target, float $seconds, Closure $ease): float
    {
        return $this->animateTo($n, $key, $target, new Animation(max($seconds, 0.001), $ease));
    }

    /**
     * A value that goes to $target as $a says, and keeps frames coming
     * while it moves (mygo's AnimateTo); animate(), animateWith() and
     * spring() share the node's keys. The value starts at the first target
     * (jump() sets it elsewhere first). A new target on the way, or a new
     * kind of Animation, takes over from where the value is: a spring
     * keeps its velocity, even that of a curve. When the desktop asks for
     * less motion, or disableAnimations() was called, the value goes to
     * its target at once.
     */
    public function animateTo(Node $n, string|int $key, float $target, Animation $a): float
    {
        Motion::finite('animation', $target);
        if ($a->duration < 0 || $a->delay < 0) {
            throw new InvalidArgumentException('animation durations must not be negative');
        }
        if ($a->duration == 0) {
            $a->spring->physics(); // throws now for invalid options
        }
        $rt = $this->runtime;
        if ($rt === null) {
            return $target;
        }
        $now = $rt->now;
        $ch = $rt->motions[$n->id][$key] ?? null;
        if ($ch === null) {
            $rt->motions[$n->id][$key] = new MotionChannel($target, $now);
            return $target;
        }
        $moving = $ch->moving || $ch->pending;
        $ch->advance($now);
        if ($rt->reduceMotion || $rt->instant()) {
            if ($ch->moving || $ch->pending || $ch->value != $target) {
                $ch->rested = $rt->frameNumber;
            }
            $ch->value = $target; $ch->velocity = 0.0; $ch->target = $target; $ch->moving = false; $ch->pending = false;
            $ch->at = $now;
            return $target;
        }
        if ($target != $ch->goal() && $a->delay > 0) {
            $ch->pending = true; $ch->pendingTo = $target; $ch->pendingAt = $now + Motion::ns($a->delay); $ch->pendingAnim = $a;
        } elseif ($target != $ch->goal()) {
            $ch->pending = false;
            $ch->retarget($target, $a);
        } elseif ($ch->moving && !$ch->curve && $a->duration == 0 && !$ch->pending) {
            [$ch->m, $ch->k, $ch->c, $ch->eps] = $a->spring->physics(); // the spring's options may change on the way
        }
        if ($ch->moving || $ch->pending) {
            $rt->animate();
        } elseif ($moving) {
            $ch->rested = $rt->frameNumber;
        }
        return $ch->value;
    }

    /** A value following $target with a spring, as animateTo() with new Animation(spring: $options) (mygo's Spring): smooth()'s spring without options. */
    public function spring(Node $n, string|int $key, float $target, SpringOptions $options = new SpringOptions()): float
    {
        return $this->animateTo($n, $key, $target, new Animation(spring: $options));
    }

    /**
     * Sets the value animated under $key to $value at once, as a drag moves
     * it under the pointer, and returns it (mygo's Jump). The value's
     * velocity follows the jumps from frame to frame, so that a spring
     * taking over from the last (animateTo) keeps going the way it went.
     */
    public function jump(Node $n, string|int $key, float $value): float
    {
        Motion::finite('animation', $value);
        $rt = $this->runtime;
        if ($rt === null) {
            return $value;
        }
        $now = $rt->now;
        $ch = $rt->motions[$n->id][$key] ?? null;
        if ($ch === null) {
            $rt->motions[$n->id][$key] = new MotionChannel($value, $now);
            return $value;
        }
        $dt = ($now - $ch->at) / 1e9;
        if ($dt > 0 && $dt <= 0.1 && $ch->jumped) {
            $ch->velocity = ($value - $ch->value) / $dt;
        } elseif ($dt > 0) {
            $ch->advance($now);
            if ($dt > 0.1) {
                $ch->velocity = 0.0;
            }
        }
        $ch->value = $value; $ch->target = $value; $ch->moving = false; $ch->pending = false; $ch->jumped = true; $ch->at = $now;
        return $value;
    }

    /** How fast, in its units per second, the value animated under $key goes in this frame: zero for one at rest or unknown (mygo's Velocity). */
    public function velocity(Node $n, string|int $key): float
    {
        return $this->runtime?->motions[$n->id][$key]->velocity ?? 0.0;
    }

    /** Whether the value animated under $key, by animateTo, animate, spring, animateColor or keyframes, rests at its target in this frame: true for an unknown key (mygo's Settled). */
    public function settled(Node $n, string|int $key): bool
    {
        $rt = $this->runtime;
        if ($rt === null) {
            return true;
        }
        if (($ch = $rt->motions[$n->id][$key] ?? null) !== null) {
            return !$ch->moving && !$ch->pending;
        }
        if (($m = $rt->colorMotions[$n->id][$key] ?? null) !== null) {
            return !$m->k->moving && !$m->k->pending;
        }
        if (($s = $rt->sequences[$n->id][$key] ?? null) !== null) {
            return $s->done;
        }
        return true;
    }

    /** Whether the value animated under $key came to rest at its target in this frame, after moving there: true in that frame only, for the view to go on, as by removing what faded out (mygo's Arrived). */
    public function arrived(Node $n, string|int $key): bool
    {
        $rt = $this->runtime;
        if ($rt === null) {
            return false;
        }
        $rested = ($rt->motions[$n->id][$key] ?? null)?->rested ?? ($rt->colorMotions[$n->id][$key] ?? null)?->k->rested ?? ($rt->sequences[$n->id][$key] ?? null)?->rested;
        return $rested === $rt->frameNumber;
    }

    /** Whether some of the node showed in the window in the frame before, within the scrolling containers and clips around it: to animate it in as it scrolls into view, as Motion's whileInView (mygo's InView). */
    public function inView(Node $n): bool
    {
        return $this->runtime?->shown($n->id) ?? false;
    }

    /**
     * A color, $rgb with $alpha, that goes to its target as $a says, mixed
     * with premultiplied alpha as transitions mix colors, so that a color
     * fading from transparent keeps its hue; the color shown, [rgb, alpha]
     * (mygo's AnimateColor). It starts at the first target. As changing
     * colors moves nothing, colors still change smoothly while the desktop
     * asks for less motion; disableAnimations() shows them at once.
     * @return array{0: int, 1: float}
     */
    public function animateColor(Node $n, string|int $key, int $rgb, Animation $a, float $alpha = 1.0): array
    {
        if ($a->duration < 0 || $a->delay < 0) {
            throw new InvalidArgumentException('animation durations must not be negative');
        }
        if ($a->duration == 0) {
            $a->spring->physics();
        }
        $target = [$rgb, $alpha];
        $rt = $this->runtime;
        if ($rt === null) {
            return $target;
        }
        $now = $rt->now;
        $m = $rt->colorMotions[$n->id][$key] ?? null;
        if ($m === null) {
            $rt->colorMotions[$n->id][$key] = (object)['from' => $target, 'to' => $target, 'shown' => $target, 'k' => new MotionChannel(1.0, $now)];
            return $target;
        }
        $moving = $m->k->moving || $m->k->pending;
        $m->k->advance($now);
        if ($target === $m->to) {
        } elseif ($rt->instant()) {
            $m->k = new MotionChannel(1.0, $now);
        } else {
            $m->from = $m->shown;
            $m->k = new MotionChannel(0.0, $now);
            $m->k->headFor(1.0, $a, $now);
        }
        $m->to = $target;
        if ($m->k->moving || $m->k->pending) {
            $m->shown = Motion::mixPremultiplied($m->from, $target, max(0.0, min($m->k->value, 1.0)));
            $rt->animate();
        } else {
            $m->shown = $target;
            if ($moving) {
                $m->k->rested = $rt->frameNumber;
            }
        }
        return $m->shown;
    }

    /**
     * Plays a sequence of Keyframes, holding its last value when done
     * (mygo's Keyframes). It starts on the first call, with the first
     * frame's value. Keep the node and key stable, and change the options'
     * run when changing the sequence. Only active playback asks for
     * frames. Reduced motion completes playback at its final value,
     * including when the preference changes mid-sequence.
     * @param list<Keyframe> $frames
     */
    public function keyframes(Node $n, string|int $key, array $frames, KeyframeOptions $o = new KeyframeOptions()): float
    {
        if (count($frames) < 2 || $frames[0]->at != 0) {
            throw new InvalidArgumentException('keyframes needs at least two frames starting at zero');
        }
        foreach ($frames as $i => $f) {
            Motion::finite('keyframe', $f->value, $f->at);
            if ($i > 0 && Motion::ns($f->at) <= Motion::ns($frames[$i - 1]->at)) {
                throw new InvalidArgumentException('keyframe times must strictly increase');
            }
        }
        if ($o->repeat < KeyframeOptions::REPEAT_FOREVER || $o->delay < 0 || $o->repeatDelay < 0) {
            throw new InvalidArgumentException('keyframe repeat must be REPEAT_FOREVER or nonnegative, and delays nonnegative');
        }
        $rt = $this->runtime;
        if ($rt === null) {
            return $frames[0]->value;
        }
        $now = $rt->now;
        $s = $rt->sequences[$n->id][$key] ?? null;
        if ($s === null || $s->options->run !== $o->run) {
            $from = $s === null ? $frames[0]->value : $s->sample($now)[0];
            $s = $rt->sequences[$n->id][$key] = new KeyframeSequence($frames, $o, $now + Motion::ns($o->delay), $from);
        }
        if ($s->done) {
        } elseif ($rt->reduceMotion) {
            $s->value = $s->final(); $s->done = true; $s->rested = $rt->frameNumber;
        } else {
            [$s->value, $s->done] = $s->sample($now);
            if ($s->done) {
                $s->rested = $rt->frameNumber;
            } else {
                $rt->animate();
            }
        }
        return $s->value;
    }

    /**
     * The progress of an animation that starts over every $period seconds,
     * from 0 to 1 along $ease, which keeps frames coming while the node is
     * built (mygo's Loop). A spinner turns with
     * loop($n, 'spin', 1, Easing::linear(...)) * 360, and a placeholder
     * pulses with an alpha of 0.5 + 0.5 * loop($n, 'pulse', $d,
     * Easing::bounce(Easing::easeInOut(...))).
     */
    public function loop(Node $n, string|int $key, float $period, Closure $ease): float
    {
        $rt = $this->runtime;
        if ($rt === null) {
            return $ease(0.0);
        }
        $start = $rt->loops[$n->id][$key] ??= $rt->now;
        $rt->animate();
        $p = max(Motion::ns($period), 1_000_000);
        return $ease((($rt->now - $start) % $p) / $p);
    }

    /**
     * The timeline $n keeps under $key, playing as $o says (mygo's
     * Timeline): every tween added to it shares one playhead, and returns
     * the value it shows in this frame. $key tells apart the node's
     * timelines, and a node asks for each of its timelines once a build.
     * Play it again, or a changed one, by changing $o->run.
     *
     * While the desktop asks for less motion, a timeline that would play
     * is at once where it would come to rest, but seek, progress and
     * paused still put and hold its playhead. disableAnimations() does not
     * change a timeline, which plays on its own, as keyframes do: jump it
     * with seek.
     */
    public function timeline(Node $n, string|int $key, TimelineOptions $o = new TimelineOptions()): Timeline
    {
        if ($o->repeat < KeyframeOptions::REPEAT_FOREVER || $o->delay < 0 || $o->repeatDelay < 0) {
            throw new InvalidArgumentException('a timeline repeat must be REPEAT_FOREVER or nonnegative, and its delays nonnegative');
        }
        if ($o->seek !== null && $o->progress !== null) {
            throw new InvalidArgumentException('a timeline takes seek or progress, not both');
        }
        $rt = $this->runtime;
        if ($rt === null) {
            return new Timeline(null, null, $o);
        }
        $st = $rt->timelines[$n->id][$key] ??= new TimelineState();
        if ($st->node === $n && $st->frame === $rt->frameNumber && $st->pass === $rt->pass) {
            throw new \LogicException('a node asked for its timeline ' . var_export($key, true) . ' twice in one build: give each timeline a key of its own');
        }
        $st->node = $n;
        return new Timeline($rt, $st, $o);
    }

    /**
     * Animates $n as $t asks (mygo's Transition): from what the last frame
     * showed to what the layout makes of it in this one. The node needs the
     * same id from frame to frame: a key, or the same place among its
     * siblings. Nodes that the change moves as well need a transition of
     * their own to move smoothly, the same one to move in step with it, as
     * the rows of a list. Changes made by resizing the window do not
     * animate. While the desktop asks for less motion, nothing moves:
     * colors still change smoothly, and an enter or exit that fades without
     * collapsing fades in place.
     */
    public function transition(Node $n, ElementTransition $t): Node
    {
        if ($this->runtime !== null) {
            $this->runtime->uses[] = [$n, $t];
        }
        return $n;
    }

    /**
     * Lets the pointer move $n from its place in the layout by $offset
     * ([x, y]), and returns $n (mygo's Draggable). As the pointer drags it,
     * it follows the pointer along its parent's axes, and $offset with it;
     * once let go, $offset is where it comes to rest, as $o says, and it goes
     * there. Setting $offset moves it there as well, as $o->animation says;
     * without one, the offset is kept for it. It moves after its own
     * transform and its pose (Node::animatePose). dragging() reports that
     * the pointer drags it, once it moved a few points, transitioning() that
     * it moves, and dragOffset() where it shows. A press dragging it is no
     * click; a press catches it as it moves. With Reduce Motion it goes to
     * rest at once.
     *
     *     $c->draggable($sheet, $s->sheet, new DraggableOptions(Node::VERTICAL,
     *         bounds: [0, 0, 0, 400], snap: Motion::nearest([0, 0], [0, 200], [0, 400])));
     * @param ?array{0: float, 1: float} $offset
     */
    public function draggable(Node $n, ?array &$offset = null, DraggableOptions $o = new DraggableOptions): Node
    {
        $axes = $o->axis;
        if (($axes & ~(Node::HORIZONTAL | Node::VERTICAL)) !== 0) {
            throw new \InvalidArgumentException('a draggable axis must be Node::HORIZONTAL, VERTICAL or both');
        }
        if ($axes === 0) {
            $axes = Node::HORIZONTAL | Node::VERTICAL;
        }
        if (($b = $o->bounds) !== null) {
            self::finite('drag bounds', ...$b);
            if ($b[2] < 0 || $b[3] < 0) {
                throw new \InvalidArgumentException('draggable bounds must not have a negative size');
            }
        }
        $a = $o->animation ?? Animation::smooth();
        if ($a->duration < 0 || $a->delay < 0) {
            throw new \InvalidArgumentException('animation durations must not be negative');
        }
        if ($a->duration == 0) {
            $a->spring->physics(); // throws now for invalid options
        }
        $rt = $this->runtime;
        if ($rt === null) {
            return $n;
        }
        $now = $rt->now;
        $r = $rt->dragRecs[$n->id] ??= new DragRec;
        if ($r->frame === 0 || $r->frame + 1 < $rt->frameNumber) { // not dragged in the frame before: it shows at its offset at once
            $own = $r->own;
            $r = $rt->dragRecs[$n->id] = new DragRec;
            $r->own = $own;
        }
        if ($offset === null) {
            $offset = &$r->own;
        }
        self::finite('drag offset', ...$offset);
        if ($r->frame === 0 || $r->frame + 1 < $rt->frameNumber) {
            $r->hold($offset, $now);
        }
        $r->frame = $rt->frameNumber; $r->axes = $axes; $r->rigid = $o->rigid;
        $r->bounded = $o->bounds !== null;
        if ($r->bounded) { $r->bounds = $o->bounds; }
        $n->draggable = true;
        $r->advance($now);
        $shown = $r->shown();
        $still = $rt->reduceMotion || $rt->instant();
        $set = function (array $p) use (&$offset, $rt) {
            if ($offset != $p) {
                $offset = $p;
                $rt->changed();
            }
        };
        $press = $n->disabled ? null : $rt->pressOf($n->id);
        $held = $press !== null && $press['down'];
        $ended = $press !== null && !$press['down'] && $press['released']; // a press that began and ended since the last frame
        if (!$r->pressing && ($held || $ended)) { // the press holds it where it shows
            $r->pressing = true; $r->caught = $r->moving(); $r->from = $shown;
            $r->hold($shown, $now);
            if ($r->caught) { $set($shown); }
        }
        $space = $rt->parentSpace($n->id);
        if ($r->pressing && $press !== null && $press['track']->far && $space !== null) { // it follows the pointer, from where the press took it
            $d = $press['track'];
            [$x0, $y0] = $space->point($d->ox, $d->oy);
            [$x1, $y1] = $space->point($d->ox + $d->wx, $d->oy + $d->wy);
            [$w, $h] = $rt->parentSize($n->id);
            $shown = $r->band([$r->from[0] + $x1 - $x0, $r->from[1] + $y1 - $y0], $w, $h);
            $r->hold($shown, $now);
            $set($shown);
        }
        if ($r->pressing && !$held) {
            $r->pressing = false;
            $far = $press !== null && $press['track']->far;
            if ($far || $r->caught) {
                $v = [0.0, 0.0];
                if ($far && $ended && $space !== null) {
                    $d = $press['track'];
                    $v = self::fling($space->vectorAt($d->ox + $d->wx, $d->oy + $d->wy, $press['v'][0], $press['v'][1]));
                }
                $to = $shown;
                if ($o->snap !== null) {
                    $to = ($o->snap)($r->clamp([Motion::project($shown[0], $v[0]), Motion::project($shown[1], $v[1])], $shown));
                    self::finite('drag offset', ...$to);
                }
                $to = $r->clamp($to, $shown);
                $set($to);
                $r->settle($to, $v, $a, $still, $now);
                return $n;
            }
        }
        if (!$r->pressing) { // it goes where the app moves it, within its bounds
            $to = $r->clamp($offset, $offset);
            $set($to);
            $r->head($to, $a, $still, $now);
        }
        return $n;
    }

    /** The fastest a throw goes, in points per second, as Flutter's and Android's flings: moves the window delivers at once after a slow frame would otherwise throw a node far away. */
    public const MAX_FLING = 8000.0;

    /** $v, no faster than MAX_FLING (mygo's flingVelocity). @return array{0: float, 1: float} */
    public static function fling(array $v): array
    {
        $n = hypot($v[0], $v[1]);
        if (!is_finite($n)) {
            return [0.0, 0.0];
        }
        $k = $n > self::MAX_FLING ? self::MAX_FLING / $n : 1.0;
        return [$v[0] * $k, $v[1] * $k];
    }

    private static function finite(string $what, float ...$vs): void
    {
        foreach ($vs as $v) {
            if (!is_finite($v)) {
                throw new \InvalidArgumentException("$what must be finite");
            }
        }
    }

    /** Where draggable() shows $n in this frame, from its place in the layout: following the pointer, or on its way to rest (mygo's DragOffset). Call it after draggable(). @return array{0: float, 1: float} */
    public function dragOffset(Node $n): array
    {
        $rt = $this->runtime;
        $r = $rt?->dragRecs[$n->id] ?? null;
        if ($r === null || $r->frame + 1 < $rt->frameNumber) {
            return [0.0, 0.0];
        }
        $r->advance($rt->now);
        if ($r->moving()) {
            $rt->animationFrame();
        }
        return $r->shown();
    }

    /** Whether the pointer is dragging $n (draggable()), once it moved a few points (mygo's Dragging). */
    public function dragging(Node $n): bool
    {
        $rt = $this->runtime;
        $p = $rt?->pressOf($n->id);
        $r = $rt?->dragRecs[$n->id] ?? null;
        return $p !== null && $p['down'] && !$n->disabled && $r !== null && $r->frame + 1 >= $rt->frameNumber && $p['track']->far;
    }

    /** Whether $n's transition was moving it, or changing its colors, in the frame before: false once it is where it goes (mygo's Transitioning). */
    public function transitioning(Node $n): bool
    {
        return $this->runtime?->transitioning($n->id) ?? false;
    }

    /**
     * Makes the values that animate in this frame go to their targets at
     * once, as when the window first shows, or the view jumps to a state
     * rather than changing to it (mygo's DisableAnimations).
     */
    public function disableAnimations(): void
    {
        if ($this->runtime !== null) {
            $this->runtime->instantFrame = $this->runtime->frameNumber;
        }
    }
}
