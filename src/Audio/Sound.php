<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Audio decoded into memory, for effects that play often and over
 * themselves: each play() is a new Player (mygo's audio.Sound). Its
 * samples are kept as 32-bit floats, as mygo's.
 */
final class Sound
{
    /** @internal $samples: interleaved 32-bit floats, little-endian */
    public function __construct(public readonly string $samples, public readonly int $channels, public readonly int $rate) {}

    /** The frames it holds. */
    public function frames(): int
    {
        return intdiv(strlen($this->samples), 4 * $this->channels);
    }

    /** How long it plays, in seconds. */
    public function duration(): float
    {
        return $this->frames() / $this->rate;
    }

    /** A paused player of the sound. */
    public function newPlayer(): Player
    {
        return new Player(new MemSource($this->samples, $this->channels), $this->rate, $this->channels, $this->frames());
    }

    /** Plays the sound from its start, over any other of its players, and returns the player playing it. */
    public function play(): Player
    {
        $p = $this->newPlayer();
        $p->play();
        return $p;
    }
}
