<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\Logger;
use MyPHP\Media\{AudioFilters, Packet, Player, Track};
use MyPHP\Native\{AvDecoder, AvResampler, AvScaler, Equalizer, Pcm, Zlib};
use MyPHP\Platform;
use MyPHP\UI\Image;

/**
 * What decodes and plays a Player's packets on Linux, as Native\Renderers
 * does on macOS, on the main thread in slices: libavcodec decodes them
 * as they are given (Native\AvDecoder; its frame threads decode video
 * while the packets are sent, the frames received later), the sound is
 * made 48 kHz stereo floats by libswresample and written to PulseAudio
 * (or ALSA: Native\Pcm) 10 ms at a time from a timer, only what the
 * device takes then (it holds AHEAD seconds), never waiting on it. The
 * sound is the clock, as AVSampleBufferRenderSynchronizer's: each tick
 * reads where the device is (what was written less what it holds) and
 * sets the clock there, which runs on the system's clock between ticks,
 * and where no sound plays (a film of none, its end, no device, a rate
 * but 1). The video's frames wait decoded (at most FRAMES of them) until
 * the clock is at their time; the one due is converted to RGBA by
 * libswscale (Native\AvScaler) into an image (frame()) that Context::movie's
 * node draws with the window's renderer, the windows showing it asked to
 * paint again. A frame decoded for those after it (enqueue's $show off,
 * a seek's from its keyframe) is dropped as it comes out, by its time.
 *
 * @internal
 */
final class Renderers implements Platform\Renderers
{
    public ?\Closure $videoWanted = null;
    public float $audioDelay = 0.0;

    /** The sound written: its rate and channels; the seconds the device holds of it; the seconds decoded ahead of the clock at most. */
    private const RATE = 48000, CHANNELS = 2, AHEAD = 0.2, DECODED = 3.0;
    /** Seconds between the ticks that write the sound and show the frames due, while the clock runs. */
    private const TICK = 0.01;
    /** The decoded frames of the video kept ahead, the one shown among them. */
    private const FRAMES = 8;
    /** Seconds of the main thread the video's packets take at a time (Native\Renderers' SLICE), the rest a POLL on. */
    private const SLICE = 0.010, POLL = 0.004;
    /** The largest side of the picture shown, in pixels: a page of the image atlas (UI\ImageAtlas), which a larger one is scaled to fit. */
    private const LARGEST = 2048;
    /** A frame shows this early, so that one due right after a tick does not wait for the next. */
    private const EARLY = 0.004;

    /** @var array<int, AvDecoder> the decoders, by track number */
    private array $decoders = [];
    /** @var array<int, AvResampler> the audio tracks' conversions, by track number */
    private array $resamplers = [];
    private AvScaler $scaler;
    /** @var list<array{0: float, 1: CData}> the video's frames decoded and not passed, their times and AVFrame*s, in order: the first the one shown, once one is */
    private array $pictures = [];
    /** @var array<int, true> the times (microseconds) of the packets given not to be shown, whose frames are dropped */
    private array $hidden = [];
    /** The picture shown, and the AVFrame* it was made of (null after a flush: the first frame decoded shows, whenever it is due). */
    private ?Image $image = null;
    private ?CData $shown = null;
    private bool $fresh = true;
    /** When the video's slice of the main thread started (hrtime), and the timer of a poll after it. */
    private int $burst = 0, $pollTimer = 0;

    /** The clock: its rate, its time at hrtime $since. */
    private float $rate = 0.0, $base = 0.0;
    private int $since = 0;
    /** The tick's timer while the clock runs. */
    private int $timer = 0;

    /** The sound's output, opened at the first audio packet; why it did not open. */
    private ?Pcm $pcm = null;
    private ?string $pcmFailed = null;
    /** The sound decoded and not heard: its bytes, the clock's time of its first frame (null: none since a flush), the frames of it written. */
    private string $sound = '';
    private ?float $soundAt = null;
    private int $sent = 0;
    private float $volume = 1.0, $gain = 1.0;
    private bool $muted = false, $rateWarned = false;
    private ?string $failed = null;

    public function __construct(private bool $hardware = true)
    {
        $this->scaler = new AvScaler();
    }

    public function ready(Track $t): bool
    {
        if ($t->type === Track::VIDEO) {
            $this->given = true; // the player holds video yet: no end told at the next pump
            if (hrtime(true) - $this->burst > self::SLICE * 1e9) {
                $this->poll(); // the slice spent: on soon, the window drawn meanwhile
                return false;
            }
            return count($this->pictures) < self::FRAMES; // present() asks for more as it passes them
        }
        return $this->soundAt === null || $this->soundAt + strlen($this->sound) / (self::CHANNELS * 4) / self::RATE - $this->time() < self::DECODED;
    }

    /** Has the video queued given again in POLL seconds (videoWanted). */
    private function poll(): void
    {
        if ($this->pollTimer !== 0) {
            return;
        }
        $this->pollTimer = Gtk::timeout(self::POLL, $this->later(static function (self $r): void {
            $r->pollTimer = 0;
            if ($r->videoWanted !== null) { ($r->videoWanted)(); }
        }));
    }

    public function slice(): void
    {
        $this->burst = hrtime(true);
    }

    /**
     * At the end of the film ($drain), once the player gave the video all
     * its packets (none given nor asked to be taken since the pump before:
     * the player pumps before it gives, each feed), the decoders told the
     * end, their frames out (those a frame thread or the reordering held).
     * An end told takes no packet more till a flush.
     */
    public function pump(bool $drain): void
    {
        if ($drain && !$this->given && !$this->ended) {
            $this->ended = true;
            foreach ($this->decoders as $d) {
                if ($d->track->type === Track::VIDEO && $d->send(null)) { $this->received($d); }
            }
        }
        $this->given = false;
    }

    /** Whether video was given, or offered, since the last pump; whether the decoders were told the end. */
    private bool $given = false, $ended = false;

    public function enqueue(Packet $p, bool $show = true): void
    {
        $t = $p->track;
        try {
            $d = $this->decoders[$t->number] ??= new AvDecoder($t, $t->type === Track::VIDEO ? Player::$videoThreads : 1);
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
            return;
        }
        if ($t->type === Track::VIDEO && !$show) {
            $this->hidden[(int)round($p->time * \MyPHP\Native\Av::SCALE)] = true;
        }
        if (!$d->send($p)) { // its frames to be taken first
            $this->received($d);
            $d->send($p);
        }
        $this->received($d);
    }

    /** The frames $d decoded so far: the video's kept to be shown (the first after a flush at once), the audio's made the device's PCM and kept to be written. */
    private function received(AvDecoder $d): void
    {
        $video = $d->track->type === Track::VIDEO;
        try {
            while (($f = $d->receive()) !== null) {
                $time = AvDecoder::time($f);
                if ($video) {
                    $key = $time === null ? null : (int)round($time * \MyPHP\Native\Av::SCALE);
                    if ($time === null || isset($this->hidden[$key])) {
                        unset($this->hidden[$key]);
                        AvDecoder::release($f);
                        continue;
                    }
                    $this->pictures[] = [$time, $f];
                    continue;
                }
                $r = $this->resamplers[$d->track->number] ??= new AvResampler(self::RATE, self::CHANNELS);
                [$pcm, $n] = $r->convert($f);
                AvDecoder::release($f);
                if ($n > 0 && $time !== null) { $this->heard($pcm, $time + $this->audioDelay); }
            }
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
        }
        if ($video && $this->fresh) { $this->present(false); } // as a seek lands, paused too: not asking for more from within a give
    }

    /** Sound $pcm decoded, which plays at $at on the clock, kept to be written: after what is kept, a gap filled with silence. */
    private function heard(string $pcm, float $at): void
    {
        if ($this->pcm === null && $this->pcmFailed === null) {
            try {
                $this->pcm = Pcm::open(self::RATE, self::CHANNELS, self::AHEAD, \MyPHP\App::name());
                Logger::debug("the film's sound plays through {$this->pcm->system}", 'media');
            } catch (\RuntimeException $e) {
                $this->pcmFailed = $e->getMessage();
                Logger::warn($e->getMessage() . ': the film plays without its sound, on the system\'s clock', 'media');
            }
        }
        if ($this->soundAt === null) {
            [$this->sound, $this->soundAt, $this->sent] = [$pcm, $at, 0];
            return;
        }
        $gap = (int)round(($at - $this->soundAt) * self::RATE) - intdiv(strlen($this->sound), self::CHANNELS * 4);
        if ($gap > self::RATE / 10) { // the file's time jumps on: silence till then (a jump back plays on, as Native\Renderers' audioAt)
            $this->sound .= str_repeat("\0", $gap * self::CHANNELS * 4);
        }
        $this->sound .= $pcm;
    }

    /** Shows the frame due (the first decoded after a flush, whenever it is due), the earlier ones passed, and asks for more video once there is room ($ask). */
    private function present(bool $ask = true): void
    {
        $now = $this->time();
        $passed = false;
        while (count($this->pictures) > 1 && $this->pictures[1][0] <= $now + self::EARLY) {
            AvDecoder::release(array_shift($this->pictures)[1]);
            $passed = true;
        }
        $head = $this->pictures[0] ?? null;
        if ($head !== null && $head[1] !== $this->shown && ($this->fresh || $head[0] <= $now + self::EARLY)) {
            $this->show($head[1]);
            $passed = true;
        }
        if ($ask && $passed && count($this->pictures) < self::FRAMES && $this->videoWanted !== null) { ($this->videoWanted)(); }
    }

    /** Makes $frame the picture, at most LARGEST on a side, and has the windows that show it paint again. */
    private function show(CData $frame): void
    {
        [$w, $h] = [$frame->width, $frame->height];
        if (max($w, $h) > self::LARGEST) {
            $k = self::LARGEST / max($w, $h);
            [$w, $h] = [max(1, (int)round($w * $k)), max(1, (int)round($h * $k))];
        }
        try {
            $rgba = $this->scaler->rgba($frame, $w, $h);
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
            return;
        }
        $this->image === null ? $this->image = Image::fromPixels($w, $h, $rgba) : $this->image->update($w, $h, $rgba);
        [$this->shown, $this->fresh] = [$frame, false];
        foreach (Window::all() as $win) { // those whose last frame showed it: their nodes painted again
            foreach ($win->w->runtime?->videoPlaces ?? [] as $place) {
                if ($place['view'] instanceof Player && $place['view']->out === $this) {
                    $win->requestFrame(1, true);
                    break;
                }
            }
        }
    }

    /** A tick while the clock runs: the sound the device takes written, the clock set by it, the frame due shown. */
    private function tick(): void
    {
        $this->timer = 0;
        if ($this->rate <= 0) {
            return;
        }
        try {
            $this->write();
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
        }
        $this->present();
        $this->timer = Gtk::timeout(self::TICK, $this->later(static fn(self $r) => $r->tick()));
    }

    /** $fn of these renderers for a timer, which holds them weakly: renderers let go (their player's) stop ticking. */
    private function later(\Closure $fn): \Closure
    {
        $self = \WeakReference::create($this);
        return static function () use ($self, $fn): void {
            $r = $self->get();
            if ($r !== null) { $fn($r); }
        };
    }

    /** Writes what the device takes of the sound kept, and sets the clock where the device is; the sound heard let go. */
    private function write(): void
    {
        if ($this->pcm === null || $this->soundAt === null) {
            return;
        }
        $frame = self::CHANNELS * 4;
        if ($this->rate != 1.0) { // no sound but at its own speed: the clock runs on the system's, the sound passed let go
            if (!$this->rateWarned) {
                $this->rateWarned = true;
                Logger::warn("the film's sound does not play at a speed of {$this->rate} on Linux: it plays silent while the speed is not 1", 'media');
            }
            $this->rewind($this->time());
            return;
        }
        if ($this->sent === 0 && $this->soundAt < $this->base - 1 / self::RATE) { // decoded from before where the clock starts (a seek's first packet): from there
            $this->rewind($this->base);
        }
        $kept = intdiv(strlen($this->sound), $frame);
        $n = min($this->pcm->room(), $kept - $this->sent);
        if ($n > 0) {
            $chunk = substr($this->sound, $this->sent * $frame, $n * $frame);
            $k = $this->muted ? 0.0 : $this->volume * $this->gain;
            if ($k != 1.0) { $chunk = pack('g*', ...array_map(fn(float $s): float => $s * $k, unpack('g*', $chunk))); }
            $this->pcm->write($chunk);
            $this->sent += $n;
        }
        $delay = min($this->pcm->delay(), $this->sent);
        if ($this->sent > 0 && $this->sent < $kept) { // the sound plays, more of it to come: the clock is where the device is (its last written, it runs on as the system's: a device's latency left at its end would hold it)
            [$this->base, $this->since] = [$this->soundAt + ($this->sent - $delay) / self::RATE, hrtime(true)];
        }
        if (($heard = $this->sent - $delay) > self::RATE) { // a second heard: let go
            $this->sound = substr($this->sound, $heard * $frame);
            $this->soundAt += $heard / self::RATE;
            $this->sent -= $heard;
        }
    }

    /** The sound kept from the clock's time $at on, none of it written: what the device held is dropped. */
    private function rewind(float $at): void
    {
        if ($this->sent > 0) {
            $this->pcm?->flush();
        }
        $this->sent = 0;
        if ($this->soundAt === null) {
            return;
        }
        $k = max(0, min(intdiv(strlen($this->sound), self::CHANNELS * 4), (int)round(($at - $this->soundAt) * self::RATE)));
        $this->sound = substr($this->sound, $k * self::CHANNELS * 4);
        $this->soundAt += $k / self::RATE;
    }

    public function setEqualizer(?array $gains): void
    {
        if ($gains !== null && !Equalizer::flat($gains)) {
            Logger::warn('the equalizer is not on Linux yet: the sound plays as it is', 'media');
        }
    }

    public function setGain(float $linear): void
    {
        $this->gain = $linear;
    }

    public function setAudioFilters(?AudioFilters $filters): void
    {
        if ($filters !== null) {
            Logger::warn('audio filters are not on Linux yet: the sound plays as it is', 'media');
        }
    }

    /** The frame shown as a PNG of its pixels (as shown: at most LARGEST on a side), RGBA; null before one shows. */
    public function snapshot(): ?string
    {
        if ($this->image === null) {
            return null;
        }
        [$w, $h] = $this->image->size();
        $rgba = $this->image->pixels();
        $raw = '';
        for ($y = 0, $stride = $w * 4; $y < $h; $y++) {
            $raw .= "\0" . substr($rgba, $y * $stride, $stride);
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', Zlib::deflate($raw, 15, 6)) . $chunk('IEND', '');
    }

    /** Hardware decoding (VA-API) is not here: every track decodes in software, either way. */
    public function setHardware(bool $on): void
    {
        $this->hardware = $on;
    }

    public function flush(): void
    {
        foreach ($this->decoders as $d) { $d->flush(); }
        foreach ($this->resamplers as $r) { $r->reset(); }
        foreach ($this->pictures as [, $f]) { AvDecoder::release($f); }
        [$this->pictures, $this->hidden, $this->shown, $this->fresh, $this->ended] = [[], [], null, true, false];
        if ($this->sent > 0) { $this->pcm?->flush(); }
        [$this->sound, $this->soundAt, $this->sent] = ['', null, 0];
    }

    public function time(): float
    {
        return $this->rate == 0.0 ? $this->base : $this->base + (hrtime(true) - $this->since) / 1e9 * $this->rate;
    }

    public function setRate(float $rate, ?float $at = null): void
    {
        $now = $at ?? $this->time();
        $this->rewind($now); // what the device holds goes: it plays again from the clock's time
        [$this->base, $this->since, $this->rate] = [$now, hrtime(true), $rate];
        if ($rate == 1.0) { $this->rateWarned = false; }
        if ($rate > 0 && $this->timer === 0) {
            $this->timer = Gtk::timeout(0, $this->later(static fn(self $r) => $r->tick()));
        } elseif ($rate <= 0 && $this->timer !== 0) {
            Gtk::cancel($this->timer);
            $this->timer = 0;
        }
    }

    public function setVolume(float $v): void
    {
        $this->volume = $v;
    }

    public function setMuted(bool $m): void
    {
        $this->muted = $m;
    }

    public function failure(): ?string
    {
        return $this->failed;
    }

    /** The system's default output alone: PulseAudio's default sink (the devices are Native\AudioDevices', CoreAudio's). */
    public function setAudioDevice(?string $uid): void
    {
        if ($uid !== null) {
            Logger::warn('choosing the audio device is not on Linux yet: the film plays on the default one', 'media');
        }
    }

    /** The picture is converted to 8-bit RGBA: HDR shows tone mapped by libswscale's matrix alone, either way. */
    public function setDynamicRange(bool $high): void {}

    public function setPictureFilters(array $eq, array $effects = [], float $px = 1.0, float $w = 0.0, float $h = 0.0): void
    {
        if (array_filter($eq) !== [] || $effects !== []) {
            Logger::warn('the video equalizer and video filters\' effects are not on Linux yet: the picture shows as it is', 'media');
        }
    }

    public function setDeinterlace(bool $on): void
    {
        if ($on) {
            Logger::warn('deinterlacing is not on Linux yet: the picture shows as decoded', 'media');
        }
    }

    public function frame(): ?Image
    {
        return $this->image;
    }

    public function __destruct()
    {
        if ($this->timer !== 0) { Gtk::cancel($this->timer); }
        if ($this->pollTimer !== 0) { Gtk::cancel($this->pollTimer); }
        foreach ($this->pictures as [, $f]) { AvDecoder::release($f); }
        $this->pcm?->close();
    }
}
