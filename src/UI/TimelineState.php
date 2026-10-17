<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What a Timeline keeps between frames (mygo's timelineState): how long
 * its playhead has run, and the labels its last frame built, which this
 * frame's playhead folds around. A node keeps one per key, in
 * Runtime::$timelines, and it goes with the node. Times in nanoseconds.
 */
final class TimelineState
{
    /** The node that last asked for it, to catch it asking twice. */
    public ?Node $node = null;
    /** The frame the playhead last moved in, and the pass it was built in, to build once per pass. */
    public int $frame = 0, $pass = 0;
    /** The time of that frame's clock. */
    public int $at = 0;
    /** Whether it has a time to measure from; whether a frame before this one built it. */
    public bool $started = false, $built = false;
    /** Whether the last pass played it, so the time to the next frame is played; played it backward; paused or held it. */
    public bool $moving = false, $reverse = false, $holding = false;
    /** The run it plays, to restart when it changes. */
    public int $run = 0;
    /** Where the playhead is through its repeats, its delay included. */
    public int $raw = 0;
    /** The last frame's duration, which folds this one, and the duration this frame is building. */
    public int $duration = 0, $build = 0;
    /** Where the playhead is. */
    public int $t = 0;
    /** It came to rest; less motion settled it at once; where it settled is its start. */
    public bool $done = false, $over = false, $atStart = false;
    /** The frame it came to rest in, for arrived(). */
    public int $rested = 0;
    /** @var list<array{0: string, 1: int}> the labels this pass marked: name, at */
    public array $labels = [];

    /** How long the timeline plays with its repeats; null when it repeats for ever or its duration is not known yet. */
    public function end(TimelineOptions $o): ?int
    {
        if ($o->repeat === KeyframeOptions::REPEAT_FOREVER || $this->duration <= 0) {
            return null;
        }
        $plays = $o->repeat + 1;
        return $plays * $this->duration + ($plays - 1) * Motion::ns($o->repeatDelay);
    }
}
