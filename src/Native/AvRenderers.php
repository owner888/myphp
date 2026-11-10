<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI\CData;
use MyPHP\Logger;
use MyPHP\Media\{AudioFilters, Packet, Player, Track};
use MyPHP\Platform;
use MyPHP\UI\Image;

/**
 * What decodes and plays a Player's packets on Linux and Windows over
 * FFmpeg, as Native\Renderers does on macOS, on the main thread in
 * slices: libavcodec decodes them
 * as they are given (Native\AvDecoder; its frame threads decode video
 * while the packets are sent, the frames received later). The sound goes
 * through a libavfilter graph (Native\AvFilter: the equalizer's bands, the
 * gain, the audio filters, as FFmpeg's filters of mpv's af) that makes it
 * 48 kHz stereo floats, kept at the film's speed and written to the
 * system's output (MediaHost::sound: PulseAudio or ALSA, WASAPI) 10 ms at a
 * time from a timer of the main loop (MediaHost::timeout), only what the device
 * takes then (it holds AHEAD seconds), never waiting on it; at a rate but 1
 * through atempo (its pitch kept, as AVSampleBufferAudioRenderer's time
 * pitch). The sound is the clock, as AVSampleBufferRenderSynchronizer's:
 * each tick reads where the device is (what was written less what it
 * holds, at the rate) and sets the clock there, which runs on the system's
 * clock between ticks, and where no sound plays (a film of none, its end,
 * no device). The video's frames go through a graph of their own where
 * the video equalizer, the video filters' effects or deinterlacing ask
 * (eq, hue, unsharp, negate, lut3d, delogo, bwdif), and wait decoded (at most FRAMES of them) until
 * the clock is at their time; the one due is converted to RGBA by
 * libswscale (Native\AvScaler) into an image (frame()) that Context::movie's
 * node draws with the window's renderer, the windows showing it asked to
 * paint again. A frame decoded for those after it (enqueue's $show off,
 * a seek's from its keyframe) is dropped as it comes out, by its time.
 *
 * @internal
 */
final class AvRenderers implements Platform\Renderers
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
    /** @var array<int, AvFilter> the tracks' graphs, by track number, made at their first frame; @var array<int, string> what each was made of */
    private array $graphs = [], $made = [];
    /** The equalizer's gains (null: flat), the audio filters; the video equalizer's options, the video filters' effects, deinterlacing. */
    private ?array $gains = null;
    private ?AudioFilters $audioFilters = null;
    private array $eq = [], $effects = [];
    private bool $deinterlace = false;
    /** The tempo's graph while the rate is not 1, the sound it gave not yet written, and the frames written since the sound was rewound (of the device, at the rate). */
    private ?AvFilter $tempo = null;
    private string $tempoOut = '';
    private float $written = 0.0;
    private AvScaler $scaler;
    /** @var list<array{0: float, 1: CData}> the video's frames decoded and not passed, their times and AVFrame*s, in order: the first the one shown, once one is */
    private array $pictures = [];
    /** @var array<int, CData> the video's frames as decoded (AVFrame* references), by their times (microseconds), till their pictures pass: the one shown filtered again as the picture's filters change while paused */
    private array $raws = [];
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
    private ?Sound $pcm = null;
    private ?string $pcmFailed = null;
    /** The sound decoded and not heard: its bytes, the clock's time of its first frame (null: none since a flush), the frames of it written. */
    private string $sound = '';
    private ?float $soundAt = null;
    private int $sent = 0;
    private float $volume = 1.0, $gain = 1.0;
    private bool $muted = false;
    private ?string $failed = null;

    public function __construct(private readonly MediaHost $host, private bool $hardware = true)
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
        $this->pollTimer = $this->host->timeout(self::POLL, $this->later(static function (self $r): void {
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
                if ($d->track->type === Track::VIDEO && $d->send(null)) {
                    $this->received($d);
                    try { $this->filtered($d->track, null); } catch (\RuntimeException $e) { $this->failed = $e->getMessage(); } // the graph's last pictures out
                }
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
            $d = $this->decoders[$t->number] ??= self::decoder($t, $this->hardware);
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

    /** A decoder of $t: video on Player::$videoThreads, with VA-API where $hardware asks and it can (which path, and why, told at debug). */
    private static function decoder(Track $t, bool $hardware): AvDecoder
    {
        if ($t->type !== Track::VIDEO) {
            return new AvDecoder($t, 1);
        }
        $d = new AvDecoder($t, Player::$videoThreads, $hardware);
        Logger::debug($d->vaapi ? "video: {$t->codec} decodes with VA-API ({$d->name}), its frames copied to memory and converted by libswscale"
            : "video: {$t->codec} decodes in software ({$d->name}): {$d->software}", 'media');
        return $d;
    }

    /** The frames $d decoded so far: the video's kept to be shown (the first after a flush at once), the audio's made the device's PCM and kept to be written. */
    private function received(AvDecoder $d): void
    {
        $video = $d->track->type === Track::VIDEO;
        try {
            while (($f = $d->receive()) !== null) {
                $time = AvDecoder::time($f);
                if ($video) {
                    $key = $time === null ? null : (int)round($time * Av::SCALE);
                    if ($time === null || isset($this->hidden[$key])) {
                        unset($this->hidden[$key]);
                        AvDecoder::release($f);
                        continue;
                    }
                }
                $this->filtered($d->track, $f);
            }
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
        }
        if ($video && $this->fresh) { $this->present(false); } // as a seek lands, paused too: not asking for more from within a give
    }

    /**
     * Decoded frame $f of track $t through its graph, made again as what it
     * filters with or the frames' format change: a picture kept to be
     * shown, sound kept to be written; null the end (the graph's last out).
     */
    private function filtered(Track $t, ?CData $f): void
    {
        $video = $t->type === Track::VIDEO;
        $n = $t->number;
        if ($video && $f !== null) { $this->raws[(int)$f->best_effort_timestamp] = Av::ffi()->av_frame_clone($f); }
        if ($f !== null) {
            $text = $video ? $this->videoGraph($f) : $this->audioGraph($f);
            if ($text === null) { // nothing asked of the picture: as decoded
                unset($this->graphs[$n], $this->made[$n]);
                $this->pictures[] = [AvDecoder::time($f) ?? 0.0, $f];
                return;
            }
            if (($this->made[$n] ?? null) !== $text) {
                if (isset($this->graphs[$n])) { $this->drain($t); }
                $this->graphs[$n] = new AvFilter($text);
                $this->made[$n] = $text;
            }
            $f->pts = $f->best_effort_timestamp;
        }
        $g = $this->graphs[$n] ?? null;
        if ($g === null) {
            return;
        }
        $g->push($f);
        if ($f !== null) { AvDecoder::release($f); }
        $this->drain($t);
    }

    /** What track $t's graph gives now: pictures kept, sound kept. */
    private function drain(Track $t): void
    {
        $g = $this->graphs[$t->number];
        $tb = $g->timeBase();
        while (($o = $g->pull()) !== null) {
            $time = $o->pts === Av::NOPTS ? null : $o->pts * $tb;
            if ($t->type === Track::VIDEO) {
                $this->pictures[] = [$time ?? 0.0, $o];
                continue;
            }
            if ($time !== null && $o->nb_samples > 0) { $this->heard(\FFI::string($o->data[0], $o->nb_samples * self::CHANNELS * 4), $time + $this->audioDelay); }
            AvDecoder::release($o);
        }
    }

    /** The sound's graph of frame $f's format: the equalizer, the gain, the audio filters, made 48 kHz stereo floats. */
    private function audioGraph(CData $f): string
    {
        $ffi = Av::ffi();
        $fmt = $ffi->av_get_sample_fmt_name($f->format) ?? throw new \RuntimeException("myphp: no sample format {$f->format}");
        if ($f->ch_layout->order === 0) { // AV_CHANNEL_ORDER_UNSPEC: a count alone
            $layout = 'channels=' . $f->ch_layout->nb_channels;
        } else {
            $buf = $ffi->new('char[128]');
            $ffi->av_channel_layout_describe(\FFI::addr($f->ch_layout), $buf, 128);
            $layout = 'channel_layout=' . \FFI::string($buf);
        }
        $chain = [];
        if ($this->gains !== null) { // IINA's bands, as its filter for mpv is (Native\Equalizer)
            foreach (Equalizer::FREQUENCIES as $i => $hz) {
                if ((float)$this->gains[$i] != 0.0) { $chain[] = sprintf('equalizer=f=%d:t=h:width=%s:g=%s', $hz, self::num($hz / Equalizer::Q), self::num((float)$this->gains[$i])); }
            }
        }
        if ($this->gain != 1.0) { $chain[] = 'volume=' . self::num($this->gain); }
        foreach ($this->audioFilters?->stages ?? [] as $st) { // Media\AudioFilters' stages, as FFmpeg's filters they were read from
            $chain[] = match ($st[0]) {
                'gain' => 'volume=' . self::num($st[1]),
                'biquad' => sprintf('%s=f=%s:t=%s:w=%s', ['peak' => 'equalizer', 'low' => 'bass', 'high' => 'treble', 'hp' => 'highpass', 'lp' => 'lowpass'][$st[1]], self::num($st[2]), $st[4], self::num($st[5]))
                    . (in_array($st[1], ['hp', 'lp'], true) ? ':p=' . $st[6] : ':g=' . self::num($st[3])),
                'pan' => 'pan=' . $st[1] . 'c|' . implode('|', array_map(fn(array $row, int $o): string => "c$o=" . (implode('+', array_map(fn(float $k, int $c): string => self::num($k) . "*c$c", $row, array_keys($row))) ?: '0*c0'), $st[2], array_keys($st[2]))),
            };
        }
        return sprintf('abuffer@in=time_base=1/%d:sample_rate=%d:sample_fmt=%s:%s,%s,aformat=sample_fmts=flt:sample_rates=%d:channel_layouts=stereo,abuffersink@out',
            Av::SCALE, $f->sample_rate, $fmt, $layout, $chain === [] ? 'anull' : implode(',', $chain), self::RATE);
    }

    /** The picture's graph of frame $f's format, null for none: deinterlacing, the video equalizer, the video filters' effects. */
    private function videoGraph(CData $f): ?string
    {
        $chain = [];
        if ($this->deinterlace) { $chain[] = 'bwdif=mode=send_frame:parity=auto:deint=all'; } // a frame a frame, as IINA's Deinterlace
        $eq = $this->eq + ['brightness' => 0, 'contrast' => 0, 'saturation' => 0, 'gamma' => 0, 'hue' => 0];
        if ($eq['brightness'] !== 0 || $eq['contrast'] !== 0 || $eq['saturation'] !== 0 || $eq['gamma'] !== 0) { // mpv's: brightness / 100, 1 + contrast / 100, 1 + saturation / 100, 8^(gamma / 100) (Native\Renderers' CIColorControls and CIGammaAdjust)
            $chain[] = sprintf('eq=brightness=%s:contrast=%s:saturation=%s:gamma=%s', self::num($eq['brightness'] / 100), self::num(1 + $eq['contrast'] / 100), self::num(1 + $eq['saturation'] / 100), self::num(8 ** ($eq['gamma'] / 100)));
        }
        if ($eq['hue'] !== 0) { $chain[] = 'hue=h=' . self::num($eq['hue'] / 100 * 180); } // CIHueAdjust's angle, hue / 100 × π, in degrees
        foreach ($this->effects as $e) {
            $chain[] = match ($e[0]) {
                'unsharp' => sprintf('unsharp=lx=%d:ly=%d:la=%s', $e[1], $e[1], self::num($e[2])),
                'negative' => 'negate',
                'lut3d' => "lut3d=file='" . self::cube($e[1]) . "':interp={$e[2]}",
                'delogo' => sprintf('delogo=x=%d:y=%d:w=%d:h=%d', (int)floor($e[1] * $f->width), (int)floor($e[2] * $f->height), max(1, (int)round($e[3] * $f->width)), max(1, (int)round($e[4] * $f->height))),
            };
        }
        if ($chain === []) {
            return null;
        }
        $sar = $f->sample_aspect_ratio->num > 0 ? "{$f->sample_aspect_ratio->num}/{$f->sample_aspect_ratio->den}" : '1/1';
        $fmt = Av::ffi()->av_get_pix_fmt_name($f->format) ?? throw new \RuntimeException("myphp: no pixel format {$f->format}");
        return sprintf('buffer@in=video_size=%dx%d:pix_fmt=%s:time_base=1/%d:pixel_aspect=%s,%s,buffersink@out', $f->width, $f->height, $fmt, Av::SCALE, $sar, implode(',', $chain));
    }

    /** A number as the filters' options take it: no exponent, no locale. */
    private static function num(float $v): string
    {
        return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }

    /** Lut3d $lut as a .cube file libavfilter's lut3d reads (Native\Renderers gives CIColorCube its table): written once, in the temporary directory, by its contents. */
    private static function cube(\MyPHP\Media\Lut3d $lut): string
    {
        $path = sys_get_temp_dir() . '/myphp-lut-' . md5($lut->data) . '.cube';
        if (!is_file($path)) {
            $v = unpack('g*', $lut->data) ?: [];
            $lines = ["LUT_3D_SIZE {$lut->size}"];
            for ($i = 1, $n = count($v); $i + 3 <= $n; $i += 4) { $lines[] = sprintf('%.6F %.6F %.6F', $v[$i], $v[$i + 1], $v[$i + 2]); } // RGBA a point, red fastest
            file_put_contents($path, implode("\n", $lines) . "\n") !== false || throw new \RuntimeException("myphp: cannot write $path");
        }
        return $path;
    }

    /** Opens the sound's output: the device chosen (setAudioDevice), else the default; one that does not open is told, the default taken. */
    private function openPcm(): void
    {
        if ($this->device !== null) {
            try {
                $this->pcm = $this->host->sound(self::RATE, self::CHANNELS, self::AHEAD, $this->device);
                Logger::debug("the film's sound plays through {$this->pcm->name()}, on {$this->device}", 'media');
                return;
            } catch (\RuntimeException $e) {
                Logger::warn("the audio device {$this->device} does not open ({$e->getMessage()}): the film plays on the default one", 'media');
            }
        }
        try {
            $this->pcm = $this->host->sound(self::RATE, self::CHANNELS, self::AHEAD, null);
            Logger::debug("the film's sound plays through {$this->pcm->name()}", 'media');
        } catch (\RuntimeException $e) {
            $this->pcmFailed = $e->getMessage();
            Logger::warn($e->getMessage() . ': the film plays without its sound, on the system\'s clock', 'media');
        }
    }

    /** Sound $pcm decoded, which plays at $at on the clock, kept to be written: after what is kept, a gap filled with silence. */
    private function heard(string $pcm, float $at): void
    {
        if ($this->pcm === null && $this->pcmFailed === null) { $this->openPcm(); }
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
            $this->pass(array_shift($this->pictures));
            $passed = true;
        }
        $head = $this->pictures[0] ?? null;
        if ($head !== null && $head[1] !== $this->shown && ($this->fresh || $head[0] <= $now + self::EARLY)) {
            $this->show($head[1]);
            $passed = true;
        }
        if ($ask && $passed && count($this->pictures) < self::FRAMES && $this->videoWanted !== null) { ($this->videoWanted)(); }
    }

    /** Lets go of picture $p ([time, AVFrame*]) and of its frame as decoded. @param array{0: float, 1: CData} $p */
    private function pass(array $p): void
    {
        $key = (int)round($p[0] * Av::SCALE);
        foreach ($this->raws as $k => $f) { // its own, and those before it (a frame the graph gave none of)
            if ($k <= $key) {
                AvDecoder::release($f);
                unset($this->raws[$k]);
            }
        }
        AvDecoder::release($p[1]);
    }

    /**
     * The picture shown made again of its frame as decoded, through the
     * picture's filters as they are now: while paused, a change of them
     * shows at once, as macOS's layer filters do.
     */
    private function refilter(): void
    {
        $head = $this->pictures[0] ?? null;
        $raw = $head === null || $head[1] !== $this->shown ? null : ($this->raws[(int)round($head[0] * Av::SCALE)] ?? null);
        if ($raw === null) {
            return;
        }
        $f = Av::ffi()->av_frame_clone($raw);
        try {
            $text = $this->videoGraph($f);
            if ($text !== null) {
                $f->pts = $f->best_effort_timestamp;
                $g = new AvFilter(str_replace('bwdif=mode=send_frame:parity=auto:deint=all,', '', $text)); // one frame alone: no neighbors to deinterlace by
                $g->push($f);
                AvDecoder::release($f);
                $g->push(null);
                $f = $g->pull() ?? throw new \RuntimeException('myphp: the picture\'s filters gave no frame');
            }
        } catch (\RuntimeException $e) {
            $this->failed = $e->getMessage();
            return;
        }
        AvDecoder::release($head[1]);
        $this->pictures[0][1] = $f;
        $this->show($f);
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
        foreach ($this->host->windows() as $win) { // those whose last frame showed it: their nodes painted again
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
        $this->timer = $this->host->timeout(self::TICK, $this->later(static fn(self $r) => $r->tick()));
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
        if ($this->sent === 0 && $this->soundAt < $this->base - 1 / self::RATE) { // decoded from before where the clock starts (a seek's first packet): from there
            $this->rewind($this->base);
        }
        $kept = intdiv(strlen($this->sound), $frame);
        $room = $this->pcm->room();
        if ($this->rate == 1.0) {
            $n = min($room, $kept - $this->sent);
            $out = $n > 0 ? substr($this->sound, $this->sent * $frame, $n * $frame) : '';
            $this->sent += max(0, $n);
        } else { // through atempo, the film's sound taken as the device's room asks at the rate
            $this->tempo ??= new AvFilter(sprintf('abuffer@in=time_base=1/%d:sample_rate=%d:sample_fmt=flt:channel_layout=stereo,%s,aformat=sample_fmts=flt:sample_rates=%d:channel_layouts=stereo,abuffersink@out',
                self::RATE, self::RATE, self::atempo($this->rate), self::RATE));
            while (intdiv(strlen($this->tempoOut), $frame) < $room && $this->sent < $kept) {
                $n = min(1024, $kept - $this->sent);
                $this->tempo->push(self::pcmFrame(substr($this->sound, $this->sent * $frame, $n * $frame), $n, $this->sent));
                $this->sent += $n;
                while (($o = $this->tempo->pull()) !== null) {
                    $this->tempoOut .= \FFI::string($o->data[0], $o->nb_samples * $frame);
                    AvDecoder::release($o);
                }
            }
            $n = min($room, intdiv(strlen($this->tempoOut), $frame));
            $out = substr($this->tempoOut, 0, $n * $frame);
            $this->tempoOut = substr($this->tempoOut, $n * $frame);
        }
        if ($out !== '') {
            $k = $this->muted ? 0.0 : $this->volume;
            if ($k != 1.0) { $out = pack('g*', ...array_map(fn(float $s): float => $s * $k, unpack('g*', $out))); }
            $this->pcm->write($out);
            $this->written += strlen($out) / $frame;
        }
        $delay = $this->pcm->delay();
        if ($delay === null) {
            return; // the device has not told where it is: the clock runs on the system's meanwhile
        }
        $delay = min((float)$delay, $this->written);
        $heard = ($this->written - $delay) * $this->rate; // of the film's frames, the device's at the rate
        if ($this->sent > 0 && $this->sent < $kept) { // the sound plays, more of it to come: the clock is where the device is (its last written, it runs on as the system's: a device's latency left at its end would hold it)
            [$this->base, $this->since] = [$this->soundAt + $heard / self::RATE, hrtime(true)];
        }
        if (($k = (int)floor($heard)) > self::RATE) { // a second heard: let go
            $this->sound = substr($this->sound, $k * $frame);
            $this->soundAt += $k / self::RATE;
            $this->sent -= $k;
            $this->written -= $k / $this->rate;
        }
    }

    /** atempo of $rate: a chain of them where it is past one's 0.5 to 2 (as FFmpeg says to). */
    private static function atempo(float $rate): string
    {
        $parts = [];
        while ($rate > 2.0) { $parts[] = 'atempo=2'; $rate /= 2; }
        while ($rate < 0.5) { $parts[] = 'atempo=0.5'; $rate /= 0.5; }
        $parts[] = 'atempo=' . self::num($rate);
        return implode(',', $parts);
    }

    /** $n frames of the sound's floats $pcm as an AVFrame* (48 kHz stereo), its time $at frames. */
    private static function pcmFrame(string $pcm, int $n, int $at): CData
    {
        $f = Av::ffi();
        $frame = $f->av_frame_alloc();
        [$frame->nb_samples, $frame->format, $frame->sample_rate, $frame->pts] = [$n, Av::FLT, self::RATE, $at];
        $f->av_channel_layout_default(\FFI::addr($frame->ch_layout), self::CHANNELS);
        Av::check($f->av_frame_get_buffer($frame, 0), 'a frame of sound');
        \FFI::memcpy($frame->data[0], $pcm, strlen($pcm));
        return $frame;
    }

    /** The sound kept from the clock's time $at on, none of it written: what the device held is dropped. */
    private function rewind(float $at): void
    {
        if ($this->written > 0) {
            $this->pcm?->flush();
        }
        [$this->sent, $this->written, $this->tempo, $this->tempoOut] = [0, 0.0, null, ''];
        if ($this->soundAt === null) {
            return;
        }
        $k = max(0, min(intdiv(strlen($this->sound), self::CHANNELS * 4), (int)round(($at - $this->soundAt) * self::RATE)));
        $this->sound = substr($this->sound, $k * self::CHANNELS * 4);
        $this->soundAt += $k / self::RATE;
    }

    /** The equalizer's bands (IINA's ten, dB) in the sound's graph, null or flat for none: made again at the next frame (the player gives the sound again from where it is). */
    public function setEqualizer(?array $gains): void
    {
        $this->gains = $gains === null || Equalizer::flat($gains) ? null : $gains;
    }

    /** A gain of the sound (a volume past 100%, ReplayGain's): libavfilter's volume in its graph. */
    public function setGain(float $linear): void
    {
        $this->gain = $linear;
    }

    /** The audio filters, FFmpeg's of their stages in the sound's graph. */
    public function setAudioFilters(?AudioFilters $filters): void
    {
        $this->audioFilters = $filters;
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

    /** Decodes the video with VA-API or not from the next packet on (the player gives it again from the keyframe before): its decoders made anew. */
    public function setHardware(bool $on): void
    {
        $this->hardware = $on;
        foreach ($this->decoders as $n => $d) {
            if ($d->track->type === Track::VIDEO) { unset($this->decoders[$n], $this->graphs[$n], $this->made[$n]); }
        }
    }

    public function flush(): void
    {
        foreach ($this->decoders as $d) { $d->flush(); }
        foreach ($this->pictures as [, $f]) { AvDecoder::release($f); }
        foreach ($this->raws as $f) { AvDecoder::release($f); }
        [$this->graphs, $this->made, $this->raws] = [[], [], []]; // made again at the next frame: what they held goes
        [$this->pictures, $this->hidden, $this->shown, $this->fresh, $this->ended] = [[], [], null, true, false];
        if ($this->written > 0) { $this->pcm?->flush(); }
        [$this->sound, $this->soundAt, $this->sent, $this->written, $this->tempo, $this->tempoOut] = ['', null, 0, 0.0, null, ''];
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
        if ($rate > 0 && $this->timer === 0) {
            $this->timer = $this->host->timeout(0, $this->later(static fn(self $r) => $r->tick()));
        } elseif ($rate <= 0 && $this->timer !== 0) {
            $this->host->cancel($this->timer);
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

    /** The device chosen, mpv's name of it ("pulse/<sink>", "alsa/<device>", "wasapi/<endpoint id>": Media::audioOutputs), null for the default. */
    private ?string $device = null;

    /**
     * Plays the sound on device $uid (mpv's name, null the default): the
     * output opened anew there, the sound from the clock's time written to
     * it, the device before let go.
     */
    public function setAudioDevice(?string $uid): void
    {
        if ($uid === $this->device) {
            return;
        }
        $this->device = $uid;
        if ($this->pcm === null && $this->pcmFailed === null) {
            return; // opened on it at the first sound
        }
        $this->rewind($this->time());
        $this->pcm?->close();
        [$this->pcm, $this->pcmFailed] = [null, null];
        $this->openPcm();
    }

    /** The picture is converted to 8-bit RGBA: HDR shows tone mapped by libswscale's matrix alone, either way. */
    public function setDynamicRange(bool $high): void {}

    /**
     * The video equalizer and the video filters' effects in the picture's
     * graph (eq, hue, unsharp, negate, lut3d, delogo), from the frames
     * decoded next; $px, $w and $h (the layer's, Core Image's) are not
     * needed: the filters work in the decoded picture's pixels, as mpv's.
     */
    public function setPictureFilters(array $eq, array $effects = [], float $px = 1.0, float $w = 0.0, float $h = 0.0): void
    {
        if ([$eq, $effects] == [$this->eq, $this->effects]) {
            return;
        }
        [$this->eq, $this->effects] = [$eq, $effects];
        if ($this->rate == 0.0) { $this->refilter(); }
    }

    /** bwdif, a frame a frame, in the picture's graph (the player gives the picture again from where it is). */
    public function setDeinterlace(bool $on): void
    {
        $this->deinterlace = $on;
    }

    public function frame(): ?Image
    {
        return $this->image;
    }

    public function __destruct()
    {
        if ($this->timer !== 0) { $this->host->cancel($this->timer); }
        if ($this->pollTimer !== 0) { $this->host->cancel($this->pollTimer); }
        foreach ($this->pictures as [, $f]) { AvDecoder::release($f); }
        foreach ($this->raws as $f) { AvDecoder::release($f); }
        $this->pcm?->close();
    }
}
