<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Plays a sound or a stream: its position, volume, pan, speed and looping
 * are its own (mygo's audio.Player). Times are in seconds. The setters
 * return the player, to chain them. A stream's player dropped while
 * paused closes; one dropped while playing plays to its end.
 */
final class Player
{
    /** @internal */
    public readonly Voice $v;

    /** @internal */
    public function __construct(Source $src, int $rate, int $channels, int $length)
    {
        $this->v = new Voice($src, (float)$rate, min($channels, 2), $length);
    }

    public function __destruct()
    {
        if ($this->v->active) { $this->v->orphan = true; } else { $this->v->close(); }
    }

    /** Plays from where it is, or from the start if it ended. */
    public function play(): void
    {
        $v = $this->v;
        if ($v->closed || $v->playing) { return; }
        if ($v->ended()) {
            $v->src->seek(0);
            $v->reset(0);
        }
        $v->playing = true;
        if (!$v->active) {
            // A sound starting at its start starts at once; one resuming in the middle fades in, as one pausing fades out, without a click. One still fading out ramps back from where it is.
            $v->snap = $v->idx + 3 <= 0;
            $v->fadeIn = !$v->snap;
            $v->active = true;
            Mixer::$voices[] = $v;
            Output::wake();
        }
    }

    /** Pauses it where it is. */
    public function pause(): void
    {
        $this->v->playing = false;
    }

    /** Pauses it and moves it to the start. */
    public function stop(): void
    {
        $v = $this->v;
        if ($v->closed) { return; }
        $v->playing = false;
        $v->src->seek(0);
        $v->reset(0);
    }

    /** Whether it plays: false once paused, stopped, closed, or at its end. */
    public function playing(): bool
    {
        return $this->v->playing;
    }

    /** Moves it to $seconds from the start. */
    public function seek(float $seconds): void
    {
        $v = $this->v;
        if ($v->closed) { return; }
        $f = max((int)round($seconds * $v->rate), 0);
        if ($v->length >= 0) { $f = min($f, $v->length); }
        $v->src->seek($f);
        $v->reset($f);
    }

    /**
     * How far it is from the start, in seconds, in what is heard: the
     * output mixes ahead of it (Audio::setLatency), and it goes on for as
     * long after a pause, but it is where seek() moved it at once.
     */
    public function position(): float
    {
        return $this->v->position($this->v->heardAt()) / $this->v->rate;
    }

    /** How long its audio is, in seconds, or 0 if unknown. */
    public function duration(): float
    {
        return $this->v->length < 0 ? 0.0 : $this->v->length / $this->v->rate;
    }

    /** Sets its volume, a gain: 1 (the default) as decoded, 0 silent. Changes ramp over a few milliseconds, without a click. */
    public function setVolume(float $v): self
    {
        $this->v->volume = max($v, 0.0);
        return $this;
    }

    public function volume(): float
    {
        return $this->v->volume;
    }

    /** Places it between the left speaker (-1) and the right (1); 0, the default, is the middle. A mono sound pans keeping its power; a stereo one keeps both channels, as Web Audio's StereoPannerNode does. */
    public function setPan(float $pan): self
    {
        $this->v->pan = min(max($pan, -1.0), 1.0);
        return $this;
    }

    public function pan(): float
    {
        return $this->v->pan;
    }

    /** Sets how fast it plays, which changes its pitch with it, as a record's speed does: 1 (the default) as decoded, 2 twice as fast and an octave higher. */
    public function setSpeed(float $s): self
    {
        if ($s > 0 && is_finite($s)) { $this->v->speed = min($s, 16.0); }
        return $this;
    }

    public function speed(): float
    {
        return $this->v->speed;
    }

    /** Sets whether it starts again from the start when it ends, without a gap, or from its loop's start (setLoopRange). A change is heard after what it decoded ahead: a few milliseconds of a sound, up to a second of a stream. */
    public function setLoop(bool $loop): self
    {
        $v = $this->v;
        if (!$v->closed) {
            $v->loop = $loop;
            $v->src->setLoop($loop, $v->loopStart, $v->loopEnd);
        }
        return $this;
    }

    /** Loops it between $start and $end seconds, without a gap, $end 0 being the end of its audio: what is before $start, as a tune's intro, plays once. It sets it looping; setLoop(false) plays on past $end, as far as the end. */
    public function setLoopRange(float $start, float $end): self
    {
        $v = $this->v;
        if ($v->closed) { return $this; }
        $s = max((int)round($start * $v->rate), 0);
        $e = max((int)round($end * $v->rate), 0);
        if ($v->length >= 0) { $s = min($s, $v->length); $e = min($e, $v->length); }
        if ($e !== 0 && $e <= $s) { $s = $e = 0; } // nothing to loop: all of it
        $v->loopStart = $s; $v->loopEnd = $e; $v->loop = true;
        $v->src->setLoop(true, $s, $e);
        return $this;
    }

    public function loop(): bool
    {
        return $this->v->loop;
    }

    /** Stops it for good and frees what it decodes with. A sound's players need no closing. */
    public function close(): void
    {
        $this->v->playing = false;
        $this->v->close();
    }
}
