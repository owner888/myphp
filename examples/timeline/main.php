<?php
// What a timeline of keyframes is for (mygo's examples/timeline): several
// values on one playhead, arranged and controlled together, as SwiftUI's
// KeyframeTimeline sampled by a TimelineView. The card slides in as it
// grows, its title follows it, the rows come one after another, and the
// card bounces a little as it lands; Play, Pause, Reverse, Restart and the
// scrubber move the whole intro together. It asks for frames only while
// it plays.
// Run: myphp run -d macos examples/timeline
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const WINDOW_W = 520;
const WINDOW_H = 480;

/** Where the intro's playhead is, and how it goes. */
final class Intro
{
    /** The playhead, in seconds. */
    public float $time = 0.0;
    public bool $paused = false, $reverse = false;
    /** Whether the pointer holds the scrubber: the playhead stays where it puts it. */
    public bool $held = false;
    /** The frame's time the playhead last moved at, in nanoseconds. */
    public ?int $last = null;

    /** The intro: the card's place, size and bounce, its title's fade and its rows', one after another. */
    public static function timeline(): KeyframeTimeline
    {
        $rows = [];
        foreach (['Plan', 'Build', 'Ship'] as $i => $name) {
            $rows[] = KeyframeTrack($name)(LinearKeyframe(0, duration: 0.45 + $i * 0.09), LinearKeyframe(1, duration: 0.24));
        }
        return new KeyframeTimeline(['x' => -420.0, 'scale' => 0.9, 'fade' => 0.0, 'y' => 10.0, 'Plan' => 0.0, 'Build' => 0.0, 'Ship' => 0.0], [
            KeyframeTrack('x')(LinearKeyframe(0, duration: 0.45, timingCurve: UnitCurve::easeOut)),
            KeyframeTrack('scale')(LinearKeyframe(1, duration: 0.45, timingCurve: UnitCurve::easeOut)),
            KeyframeTrack('fade')(LinearKeyframe(0, duration: 0.15), LinearKeyframe(1, duration: 0.3)),
            KeyframeTrack('y')(LinearKeyframe(10, duration: 0.45), SpringKeyframe(0, duration: 0.36, spring: Animation::bouncy())),
            ...$rows,
        ]);
    }

    /** Moves the playhead to the frame at $now, unless paused or held, within the intro's $duration; whether it still plays. */
    public function advance(int $now, float $duration): bool
    {
        if ($this->paused || $this->held) { $this->last = null; return false; }
        $dt = $this->last === null ? 0.0 : ($now - $this->last) / 1e9;
        $this->last = $now;
        $this->time = max(0.0, min($duration, $this->time + ($this->reverse ? -$dt : $dt)));
        $done = $this->reverse ? $this->time <= 0 : $this->time >= $duration;
        if ($done) { $this->last = null; }
        return !$done;
    }
}

$s = new Intro();
App::setName('Timeline');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Timeline', width: WINDOW_W, height: WINDOW_H, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
