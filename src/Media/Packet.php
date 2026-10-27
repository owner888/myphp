<?php
declare(strict_types=1);

namespace MyPHP\Media;

/** A frame of a track as stored, encoded: what a decoder takes, and when it shows. */
final class Packet
{
    public function __construct(
        public readonly Track $track,
        /** When it shows, in seconds from the start. */
        public readonly float $time,
        /** How long it shows, in seconds: 0 when the file does not say. */
        public readonly float $duration,
        /** Whether decoding can start at it. */
        public readonly bool $keyframe,
        /** The frame's bytes as stored; empty when $sample holds them. */
        public readonly string $data,
        /** @internal a system reader's sample buffer of the frame (Native\SampleBuffer), which goes to the renderers as it is */
        public readonly ?object $sample = null,
    ) {}
}
