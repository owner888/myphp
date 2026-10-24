<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * A player's state: its source, where it is, and how it resamples to the
 * output (mygo's voice); Mixer mixes the voices playing.
 * @internal
 */
final class Voice
{
    public bool $mono;
    /** As the API sees it; in the mixer's voices, which it stays in while fading out; starting at its start (no fade in); resuming in the middle (a fade in from silence); its Player gone (closed at its end). */
    public bool $playing = false, $active = false, $snap = false, $fadeIn = false, $closed = false, $orphan = false;
    public float $volume = 1.0, $pan = 0.0, $speed = 1.0;
    public bool $loop = false;
    /** The frames looped, $loopEnd 0 for the end. */
    public int $loopStart = 0, $loopEnd = 0;
    /** The four source frames the output is interpolated between (left, right each), the second at $idx, and how far the output is past it; the frame after the last once the source ended, or -1. */
    private float $h0l = 0, $h0r = 0, $h1l = 0, $h1r = 0, $h2l = 0, $h2r = 0, $h3l = 0, $h3r = 0;
    public int $idx = 0;
    public float $phase = 0;
    public int $end = -1;
    /** @var list<float> frames read from the source and not yet used */
    private array $in = [];
    private int $inPos = 0, $inLen = 0;
    /** The gains applied, a 2×2 matrix from the source's channels to the output's (ll, rl, lr, rr), ramping to $target. */
    public array $gain = [0.0, 0.0, 0.0, 0.0], $target = [0.0, 0.0, 0.0, 0.0];
    private array $step = [0.0, 0.0, 0.0, 0.0];
    private int $rampLeft = 0;

    /** @param int $length frames, or -1 */
    public function __construct(public Source $src, public float $rate, int $channels, public int $length)
    {
        $this->mono = $channels === 1;
        $this->reset(0);
    }

    /** Starts over at frame $f of its source, which the caller moved there. */
    public function reset(int $f): void
    {
        $this->h0l = $this->h0r = $this->h1l = $this->h1r = $this->h2l = $this->h2r = $this->h3l = $this->h3r = 0.0;
        $this->idx = $f - 3; // three frames come in before the first output: the second is $f
        $this->phase = 3.0;
        $this->end = -1;
        $this->inPos = $this->inLen = 0;
    }

    /** Whether it played to the end of its source. */
    public function ended(): bool
    {
        return $this->end >= 0 && $this->idx >= $this->end;
    }

    /** The source frame it is at. */
    public function position(): float
    {
        $p = max($this->idx + $this->phase, 0.0);
        if ($this->length === 0) { return 0.0; }
        if ($this->loop) {
            $end = $this->loopEnd > 0 ? (float)$this->loopEnd : (float)$this->length;
            $start = (float)$this->loopStart;
            if ($end > $start && $p >= $end) { $p = $start + fmod($p - $start, $end - $start); }
            return $p;
        }
        return $this->length > 0 ? min($p, (float)$this->length) : $p;
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->playing = false;
        $this->src->close();
    }

    /** Shifts in the source's next frame; false while a stream has none decoded yet. */
    private function next(): bool
    {
        $ch = $this->mono ? 1 : 2;
        if ($this->inPos >= $this->inLen) {
            if ($this->end >= 0) { // past the end, silence
                $this->shift(0.0, 0.0);
                return true;
            }
            [$this->in, $ended] = $this->src->read(256);
            $this->inPos = 0; $this->inLen = count($this->in);
            if ($this->inLen === 0) {
                if (!$ended) { return false; }
                $this->end = $this->idx + 3; // the frame coming in would be at idx+3
                $this->shift(0.0, 0.0);
                return true;
            }
        }
        $l = $this->in[$this->inPos];
        $r = $this->mono ? $l : $this->in[$this->inPos + 1];
        $this->inPos += $ch;
        $this->shift($l, $r);
        return true;
    }

    private function shift(float $l, float $r): void
    {
        $this->h0l = $this->h1l; $this->h0r = $this->h1r;
        $this->h1l = $this->h2l; $this->h1r = $this->h2r;
        $this->h2l = $this->h3l; $this->h2r = $this->h3r;
        $this->h3l = $l; $this->h3r = $r;
        $this->idx++;
    }

    /** The matrix of its volume and pan, with the master volume. @return array{0: float, 1: float, 2: float, 3: float} */
    private function gains(float $master): array
    {
        $g = $this->playing ? $this->volume * $master : 0.0;
        if ($this->mono) { // equal power, the middle as decoded
            $x = ($this->pan + 1) / 2 * M_PI / 2;
            return [$g * M_SQRT2 * cos($x), 0.0, 0.0, $g * M_SQRT2 * sin($x)];
        }
        if ($this->pan <= 0) { // Web Audio's StereoPannerNode for stereo input
            $x = ($this->pan + 1) * M_PI / 2;
            return [$g, 0.0, $g * cos($x), $g * sin($x)];
        }
        $x = $this->pan * M_PI / 2;
        return [$g * cos($x), $g * sin($x), 0.0, $g];
    }

    /** Ramps the gains to $t from now. */
    private function retarget(array $t): void
    {
        if ($this->snap) {
            $this->snap = $this->fadeIn = false;
            $this->gain = $this->target = $t; $this->rampLeft = 0;
            return;
        }
        if ($this->fadeIn) {
            $this->fadeIn = false;
            $this->gain = [0.0, 0.0, 0.0, 0.0];
        } elseif ($t === $this->target) {
            return;
        }
        $this->target = $t;
        for ($i = 0; $i < 4; $i++) { $this->step[$i] = ($t[$i] - $this->gain[$i]) / Mixer::RAMP; }
        $this->rampLeft = Mixer::RAMP;
    }

    /**
     * Adds its next frames to $out, stereo, from frame $from to $to, and
     * whether it is still active: false once at its end, or faded out.
     * @param list<float> $out
     */
    public function mix(array &$out, int $from, int $to, float $master): bool
    {
        $this->retarget($this->gains($master));
        $step = $this->rate / Mixer::RATE * $this->speed;
        [$g0, $g1, $g2, $g3] = $this->gain;
        for ($i = 2 * $from, $n = 2 * $to; $i < $n; $i += 2) {
            while ($this->phase >= 1) {
                if (!$this->next()) {
                    $this->gain = [$g0, $g1, $g2, $g3];
                    return true; // the stream is late: silence until it catches up
                }
                $this->phase--;
            }
            if ($this->end >= 0 && $this->idx >= $this->end) {
                $this->playing = false;
                $this->gain = [$g0, $g1, $g2, $g3];
                return false;
            }
            if ($this->rampLeft > 0) {
                $g0 += $this->step[0]; $g1 += $this->step[1]; $g2 += $this->step[2]; $g3 += $this->step[3];
                if (--$this->rampLeft === 0) { [$g0, $g1, $g2, $g3] = $this->target; }
            } elseif (!$this->playing) {
                $this->gain = [$g0, $g1, $g2, $g3];
                return false; // faded out
            }
            // Between the second and third frames, a Catmull-Rom spline.
            $t = $this->phase;
            $p0 = $this->h0l; $p1 = $this->h1l; $p2 = $this->h2l; $p3 = $this->h3l;
            $l = $p1 + 0.5 * $t * ($p2 - $p0 + $t * (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3 + $t * (3 * ($p1 - $p2) + $p3 - $p0)));
            $p0 = $this->h0r; $p1 = $this->h1r; $p2 = $this->h2r; $p3 = $this->h3r;
            $r = $p1 + 0.5 * $t * ($p2 - $p0 + $t * (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3 + $t * (3 * ($p1 - $p2) + $p3 - $p0)));
            $out[$i] += $l * $g0 + $r * $g2;
            $out[$i + 1] += $l * $g1 + $r * $g3;
            $this->phase += $step;
        }
        $this->gain = [$g0, $g1, $g2, $g3];
        return true;
    }
}
