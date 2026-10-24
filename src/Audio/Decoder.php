<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Decodes audio as interleaved samples in [-1, 1], one per channel for
 * each frame (mygo's audio.Decoder). Formats registered with
 * Audio::registerFormat return one; implement one to play sound an app
 * makes itself, as a synthesizer. A decoder with a close() method is
 * closed with the player that plays it.
 */
interface Decoder
{
    /** The frames per second. */
    public function sampleRate(): int;

    /** The samples per frame: 1 for mono, 2 for stereo (left, right); later channels of more are not played. */
    public function channels(): int;

    /**
     * Decodes the next frames, at most $frames of them, as interleaved
     * samples; an empty list at the end. It throws when the audio cannot
     * be decoded, which ends it.
     * @return list<float>
     */
    public function read(int $frames): array;

    /** Moves to frame $frame, counted from the start; it throws where it cannot. */
    public function setPosition(int $frame): void;

    /** The frames from the start to the end, or -1 if unknown. */
    public function len(): int;
}
