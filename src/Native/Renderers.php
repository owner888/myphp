<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI\CData;
use MyPHP\Media\{Packet, Track};

/**
 * What decodes and plays a Player's packets, all of it the system's on
 * threads of its own: an AVSampleBufferDisplayLayer decodes the video and
 * shows it (the window places the layer where Context::video's node is),
 * an AVSampleBufferAudioRenderer the audio, and an
 * AVSampleBufferRenderSynchronizer runs both on one clock. Nothing calls
 * back: the player tops them up while they ask for more.
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
    /** Seconds the sound plays after its time in the file (Player::setAudioDelay). */
    public float $audioDelay = 0.0;

    public function __construct()
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
        return ObjC::msg('b:', $t->type === Track::VIDEO ? $this->layer : $this->audio, 'isReadyForMoreMediaData');
    }

    /** Gives $p to its renderer: a video frame shown unless $show is false (decoded for those after it). */
    public function enqueue(Packet $p, bool $show = true): void
    {
        $t = $p->track;
        $video = $t->type === Track::VIDEO;
        if ($p->sample !== null) {
            $sb = $p->sample->buffer;
            if (!$show) {
                CoreMedia::mark($sb, false, true);
            }
            ObjC::msg('v:p', $video ? $this->layer : $this->audio, 'enqueueSampleBuffer:', ObjC::import($sb));
            return;
        }
        $format = $this->formats[$t->number] ??= CoreMedia::format($t, $p->data); // the first packet a keyframe: playing starts at one
        if ($video) {
            $sb = CoreMedia::videoSample($p, $format, $show);
        } else {
            $rate = (int)$t->sampleRate;
            $at = (int)round(($p->time + $this->audioDelay) * $rate);
            if ($this->nextAudio === null || abs($at - $this->nextAudio) > $rate / 10) { // the first, or the file's time jumping (a gap): its time again
                $this->nextAudio = $at;
            }
            $frames = CoreMedia::frames($p);
            $sb = CoreMedia::audioSample($p, $format, $this->nextAudio, $rate, $frames);
            $this->nextAudio += $frames;
        }
        ObjC::msg('v:p', $video ? $this->layer : $this->audio, 'enqueueSampleBuffer:', ObjC::import($sb));
        CoreMedia::c()->CFRelease($sb);
    }

    /** Drops what the renderers hold, for a seek or another audio track. */
    public function flush(): void
    {
        ObjC::msg('v:', $this->layer, 'flush');
        ObjC::msg('v:', $this->audio, 'flush');
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
        ObjC::msg('v:f', $this->sync, 'setRate:', 0.0);
        foreach ($this->formats as $f) {
            CoreMedia::c()->CFRelease($f);
        }
        ObjC::msg('v:', $this->layer, 'removeFromSuperlayer');
        foreach ([$this->sync, $this->audio, $this->layer] as $o) {
            ObjC::msg('v:', $o, 'release');
        }
    }
}
