<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * How a timeline plays (mygo's TimelineOptions). What it changes applies to
 * the frame it is given in, except $run, which plays the timeline over when
 * it changes, as KeyframeOptions::$run does.
 */
final class TimelineOptions
{
    public function __construct(
        /** Restarts the timeline when it changes, so that a view plays it again by counting. */
        public readonly int $run = 0,
        /** How many more times the timeline plays after the first, KeyframeOptions::REPEAT_FOREVER for as long as the view builds it. */
        public readonly int $repeat = 0,
        /** Plays every other time backward. */
        public readonly bool $alternate = false,
        /** Seconds the timeline holds its first value before it plays, and the value it got to between plays. */
        public readonly float $delay = 0.0,
        public readonly float $repeatDelay = 0.0,
        /** Holds the playhead where it is: the time a paused timeline spends is not time it played, and it goes on from where it held. */
        public readonly bool $paused = false,
        /** Plays the timeline backward from where the playhead is, to its start, as a menu that closes the way it opened; a timeline that $run plays over while reversed plays from its end. */
        public readonly bool $reverse = false,
        /** Holds the playhead at a time of the timeline, in seconds, or $progress at a part of its duration, for a scrubber or a jump: while either is set the timeline does not play, and it goes on from there when it is not. Set one of them at most. */
        public readonly ?float $seek = null,
        public readonly ?float $progress = null,
    ) {}
}
