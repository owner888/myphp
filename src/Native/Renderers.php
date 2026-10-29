<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI\CData;
use MyPHP\Media\{Packet, Track};
use RuntimeException;

/**
 * What decodes and plays a Player's packets, all of it the system's on
 * threads of its own: an AVSampleBufferDisplayLayer decodes the video and
 * shows it (the window places the layer where Context::video's node is),
 * an AVSampleBufferAudioRenderer the audio, and an
 * AVSampleBufferRenderSynchronizer runs both on one clock. The player tops
 * them up at its ticks while they ask for more. A video track the system
 * does not decode (AV1 without its decoder), or that it is not to
 * ($hardware off), is decoded by libdav1d (Native\SoftVideo) into pixel
 * buffers the layer shows; the layer takes few of those (a fifth of a
 * second of 60 frames), fewer than a tick's quarter of a second plays, so
 * while it will take no more video, it is asked to say when it will
 * (requestMediaDataWhenReadyOnQueue, on the main queue), and is given the
 * video queued then ($videoWanted). The video is given 10 ms of the main
 * thread at a time (SLICE), the rest a few milliseconds on (POLL).
 *
 * @internal
 */
final class Renderers
{
    /** The display layer, the audio renderer and the synchronizer, retained. */
    public $layer, $audio, $sync;
    /** @var array<int, CData> the format descriptions of the tracks whose packets carry their bytes, by track number */
    private array $formats = [];
    /** The sample the next audio packet starts at, of the track playing: packets follow one another exactly, Matroska's times being whole milliseconds. */
    private ?int $nextAudio = null;
    /** The view whose layer the display layer is, in the window that shows it (Window::placeVideos), null in none. */
    public $view = null;
    /** Whether the display layer shows in picture in picture's window, which took it: the app's window leaves it there (PictureInPicture). */
    public bool $elsewhere = false;
    /** Seconds the sound plays after its time in the file (Player::setAudioDelay). */
    public float $audioDelay = 0.0;
    /** @var array<int, SoftVideo> the decoders of the video tracks decoded in software, by track number */
    private array $soft = [];
    /** @var array<int, SoftAudio> the decoders of the audio tracks decoded in PHP (Vorbis), by track number */
    private array $softAudio = [];
    /** What gives the layer the video queued (the player's): when the layer that would take no more will, or a poll fires. */
    public ?\Closure $videoWanted = null;
    /** Whether the layer is to say when it takes more; the renderers that asked it, for the block (one for the process) the layers call. @var array<int, \WeakReference<self>> */
    private bool $asking = false;
    private static array $askers = [];
    private static mixed $askBlock = null;
    /** libSystem's main queue, which the layer's block runs on. */
    private static ?\FFI $main = null;
    /** Seconds between looks at a software decoder whose threads decode what it refused more for (a seek's frames from its keyframe). */
    private const POLL = 0.004;
    /**
     * Seconds of the main thread the video takes at a time: dav1d's send
     * waits while its frame threads are all at work (dav1d_send_data
     * submits the frame), so a seek's frames from its keyframe would hold
     * the window as long as they decode (250 ms of a 60-frame 1080p film),
     * and VideoToolbox's sample buffers of them, made here, take a while
     * too; they go on after a POLL instead. When the slice started (hrtime).
     */
    private const SLICE = 0.010;
    private int $burst = 0;
    /** @var array<int, \WeakReference<self>> the renderers whose software decoder was full, for the poll timer; the timer, its target */
    private static array $pollers = [];
    private static $pollTimer = null, $pollTarget = null;
    /** The equalizer's gains (IINA's ten bands, dB), null while it is off (flat): the audio then goes to the renderer as it comes. */
    private ?array $gains = null;
    /** @var array<int, AudioDecoder> the system's decoders of the audio the equalizer filters, by track number */
    private array $decoders = [];
    /** @var array<string, Equalizer> the equalizer's filters, by rate and channels; @var array<string, CData> the formats of the PCM filtered, by rate, channels and layout */
    private array $eqs = [], $pcmFormats = [];

    /** Renderers whose video the system decodes, unless $hardware is off (IINA's hardware decoding, mpv's hwdec): then what libdav1d decodes (AV1) is decoded so. */
    public function __construct(private bool $hardware = true)
    {
        CoreMedia::c();
        ObjC::boot(['AppKit', 'QuartzCore']);
        $this->layer = ObjC::msg('p:', ObjC::alloc('AVSampleBufferDisplayLayer'), 'init');
        ObjC::msg('v:p', $this->layer, 'setBackgroundColor:', ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSColor'), 'blackColor'), 'CGColor')); // around the picture, which it fits in its bounds
        $this->audio = ObjC::msg('p:', ObjC::alloc('AVSampleBufferAudioRenderer'), 'init');
        $this->sync = ObjC::msg('p:', ObjC::alloc('AVSampleBufferRenderSynchronizer'), 'init');
        // Off: it holds a rate change until both renderers have "enough", which a player keeping two seconds ahead of a clock that has not started never gives (a seek stalled 5 s); before macOS 11.3 it does not wait.
        if (ObjC::msg('b:p', $this->sync, 'respondsToSelector:', ObjC::sel('setDelaysRateChangeUntilHasSufficientMediaData:'))) {
            ObjC::msg('v:b', $this->sync, 'setDelaysRateChangeUntilHasSufficientMediaData:', false);
        }
        ObjC::msg('v:p', $this->sync, 'addRenderer:', $this->layer);
        ObjC::msg('v:p', $this->sync, 'addRenderer:', $this->audio);
    }

    /** Whether the renderer of $t's packets takes more now. */
    public function ready(Track $t): bool
    {
        $soft = $this->soft[$t->number] ?? null;
        $ready = ObjC::msg('b:', $t->type === Track::VIDEO ? $this->layer : $this->audio, 'isReadyForMoreMediaData'); // asked once: the layer turns ready on its own thread
        if ($t->type === Track::VIDEO && $ready && hrtime(true) - $this->burst > self::SLICE * 1e9) {
            $this->poll(); // the video's slice of the main thread spent: on soon, the window drawn meanwhile (a paused player has no tick)
            return false;
        }
        if ($soft !== null && $ready && !$soft->unclog($this->layer)) {
            $this->poll(); // dav1d full, its threads at work: looked at again soon
            return false;
        }
        if (!$ready && $t->type === Track::VIDEO && !$this->asking) { $this->ask(); } // the layer to say when it takes more: a paused player has no tick to give it the rest of a seek's frames
        return $ready;
    }

    /**
     * Has the video queued given again in POLL seconds, while a software
     * decoder holds a packet it refused or the video's slice was spent: an
     * NSTimer in the common modes, one for the process, which stops once
     * no renderer waits so.
     */
    private function poll(): void
    {
        self::$pollers[spl_object_id($this)] = \WeakReference::create($this);
        if (self::$pollTimer !== null) {
            return;
        }
        if (self::$pollTarget === null) {
            ObjC::defineClass('MyPHPSoftVideoPoll', 'NSObject', [
                'poll:' => ['v@:@', 'v(ppp)', static function ($self, $cmd, $timer): void {
                    $waiting = self::$pollers;
                    self::$pollers = [];
                    foreach ($waiting as $r) { $r->get()?->wanted(true); } // those still waiting ask again
                    if (self::$pollers === [] && self::$pollTimer !== null) {
                        ObjC::msg('v:', self::$pollTimer, 'invalidate');
                        ObjC::msg('v:', self::$pollTimer, 'release');
                        self::$pollTimer = null;
                    }
                }],
            ]);
            self::$pollTarget = ObjC::msg('p:', ObjC::alloc('MyPHPSoftVideoPoll'), 'init');
        }
        self::$pollTimer = ObjC::msg('p:', ObjC::msg('p:dpppb', ObjC::cls('NSTimer'), 'timerWithTimeInterval:target:selector:userInfo:repeats:', self::POLL, self::$pollTarget, ObjC::sel('poll:'), null, true), 'retain');
        ObjC::msg('v:pp', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'mainRunLoop'), 'addTimer:forMode:', self::$pollTimer, Metal::commonMode());
    }

    /** @internal Player: what follows is a slice of the main thread of its own (ready()'s SLICE), its packets given while it lasts. */
    public function slice(): void
    {
        $this->burst = hrtime(true);
    }

    /** Has the layer say, on the main queue, when it takes more video. */
    private function ask(): void
    {
        self::$askBlock ??= ObjC::block('', static function (): void { // every renderer asking given its video: each looks at its own layer
            foreach (self::$askers as $r) { $r->get()?->wanted(); }
        }, false);
        self::$askers[spl_object_id($this)] = \WeakReference::create($this);
        $this->asking = true;
        self::$main ??= \FFI::cdef('extern char _dispatch_main_q;', '/usr/lib/libSystem.B.dylib');
        ObjC::msg('v:pp', $this->layer, 'requestMediaDataWhenReadyOnQueue:usingBlock:', \FFI::addr(self::$main->_dispatch_main_q), self::$askBlock);
    }

    /**
     * The layer takes more, or ($polled) the poll timer fired: the video
     * queued given it. The layer still taking more after that, nothing was
     * left, and it stops asking (the next tick's reads ask again).
     */
    private function wanted(bool $polled = false): void
    {
        if (!$polled && !ObjC::msg('b:', $this->layer, 'isReadyForMoreMediaData')) {
            return; // another's layer asked
        }
        if ($this->videoWanted !== null) { ($this->videoWanted)(); } // its readiness asked again: a poll or the layer's asking again, as it is
        if (!$polled && ObjC::msg('b:', $this->layer, 'isReadyForMoreMediaData')) { $this->stopAsking(); }
    }

    private function stopAsking(): void
    {
        if (!$this->asking) {
            return;
        }
        ObjC::msg('v:', $this->layer, 'stopRequestingMediaData');
        $this->asking = false;
        unset(self::$askers[spl_object_id($this)]);
    }

    /** The software decoder of video track $t, made at its first packet; null for one the layer decodes. */
    private function softVideo(Track $t): ?SoftVideo
    {
        if (!isset($this->soft[$t->number]) && SoftVideo::wanted($t, $this->hardware)) {
            $this->soft[$t->number] = new SoftVideo($t);
        }
        return $this->soft[$t->number] ?? null;
    }

    /** At the end of the film ($drain), the pictures the software decoders hold, given to the layer. */
    public function pump(bool $drain): void
    {
        if (!$drain) {
            return;
        }
        foreach ($this->soft as $s) { $s->take($this->layer, true); }
    }

    /** Gives $p to its renderer: a video frame shown unless $show is false (decoded for those after it). */
    public function enqueue(Packet $p, bool $show = true): void
    {
        $t = $p->track;
        $video = $t->type === Track::VIDEO;
        if (!$video && $this->gains !== null) { // the equalizer on: decoded here, filtered, given as PCM
            $this->enqueueFiltered($p);
            return;
        }
        if ($p->sample !== null) {
            $sb = $p->sample->buffer;
            if (!$show) {
                CoreMedia::mark($sb, false, true);
            }
            ObjC::msg('v:p', $video ? $this->layer : $this->audio, 'enqueueSampleBuffer:', ObjC::import($sb));
            return;
        }
        if ($video && ($soft = $this->softVideo($t)) !== null) {
            $soft->send($p, $show, $this->layer);
            return;
        }
        if (!$video && SoftAudio::wanted($t)) {
            $soft = $this->softAudio[$t->number] ??= new SoftAudio($t);
            [$pcm, $frames] = $soft->decode($p->data);
            if ($frames === 0) {
                return; // the first packet after a start: it only overlaps the next
            }
            $format = $this->formats[$t->number] ??= CoreMedia::pcmFormat($soft->rate(), $soft->channels(), CoreMedia::bitmapLayout(SoftAudio::BITS[$soft->channels()]));
            $sb = CoreMedia::pcmSample($pcm, $format, $this->audioAt($p, $soft->rate(), $frames), $soft->rate(), $frames);
        } else {
            $format = $this->formats[$t->number] ??= CoreMedia::format($t, $p->data); // the first packet a keyframe: playing starts at one
            if ($video) {
                $sb = CoreMedia::videoSample($p, $format, $show);
            } else {
                $rate = (int)$t->sampleRate;
                $frames = CoreMedia::frames($p);
                $sb = CoreMedia::audioSample($p, $format, $this->audioAt($p, $rate, $frames), $rate, $frames);
            }
        }
        ObjC::msg('v:p', $video ? $this->layer : $this->audio, 'enqueueSampleBuffer:', ObjC::import($sb));
        CoreMedia::c()->CFRelease($sb);
    }

    /**
     * Turns the equalizer on with $gains (IINA's ten bands, dB), or off for
     * null or none of a gain (IINA's Flat): the audio then goes to the
     * renderer as it did. Flush and feed again after (Player::setEqualizer).
     * @param ?list<float> $gains
     */
    public function setEqualizer(?array $gains): void
    {
        $this->gains = $gains === null || Equalizer::flat($gains) ? null : $gains;
        $this->eqs = []; // made again of the gains as the audio comes
    }

    /** Audio packet $p decoded (by the system, Vorbis by SoftAudio), filtered by the equalizer and given to the renderer as float PCM. */
    private function enqueueFiltered(Packet $p): void
    {
        $t = $p->track;
        if (SoftAudio::wanted($t)) {
            $soft = $this->softAudio[$t->number] ??= new SoftAudio($t);
            [$pcm, $frames] = $soft->decode($p->data);
            [$rate, $ch, $layout] = [$soft->rate(), $soft->channels(), CoreMedia::bitmapLayout(SoftAudio::BITS[$soft->channels()])];
        } else {
            $d = $this->decoders[$t->number] ??= new AudioDecoder($p->sample !== null
                ? CoreMedia::c()->CMSampleBufferGetFormatDescription($p->sample->buffer) // AVFoundation's reader's
                : ($this->formats[$t->number] ??= CoreMedia::format($t, $p->data)));
            [$pcm, $frames] = $d->decode($p);
            [$rate, $ch, $layout] = [$d->rate, $d->channels, $d->layout];
        }
        if ($frames === 0) {
            return; // the codec holds them yet
        }
        $pcm = ($this->eqs["$rate/$ch"] ??= new Equalizer($this->gains, $rate, $ch))->process($pcm, $frames);
        $format = $this->pcmFormats["$rate/$ch/" . bin2hex($layout)] ??= CoreMedia::pcmFormat($rate, $ch, $layout);
        $sb = CoreMedia::pcmSample($pcm, $format, $this->audioAt($p, $rate, $frames), $rate, $frames);
        ObjC::msg('v:p', $this->audio, 'enqueueSampleBuffer:', ObjC::import($sb));
        CoreMedia::c()->CFRelease($sb);
    }

    /** The sample audio packet $p of $frames frames starts at, of $rate a second: right after the last, unless the file's time jumps (a gap), as its time says. */
    private function audioAt(Packet $p, int $rate, int $frames): int
    {
        $at = (int)round(($p->time + $this->audioDelay) * $rate);
        if ($this->nextAudio === null || abs($at - $this->nextAudio) > $rate / 10) { // the first, or the file's time jumping: its time again
            $this->nextAudio = $at;
        }
        $at = $this->nextAudio;
        $this->nextAudio += $frames;
        return $at;
    }

    /**
     * The frame the display layer shows, as a PNG of the film's pixels;
     * null before one shows. Its video renderer's displayed pixel buffer
     * (macOS 14.4), through CoreImage and AppKit's bitmap.
     */
    public function snapshot(): ?string
    {
        $renderer = ObjC::msg('p:', $this->layer, 'sampleBufferRenderer');
        if (!ObjC::msg('b:p', $renderer, 'respondsToSelector:', ObjC::sel('copyDisplayedPixelBuffer'))) {
            throw new RuntimeException('myphp: a snapshot of a film needs macOS 14.4');
        }
        $pixels = ObjC::msg('p:', $renderer, 'copyDisplayedPixelBuffer');
        if ($pixels === null) {
            return null;
        }
        ObjC::boot(['CoreImage']);
        $pool = ObjC::msg('p:', ObjC::alloc('NSAutoreleasePool'), 'init');
        $image = ObjC::msg('p:p', ObjC::cls('CIImage'), 'imageWithCVPixelBuffer:', $pixels);
        $rep = ObjC::msg('p:', ObjC::msg('p:p', ObjC::alloc('NSBitmapImageRep'), 'initWithCIImage:', $image), 'autorelease');
        $png = ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary')); // NSBitmapImageFileTypePNG
        $n = ObjC::msg('L:', $png, 'length');
        $bytes = \FFI::string(ObjC::$c->cast('char*', ObjC::msg('p:', $png, 'bytes')), $n);
        ObjC::msg('v:', $pool, 'drain');
        CoreMedia::c()->CFRelease($pixels); // a copy, the caller's
        return $bytes;
    }

    /** @internal Player: decodes on the system's decoders or not from the next packet on (Player::setHardwareDecoding, which seeks to where it is), the software decoders gone. */
    public function setHardware(bool $on): void
    {
        $this->hardware = $on;
        $this->soft = [];
    }

    /** Drops what the renderers hold, for a seek or another audio track. */
    public function flush(): void
    {
        $this->stopAsking();
        ObjC::msg('v:', $this->layer, 'flush');
        ObjC::msg('v:', $this->audio, 'flush');
        foreach ($this->soft as $s) { $s->flush(); }
        foreach ($this->softAudio as $s) { $s->reset(); }
        foreach ($this->decoders as $d) { $d->reset(); }
        foreach ($this->eqs as $e) { $e->reset(); }
        $this->nextAudio = null;
    }

    /** The time of the clock, in seconds. */
    public function time(): float
    {
        return CoreMedia::seconds(ObjC::msg('T:', $this->sync, 'currentTime')) ?? 0.0;
    }

    /** Runs the clock at $rate (0 stops it), from $at seconds when given. AVFoundation's rates are floats. */
    public function setRate(float $rate, ?float $at = null): void
    {
        if ($at === null) {
            ObjC::msg('v:f', $this->sync, 'setRate:', $rate);
            return;
        }
        $t = ObjC::$c->new('CMTime');
        $t->value = (int)round($at * 90000); $t->timescale = 90000; $t->flags = 1;
        ObjC::msg('v:fT', $this->sync, 'setRate:time:', $rate, $t);
    }

    public function setVolume(float $v): void
    {
        ObjC::msg('v:f', $this->audio, 'setVolume:', $v);
    }

    public function setMuted(bool $m): void
    {
        ObjC::msg('v:b', $this->audio, 'setMuted:', $m);
    }

    /** What failed, a renderer's error, or null. */
    public function failure(): ?string
    {
        foreach ([$this->layer, $this->audio] as $r) {
            if (ObjC::msg('l:', $r, 'status') === 2) { // AVQueuedSampleBufferRenderingStatusFailed
                $e = ObjC::msg('p:', $r, 'error');
                return $e === null ? 'the renderer failed' : ObjC::phpStr(ObjC::msg('p:', $e, 'localizedDescription'));
            }
        }
        return null;
    }

    public function __destruct()
    {
        $this->stopAsking();
        unset(self::$pollers[spl_object_id($this)]);
        ObjC::msg('v:f', $this->sync, 'setRate:', 0.0);
        foreach ([...$this->formats, ...$this->pcmFormats] as $f) {
            CoreMedia::c()->CFRelease($f);
        }
        ObjC::msg('v:', $this->layer, 'removeFromSuperlayer');
        foreach ([$this->sync, $this->audio, $this->layer] as $o) {
            ObjC::msg('v:', $o, 'release');
        }
    }
}
