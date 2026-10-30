<?php
declare(strict_types=1);

namespace MyPHP\UI;

use RuntimeException;
use Throwable;
use WeakReference;

/**
 * Plays a video, for Context::video to show (mygo's VideoPlayer): its
 * frames decode as the playhead reaches them, and each reaches the screen
 * through its Image's update() as it comes due, frames passed over when the
 * next is due already. A new player plays as soon as a video node shows
 * it; show it in one at a time. Make players once, not in the view, as
 * images.
 *
 *     $app->waves = (new VideoPlayer(GifDecoder::fromFile(__DIR__ . '/waves.gif')))->setLoop(true);
 *     // In the view:
 *     $c->video($app->waves)->grow(1);
 */
final class VideoPlayer
{
    private Image $image;
    /** The first frame, kept for less motion, which shows it, and for playing again; the frame the playhead ($pos, seconds) is in, which ends at $end; the one the image shows. */
    private VideoFrame $first, $cur, $shows;
    private float $pos = 0.0, $end;
    /** When the frame that last built it began (the runtime's clock), of which runtime and which frame, and whether the playhead moved on in it. */
    private int $at = 0, $frame = -1;
    private ?WeakReference $rt = null;
    private bool $moved = false, $paused = false, $ended = false, $closed = false, $loop = false;
    /** That it plays again from its start: the decoder rewinds, past the first frame, which the player keeps. */
    private bool $again = false;
    private ?Throwable $err = null;

    /** A player of $d's frames, playing: it decodes the first frame at once, which the node shows, and which throws for a video that does not decode. Closing the player closes $d when it has close(). */
    public function __construct(private readonly VideoDecoder $d)
    {
        $f = $d->next() ?? throw new RuntimeException('myphp: a video of no frames');
        $this->first = $this->cur = $this->shows = $f;
        $this->end = self::duration($f);
        $this->image = Image::fromPixels($f->w, $f->h, $f->rgba);
    }

    /** Plays the video again from its start each time it ends, without a gap; returns the player. */
    public function setLoop(bool $loop): self
    {
        $this->loop = $loop;
        return $this;
    }

    public function loop(): bool
    {
        return $this->loop;
    }

    /** Goes on from where it was paused, or plays it again from its start once it ended. */
    public function play(): void
    {
        $this->paused = false;
        if ($this->ended && !$this->closed) {
            [$this->ended, $this->err, $this->again] = [false, null, true];
            [$this->cur, $this->pos, $this->end] = [$this->first, 0.0, self::duration($this->first)];
        }
    }

    /** Holds it at the frame it shows. */
    public function pause(): void
    {
        $this->paused = true;
    }

    /** Whether it plays: neither paused nor ended. It plays out of sight too, though it does not move on then. */
    public function playing(): bool
    {
        return !$this->paused && !$this->ended;
    }

    /** The size of its frames in pixels. @return array{0: int, 1: int} */
    public function size(): array
    {
        return $this->image->size();
    }

    /** What stopped it before its end, as a frame that did not decode: it shows the frame before. */
    public function err(): ?Throwable
    {
        return $this->err;
    }

    /** Stops decoding, and closes the decoder when it has close(); it shows the frame it was at. */
    public function close(): void
    {
        if ($this->closed) { return; }
        [$this->closed, $this->ended] = [true, true];
        if (method_exists($this->d, 'close')) { $this->d->close(); }
    }

    /**
     * The image it shows, its playhead moved on by the time it played since
     * the frame that last built it, once a frame: the last of the frames
     * that came due shows, the others passed over, so that it keeps to the
     * clock. Only time it showed moves it: since the frame before, which
     * built it playing too, and since some of the window showed again; out
     * of sight, or with less motion asked for (the first frame then), it
     * stands (mygo's advance).
     * @internal Context::video
     */
    public function advance(Context $c): Image
    {
        $rt = $c->runtime;
        if ($rt === null) { return $this->image; }
        $now = $rt->now;
        $same = $this->rt?->get() === $rt;
        if ($same && $this->frame === $rt->frameNumber) { return $this->image; } // another pass of the frame
        $from = max($this->at, $rt->shownAt);
        if ($this->moved && $same && $this->frame + 1 === $rt->frameNumber && $now > $from) {
            $this->pos += ($now - $from) / 1e9;
        }
        [$this->rt, $this->frame, $this->at] = [WeakReference::create($rt), $rt->frameNumber, $now];
        if ($rt->reduceMotion) {
            $this->moved = false;
            $this->show($this->first);
            return $this->image;
        }
        $this->moved = !$this->paused && !$this->ended && !$rt->occluded;
        if (!$this->moved) {
            if (!$this->paused && !$this->ended) { $c->after(max($this->end - $this->pos, 0.0)); } // out of sight: the frame waits for the window to show
            $this->show($this->cur);
            return $this->image;
        }
        while ($this->pos >= $this->end) {
            try {
                $f = $this->decode();
            } catch (Throwable $e) {
                [$this->ended, $this->moved, $this->err] = [true, false, $e];
                break;
            }
            if ($f === null) {
                [$this->ended, $this->moved] = [true, false];
                break;
            }
            $this->cur = $f;
            $this->end += self::duration($f);
        }
        $this->show($this->cur);
        if ($this->moved && $this->pos < $this->end) { $c->after($this->end - $this->pos); }
        return $this->image;
    }

    /** The next frame: after the first once it plays again, from the start again at the end of a loop. */
    private function decode(): ?VideoFrame
    {
        if ($this->again) {
            $this->again = false;
            $this->d->rewind();
            $this->d->next(); // the first, which the player keeps
        }
        $f = $this->d->next();
        if ($f === null && $this->loop) {
            $this->d->rewind();
            $f = $this->d->next();
        }
        return $f;
    }

    private function show(VideoFrame $f): void
    {
        if ($f === $this->shows) { return; }
        $this->shows = $f;
        $this->image->update($f->w, $f->h, $f->rgba);
    }

    private static function duration(VideoFrame $f): float
    {
        return $f->duration > 0 ? $f->duration : 0.1;
    }
}
