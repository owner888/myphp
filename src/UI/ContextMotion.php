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
