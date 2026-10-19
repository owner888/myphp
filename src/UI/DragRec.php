<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What Context::draggable keeps of a node from frame to frame (mygo's
 * dragRec): the channels of its offset, the last frame built with it, the
 * axes it moves along, its bounds, and the press holding it, which caught
 * it moving when $caught, and took it at $from.
 */
final class DragRec
{
    /** @var array{0: MotionChannel, 1: MotionChannel} */
    public array $ch;
    public int $frame = 0, $axes = 0;
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $bounds = [0.0, 0.0, 0.0, 0.0];
    public bool $bounded = false, $rigid = false, $pressing = false, $caught = false;
    /** @var array{0: float, 1: float} */
    public array $from = [0.0, 0.0];
    /** The offset of a draggable node given none. @var array{0: float, 1: float} */
    public array $own = [0.0, 0.0];

    public function __construct()
    {
        $this->ch = [new MotionChannel(0.0, 0), new MotionChannel(0.0, 0)];
    }

    /** @return array{0: float, 1: float} */
    public function at(): array
    {
        return [$this->ch[0]->value, $this->ch[1]->value];
    }

    /** Where the node shows: where its channels are, held within its bounds when they are rigid. @return array{0: float, 1: float} */
    public function shown(): array
    {
        $p = $this->at();
        return $this->rigid ? $this->clamp($p, $p) : $p;
    }

    public function moving(): bool
    {
        return $this->ch[0]->moving || $this->ch[0]->pending || $this->ch[1]->moving || $this->ch[1]->pending;
    }

    /** Moves the channels on to $now. */
    public function advance(int $now): void
    {
        foreach ($this->ch as $ch) {
            if ($ch->moving || $ch->pending) {
                $ch->advance($now);
            }
        }
    }

    /** Puts the channels at rest at $p. @param array{0: float, 1: float} $p */
    public function hold(array $p, int $now): void
    {
        $this->ch = [new MotionChannel($p[0], $now), new MotionChannel($p[1], $now)];
    }

    /** $p held within the bounds along the axes the node moves along, and as $rest along the others. @return array{0: float, 1: float} */
    public function clamp(array $p, array $rest): array
    {
        [$bx, $by, $bw, $bh] = $this->bounds;
        if (($this->axes & Node::HORIZONTAL) === 0) {
            $p[0] = $rest[0];
        } elseif ($this->bounded) {
            $p[0] = max($bx, min($p[0], $bx + $bw));
        }
        if (($this->axes & Node::VERTICAL) === 0) {
            $p[1] = $rest[1];
        } elseif ($this->bounded) {
            $p[1] = max($by, min($p[1], $by + $bh));
        }
        return $p;
    }

    /** Where the pointer takes the node to $p: as $from along the axes it does not move along, and past its bounds less and less far, as on a rubber band $w × $h (its parent's), unless they are rigid. @return array{0: float, 1: float} */
    public function band(array $p, float $w, float $h): array
    {
        if (($this->axes & Node::HORIZONTAL) === 0) { $p[0] = $this->from[0]; }
        if (($this->axes & Node::VERTICAL) === 0) { $p[1] = $this->from[1]; }
        if (!$this->bounded || $this->rigid) {
            return $this->clamp($p, $p);
        }
        [$bx, $by, $bw, $bh] = $this->bounds;
        if (($this->axes & Node::HORIZONTAL) !== 0) { $p[0] = Motion::rubberband($p[0], $bx, $bx + $bw, $w); }
        if (($this->axes & Node::VERTICAL) !== 0) { $p[1] = Motion::rubberband($p[1], $by, $by + $bh, $h); }
        return $p;
    }

    /** Sends the channels from where the pointer let the node go to $to, at velocity $v. */
    public function settle(array $to, array $v, Animation $a, bool $still, int $now): void
    {
        foreach ($this->ch as $i => $ch) {
            $t = $to[$i];
            if ($t == $ch->value) {
                continue; // already there: a throw along it goes nowhere
            }
            if ($still) {
                $this->ch[$i] = new MotionChannel($t, $now);
                continue;
            }
            $s = $a->spring;
            $b = new Animation($a->duration, $a->ease, new SpringOptions($s->duration, $s->bounce, $s->mass, $s->stiffness, $s->damping, $s->precision, $v[$i]));
            $ch->at = $now;
            $ch->retarget($t, $b);
        }
    }

    /** Sends the channels from where they are to $to, as $a says, or there at once when $still. */
    public function head(array $to, Animation $a, bool $still, int $now): void
    {
        foreach ($this->ch as $i => $ch) {
            $t = $to[$i];
            if ($still) {
                if ($ch->moving || $ch->pending || $ch->value != $t) {
                    $this->ch[$i] = new MotionChannel($t, $now);
                }
            } elseif ($t != $ch->goal()) {
                $ch->headFor($t, $a, $now);
            }
        }
    }
}
