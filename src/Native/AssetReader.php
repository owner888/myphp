<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Media\{Packet, Source, Track};
use RuntimeException;

/**
 * Reads what AVFoundation reads (MP4, MOV, M4V) as a Player's source: an
 * AVAssetReader with an output of each audio, video and text subtitle
 * track that passes their samples through as stored: the sample buffers
 * of the audio and video go to the renderers as they are, the subtitles'
 * text comes out as Matroska's does. Its tracks name their codecs as
 * Matroska does.
 * A seek reads anew from the time, which the reader starts at the
 * keyframe before.
 *
 * @internal
 */
final class AssetReader implements Source
{
    /** Matroska's codec ids of the FourCCs AVFoundation's tracks have. */
    private const CODECS = ['avc1' => 'V_MPEG4/ISO/AVC', 'avc3' => 'V_MPEG4/ISO/AVC', 'hvc1' => 'V_MPEGH/ISO/HEVC', 'hev1' => 'V_MPEGH/ISO/HEVC', 'vp09' => 'V_VP9', 'av01' => 'V_AV1',
        'aac ' => 'A_AAC', 'ac-3' => 'A_AC3', 'ec-3' => 'A_EAC3', '.mp3' => 'A_MPEG/L3', 'opus' => 'A_OPUS', 'tx3g' => 'S_TEXT/TX3G'];
    /** The track types of AVFoundation's media types: text subtitles are 'sbtl', or 'text' in QuickTime files. */
    private const TYPES = ['vide' => Track::VIDEO, 'soun' => Track::AUDIO, 'sbtl' => Track::SUBTITLE, 'text' => Track::SUBTITLE];

    private $asset;
    /** @var list<Track> */
    private array $tracks = [];
    /** @var array<int, mixed> the asset's tracks by number */
    private array $native = [];
    private float $duration;
    private $reader = null;
    /** @var array<int, mixed> the reader's outputs, by track number */
    private array $outputs = [];
    /** @var array<int, ?Packet> the next packet of each output, read ahead to interleave them */
    private array $heads = [];

    public function __construct(string $path)
    {
        CoreMedia::c();
        if (!is_file($path)) {
            throw new RuntimeException("cannot open $path");
        }
        $url = ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path));
        $this->asset = ObjC::msg('p:', ObjC::msg('p:pp', ObjC::cls('AVURLAsset'), 'URLAssetWithURL:options:', $url, null), 'retain');
        $this->duration = CoreMedia::seconds(ObjC::msg('T:', $this->asset, 'duration')) ?? 0.0;
        $all = ObjC::msg('p:', $this->asset, 'tracks');
        $cm = CoreMedia::c();
        for ($i = 0, $n = ObjC::msg('L:', $all, 'count'); $i < $n; $i++) {
            $tr = ObjC::msg('p:L', $all, 'objectAtIndex:', $i);
            $type = self::TYPES[ObjC::phpStr(ObjC::msg('p:', $tr, 'mediaType'))] ?? null;
            if ($type === null) {
                continue;
            }
            $formats = ObjC::msg('p:', $tr, 'formatDescriptions');
            if (ObjC::msg('L:', $formats, 'count') === 0) {
                continue;
            }
            $fmt = CoreMedia::ptr(ObjC::msg('p:L', $formats, 'objectAtIndex:', 0));
            $fourcc = pack('N', $cm->CMFormatDescriptionGetMediaSubType($fmt));
            $lang = ObjC::msg('p:', $tr, 'languageCode');
            $number = ObjC::msg('i:', $tr, 'trackID');
            $size = $type === Track::VIDEO ? ObjC::msg('z:', $tr, 'naturalSize') : null;
            $asbd = $type === Track::AUDIO ? $cm->CMAudioFormatDescriptionGetStreamBasicDescription($fmt) : null;
            $fps = ObjC::msg('f:', $tr, 'nominalFrameRate');
            $this->tracks[] = new Track(
                number: $number,
                type: $type,
                codec: self::CODECS[$fourcc] ?? "fourcc:$fourcc",
                private: '',
                language: $lang === null ? 'und' : ObjC::phpStr($lang),
                name: '',
                isDefault: ObjC::msg('b:', $tr, 'isEnabled'),
                frameDuration: $type === Track::VIDEO && $fps > 0 ? 1 / $fps : 0.0,
                width: $size === null ? 0 : (int)$size->w,
                height: $size === null ? 0 : (int)$size->h,
                sampleRate: $asbd === null ? 0.0 : $asbd->rate,
                channels: $asbd === null ? 0 : $asbd->channels,
            );
            $this->native[$number] = $tr;
        }
        if (!array_filter($this->tracks, fn(Track $t) => $t->type !== Track::SUBTITLE)) {
            throw new RuntimeException("$path has no audio or video AVFoundation reads");
        }
        $this->start(0.0);
    }

    public function tracks(): array
    {
        return $this->tracks;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function seek(float $seconds): void
    {
        $this->start($seconds);
    }

    /** A reader from $seconds to the end, with an output of each track passing its samples through. */
    private function start(float $seconds): void
    {
        if ($this->reader !== null) {
            ObjC::msg('v:', $this->reader, 'cancelReading');
            ObjC::msg('v:', $this->reader, 'release');
        }
        $err = ObjC::$c->new('void*[1]');
        $this->reader = ObjC::msg('p:pp', ObjC::cls('AVAssetReader'), 'assetReaderWithAsset:error:', $this->asset, \FFI::addr($err[0]))
            ?? throw new RuntimeException('myphp: AVAssetReader: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        ObjC::msg('p:', $this->reader, 'retain');
        if ($seconds > 0) {
            $range = ObjC::$c->new('CMTimeRange');
            $range->start->value = (int)round($seconds * 90000); $range->start->timescale = 90000; $range->start->flags = 1;
            $range->duration->flags = 1 | 4; $range->duration->timescale = 1; // kCMTimeFlags_Valid | kCMTimeFlags_PositiveInfinity: to the end
            ObjC::msg('v:X', $this->reader, 'setTimeRange:', $range);
        }
        $this->outputs = []; $this->heads = [];
        foreach ($this->native as $number => $tr) {
            $out = ObjC::msg('p:pp', ObjC::cls('AVAssetReaderTrackOutput'), 'assetReaderTrackOutputWithTrack:outputSettings:', $tr, null); // as stored
            ObjC::msg('v:b', $out, 'setAlwaysCopiesSampleData:', false);
            if (ObjC::msg('b:p', $this->reader, 'canAddOutput:', $out)) {
                ObjC::msg('v:p', $this->reader, 'addOutput:', $out);
                $this->outputs[$number] = $out;
            }
        }
        if (!ObjC::msg('b:', $this->reader, 'startReading')) {
            $e = ObjC::msg('p:', $this->reader, 'error');
            throw new RuntimeException('myphp: reading the asset: ' . ($e === null ? 'failed' : ObjC::phpStr(ObjC::msg('p:', $e, 'localizedDescription'))));
        }
    }

    /** The next packet of the tracks: the earliest of the outputs' next, each output's in the order stored. */
    public function next(): ?Packet
    {
        $best = null;
        foreach ($this->outputs as $number => $out) {
            if (!array_key_exists($number, $this->heads)) {
                $this->heads[$number] = $this->read($number, $out);
            }
            $p = $this->heads[$number];
            if ($p !== null && ($best === null || $p->time < $this->heads[$best]->time)) {
                $best = $number;
            }
        }
        if ($best === null) {
            return null;
        }
        $p = $this->heads[$best];
        unset($this->heads[$best]);
        return $p;
    }

    /** The next sample of output $out of track $number as a packet, skipping those of no samples; null at its end. */
    private function read(int $number, $out): ?Packet
    {
        $cm = CoreMedia::c();
        $track = null;
        foreach ($this->tracks as $t) {
            if ($t->number === $number) { $track = $t; }
        }
        while (true) {
            $sb = ObjC::msg('p:', $out, 'copyNextSampleBuffer');
            if ($sb === null) {
                return null;
            }
            $buf = new SampleBuffer(CoreMedia::ptr($sb)); // copied: ours to release
            if ($cm->CMSampleBufferGetNumSamples($buf->buffer) === 0) {
                continue;
            }
            $time = CoreMedia::seconds($cm->CMSampleBufferGetPresentationTimeStamp($buf->buffer)) ?? 0.0;
            $duration = CoreMedia::seconds($cm->CMSampleBufferGetDuration($buf->buffer)) ?? 0.0;
            if ($track->type === Track::SUBTITLE) { // its text, as Matroska's subtitle packets carry it
                return new Packet($track, $time, $duration, true, CoreMedia::bytes($buf->buffer));
            }
            return new Packet($track, $time, $duration, $track->type === Track::AUDIO || CoreMedia::isSync($buf->buffer), '', $buf);
        }
    }

    public function __destruct()
    {
        if ($this->reader !== null) {
            ObjC::msg('v:', $this->reader, 'cancelReading');
            ObjC::msg('v:', $this->reader, 'release');
        }
        ObjC::msg('v:', $this->asset, 'release');
    }
}
