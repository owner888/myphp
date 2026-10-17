<?php
declare(strict_types=1);

namespace MyPHP\UI;

use InvalidArgumentException;

/**
 * Several tweens along one playhead (mygo's Timeline), so that motion that
 * belongs together is written, controlled and tested together: a card
 * arriving while its title fades in, rows following one another, a small
 * bounce after the card lands. A tween returns the value it shows in this
 * frame, which the view gives to a node as it would one of animateTo's.
 *
 *     $card = $c->column();
 *     $tl = $c->timeline($card, 'intro', new TimelineOptions(run: $app->opened));
 *     $x = $tl->tween(new Tween(-200, 0, 0.4));
 *     $y = $tl->tween(new Tween(20, 0, 0.3), Placement::afterPrevious(-0.15));
 *
 * A node keeps its timelines as it keeps its keyframes, from frame to frame
 * while the view builds it, and they go with it: build a timeline in the
 * same order every frame, and change the run to play it again, or a
 * changed one. A Timeline is good only while the view builds the frame it
 * was returned in: ask for it again in the next. One of a view without a
 * runtime (a view measured alone) shows each tween's $from.
 */
final class Timeline
{
    /** @var list<array{0: int, 1: int, 2: float, 3: float, 4: ?\Closure}> this pass's tweens: start, duration, from, to, ease */
    private array $segs = [];
    private int $prevStart = 0, $prevEnd = 0;

    public function __construct(private ?Runtime $rt, private ?TimelineState $st, TimelineOptions $o)
    {
        if ($st !== null) {
            $this->begin($o);
        }
    }

    /**
     * Adds $tw where $at places it, following the tween before it at once
     * with no placement, and returns the value it shows in this frame: its
     * from before its time, its to after it.
     */
    public function tween(Tween $tw, ?Placement $at = null): float
    {
        Motion::finite('tween', $tw->from, $tw->to);
        if ($tw->duration < 0) {
            throw new InvalidArgumentException('animation durations must not be negative');
        }
        $st = $this->st;
        if ($st === null) {
            return $tw->from;
        }
        $start = $this->place($at);
        $dur = Motion::ns($tw->duration);
        $seg = [$start, $dur, $tw->from, $tw->to, $tw->ease];
        $this->segs[] = $seg;
        if (($end = $start + $dur) > $st->build) {
            $st->build = $end;
            if ($st->done && !$st->over && !$st->reverse && !$st->holding && $end > $st->duration) {
                // The view arranges a timeline that reaches past the one the playhead came to rest at the end of:
                // it plays on from there, rather than rest, and is unfinished until it has played.
                $st->moving = true;
                $this->rt->animate();
            }
        }
        $this->prevStart = $start; $this->prevEnd = $start + $dur;
        return $this->show($seg);
    }

    /** Marks a time of the timeline for a tween to start at with Placement::atLabel; with no placement, where the tween before it ends. */
    public function label(string $name, ?Placement $at = null): void
    {
        if ($this->st !== null) {
            $this->st->labels[] = [$name, $this->place($at)];
        }
    }

    /** How long the timeline plays, in seconds: the end of its last tween, without its repeats. */
    public function duration(): float
    {
        return $this->durationNs() / 1e9;
    }

    /** Where the playhead is, in seconds from the start of the timeline. */
    public function time(): float
    {
        $st = $this->st;
        if ($st === null) {
            return 0.0;
        }
        if ($st->over) {
            return $st->atStart ? 0.0 : $this->duration();
        }
        return min($st->t, $this->durationNs()) / 1e9;
    }

    /** How far the playhead is along the timeline, from 0 to 1. */
    public function progress(): float
    {
        $st = $this->st;
        if ($st === null) {
            return 0.0;
        }
        $d = $this->durationNs();
        if ($st->over || ($st->done && $d <= 0)) {
            return $st->atStart ? 0.0 : 1.0;
        }
        if ($d <= 0) {
            return 0.0;
        }
        return min(max($st->t / $d, 0.0), 1.0);
    }

    /** Whether the timeline rests, at its end with its repeats played out, or at its start while it plays in reverse, as Context::settled does for a value. */
    public function settled(): bool
    {
        $st = $this->st;
        if ($st === null || !$st->done) {
            return false;
        }
        if ($st->over || $st->reverse) {
            return true;
        }
        // A view that arranges a longer timeline than the one the playhead came to rest at the end of has not finished: the frame that plays it is coming.
        return $st->build <= $st->duration;
    }

    /** True in the one frame in which the timeline came to rest, for the view to go on, as Context::arrived is. */
    public function arrived(): bool
    {
        return $this->st !== null && $this->st->rested !== 0 && $this->st->rested === $this->rt->frameNumber;
    }

    private function durationNs(): int
    {
        return $this->st === null ? 0 : max($this->st->build, $this->st->duration);
    }

    /** Where a tween or label starts. */
    private function place(?Placement $p): int
    {
        $pos = match ($p?->kind ?? Placement::AFTER) {
            Placement::WITH => $this->prevStart + $p->at,
            Placement::AT => $p->at,
            Placement::LABEL => $this->labelAt($p->label) + $p->at,
            default => $this->prevEnd + ($p?->at ?? 0),
        };
        // Nothing starts before the timeline does: an overlap larger than what came before it starts at zero.
        return max($pos, 0);
    }

    /** Where a label of this frame's timeline was marked. */
    private function labelAt(string $name): int
    {
        for ($i = count($this->st->labels) - 1; $i >= 0; $i--) {
            if ($this->st->labels[$i][0] === $name) {
                return $this->st->labels[$i][1];
            }
        }
        throw new InvalidArgumentException("a Timeline has no label $name");
    }

    /** The value a tween shows at this frame's playhead: 0 to 1 of its time along its ease, between its from and its to. */
    private function show(array $s): float
    {
        [$start, $dur, $from, $to, $ease] = $s;
        $st = $this->st;
        if ($st->over) {
            // Less motion: every tween is where the timeline comes to rest at once, at its end, or at its start when it plays back to it.
            return $st->atStart ? $from : $to;
        }
        $now = $st->t;
        if ($dur <= 0) {
            // A tween that takes no time is where it ends as soon as its time comes, at the end of the timeline as anywhere else.
            return $now >= $start ? $to : $from;
        }
        if ($now <= $start) {
            return $from;
        }
        if ($now >= $start + $dur) {
            return $to;
        }
        $k = ($now - $start) / $dur;
        return $from + ($to - $from) * ($ease === null ? Easing::easeOut($k) : $ease($k));
    }

    /**
     * Starts a frame's build of the timeline: at the start of a frame it
     * takes what the last one built and moves the playhead, and at the
     * start of a pass it starts the tweens and labels over, as building
     * always does.
     */
    private function begin(TimelineOptions $o): void
    {
        $st = $this->st; $rt = $this->rt;
        if ($st->frame !== $rt->frameNumber) {
            $st->frame = $rt->frameNumber; $st->pass = $rt->pass;
            $st->duration = $st->build; $st->build = 0;
            $st->labels = [];
            $this->tick($o);
            return;
        }
        if ($st->pass !== $rt->pass) {
            $st->pass = $rt->pass;
            $st->build = 0;
            $st->labels = [];
        }
        // The clock of this frame moved once already: what the options say now still decides what the pass shows,
        // so a handler that paused, reversed or played the timeline over shows it in the frame it ran in.
        $this->cue($o);
    }

    /** Moves the playhead on by this frame's time, once a frame, in the direction the last frame played it. */
    private function tick(TimelineOptions $o): void
    {
        $st = $this->st; $now = $this->rt->now;
        if (!$st->started) {
            $st->started = true; $st->at = $now; $st->run = $o->run;
            $this->cue($o);
            return;
        }
        $st->built = true;
        $dt = $now - $st->at;
        $st->at = $now;
        // Only time the timeline played moves it: a paused, held or finished timeline asks for no frames,
        // so the frame that lets it go carries the whole wait in its delta and would jump the playhead by it.
        if ($dt > 0 && $st->moving) {
            if ($st->reverse) {
                $st->raw = max($st->raw - $dt, min($st->raw, Motion::ns($o->delay))); // back to where it starts playing, not into its delay
            } else {
                $st->raw += $dt;
            }
        }
        $this->cue($o);
    }

    /** Puts the playhead where this pass's options ask, and asks for the frame that goes on as long as the timeline moves. */
    private function cue(TimelineOptions $o): void
    {
        $st = $this->st; $rt = $this->rt;
        $delay = Motion::ns($o->delay);
        if ($st->run !== $o->run) {
            // The run plays the timeline over, in the frame it changes in: from its start, or back from its end.
            $st->run = $o->run; $st->raw = 0; $st->done = false; $st->over = false; $st->rested = 0;
            if ($o->reverse) {
                $st->raw = $delay + ($st->end($o) ?? $st->duration);
            }
        }
        $st->reverse = $o->reverse;
        $held = false;
        if ($o->seek !== null) {
            $st->raw = $delay + Motion::ns($o->seek); $held = true;
        }
        if ($o->progress !== null) {
            $st->raw = $delay + (int)($o->progress * $st->duration); $held = true;
        }
        if ($held) {
            $st->over = false; // a scrubber puts the playhead where it asks, also on a timeline that less motion put at once where it comes to rest
        } elseif (!$o->paused && $rt->reduceMotion) {
            // A timeline that would play is over where it would have come to rest, in this frame;
            // what the view asks of its playhead, holding it or putting it somewhere, it still does.
            $st->over = true;
        }
        if ($st->over) {
            // Where keyframes end: back at the start when it plays back to it, or alternates an even number of times.
            $st->atStart = $o->reverse || ($o->alternate && ($o->repeat + 1) % 2 === 0);
            $st->t = $st->atStart ? 0 : $st->duration;
            $st->done = true; $st->moving = false; $st->holding = true;
            if ($st->rested === 0) {
                $st->rested = $rt->frameNumber;
            }
            return;
        }
        // Nothing plays past the end, so that playing back, or a longer timeline, goes on from it at once.
        if (($end = $st->end($o)) !== null) {
            $st->raw = min($st->raw, $delay + $end);
        }
        $st->raw = max($st->raw, 0);
        $raw = $st->raw - $delay;
        [$st->t, , $st->done] = Motion::playhead($raw, $st->duration, $o->repeat, $o->alternate, Motion::ns($o->repeatDelay));
        if ($raw > 0 && $st->duration <= 0) {
            // A timeline whose tweens take no time is over as soon as a frame has gone by: the frame it is first built in has no duration yet.
            $st->done = $st->built;
        }
        if ($o->reverse) {
            $st->done = $raw <= 0;
        }
        $st->holding = $o->paused || $held;
        $st->moving = !$st->done && !$st->holding;
        if ($st->done) {
            if ($st->rested === 0) {
                $st->rested = $rt->frameNumber;
            }
            return;
        }
        $st->rested = 0; // it moves again, so the next time it comes to rest is an arrival of its own
        if ($held && !$st->built) {
            // A part of the duration, or a time, asked for in the frame the timeline is first built in:
            // it has no duration to take a part of yet, so ask for the frame that knows one.
            $rt->animate();
            return;
        }
        if ($st->moving) {
            $rt->animate();
        }
    }
}
