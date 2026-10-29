<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Media\{Chapter, Packet, Source, Track};
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
        'aac ' => 'A_AAC', 'ac-3' => 'A_AC3', 'ec-3' => 'A_EAC3', '.mp3' => 'A_MPEG/L3', 'opus' => 'A_OPUS', 'flac' => 'A_FLAC', 'tx3g' => 'S_TEXT/TX3G'];
    /** The track types of AVFoundation's media types: text subtitles are 'sbtl', or 'text' in QuickTime files. */
    private const TYPES = ['vide' => Track::VIDEO, 'soun' => Track::AUDIO, 'sbtl' => Track::SUBTITLE, 'text' => Track::SUBTITLE];

    private $asset;
    /** @var list<Track> */
    private array $tracks = [];
    /** @var array<int, mixed> the asset's tracks by number */
    private array $native = [];
    private float $duration;
    /** @var ?list<Chapter> read as first asked for */
    private ?array $chapters = null;
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

    /**
     * The chapters AVFoundation reads (a QuickTime chapter track): in the
     * language the user prefers of those their titles come in, else in the
     * file's first (an undetermined one, "und", as ffmpeg and HandBrake
     * write it, matches no preferred language); each group's start and its
     * common title.
     */
    /** @internal Player: the file's common metadata (AVFoundation's: ID3, iTunes, QuickTime), title, artist and album, '' each where it has none. @return array{title: string, artist: string, album: string} */
    public function tags(): array
    {
        $out = ['title' => '', 'artist' => '', 'album' => ''];
        foreach (['title' => 'title', 'artist' => 'artist', 'albumName' => 'album'] as $key => $name) {
            $item = $this->common($key);
            $v = $item === null ? null : ObjC::msg('p:', $item, 'stringValue');
            if ($v !== null) { $out[$name] = trim(ObjC::phpStr($v)); }
        }
        return $out;
    }

    /**
     * @internal Player: the file's ReplayGain tags, as its metadata names
     * them in any of its formats (ID3's TXXX, iTunes's ----): dB, and the
     * peaks' linear amplitudes; null where none.
     * @return array{trackGain: ?float, trackPeak: ?float, albumGain: ?float, albumPeak: ?float}
     */
    public function replayGain(): array
    {
        $out = ['trackGain' => null, 'trackPeak' => null, 'albumGain' => null, 'albumPeak' => null];
        $names = ['replaygain_track_gain' => 'trackGain', 'replaygain_track_peak' => 'trackPeak', 'replaygain_album_gain' => 'albumGain', 'replaygain_album_peak' => 'albumPeak'];
        $items = ObjC::msg('p:', $this->asset, 'metadata');
        for ($i = 0, $n = ObjC::msg('L:', $items, 'count'); $i < $n; $i++) {
            $item = ObjC::msg('p:L', $items, 'objectAtIndex:', $i);
            $id = ObjC::msg('p:', $item, 'identifier');
            $value = ObjC::msg('p:', $item, 'stringValue');
            if ($value === null) { continue; }
            $value = ObjC::phpStr($value);
            $name = strtolower($id === null ? '' : ObjC::phpStr($id));
            $extra = ObjC::msg('p:', $item, 'extraAttributes'); // ID3's TXXX: its description among them
            if ($extra !== null) { $info = ObjC::msg('p:p', $extra, 'objectForKey:', ObjC::str('info')); if ($info !== null && ObjC::msg('b:p', $info, 'isKindOfClass:', ObjC::cls('NSString'))) { $name .= '/' . strtolower(ObjC::phpStr($info)); } }
            foreach ($names as $key => $field) {
                if (str_contains($name, $key) && is_numeric($v = trim(preg_replace('/\s*dB$/i', '', $value)))) { $out[$field] = (float)$v; }
            }
        }
        return $out;
    }

    /** @internal Player: the file's artwork (its cover), its image's bytes; null for none. */
    public function cover(): ?string
    {
        $item = $this->common('artwork');
        $data = $item === null ? null : ObjC::msg('p:', $item, 'dataValue');
        return $data === null ? null : Drag::bytes($data);
    }

    /** The first of the asset's common metadata items of common key $key (AVMetadataCommonKey…), null for none. */
    private function common(string $key)
    {
        $items = ObjC::msg('p:', $this->asset, 'commonMetadata');
        for ($i = 0, $n = ObjC::msg('L:', $items, 'count'); $i < $n; $i++) {
            $item = ObjC::msg('p:L', $items, 'objectAtIndex:', $i);
            $k = ObjC::msg('p:', $item, 'commonKey');
            if ($k !== null && ObjC::phpStr($k) === $key) { return $item; }
        }
        return null;
    }

    public function chapters(): array
    {
        if ($this->chapters !== null) {
            return $this->chapters;
        }
        $this->chapters = [];
        $langs = ObjC::msg('p:', ObjC::cls('NSLocale'), 'preferredLanguages');
        $groups = ObjC::msg('p:p', $this->asset, 'chapterMetadataGroupsBestMatchingPreferredLanguages:', $langs);
        if ($groups === null || ObjC::msg('L:', $groups, 'count') === 0) {
            $locales = ObjC::msg('p:', $this->asset, 'availableChapterLocales');
            $groups = $locales === null || ObjC::msg('L:', $locales, 'count') === 0 ? null
                : ObjC::msg('p:pp', $this->asset, 'chapterMetadataGroupsWithTitleLocale:containingItemsWithCommonKeys:', ObjC::msg('p:L', $locales, 'objectAtIndex:', 0), null);
        }
        for ($i = 0, $n = $groups === null ? 0 : ObjC::msg('L:', $groups, 'count'); $i < $n; $i++) {
            $items = ObjC::msg('p:', ObjC::msg('p:L', $groups, 'objectAtIndex:', $i), 'items');
            // The chapter's start is its items' time (a CMTime, as the group's timeRange starts), its title the common title's value.
            $title = ''; $start = null;
            for ($k = 0, $m = ObjC::msg('L:', $items, 'count'); $k < $m; $k++) {
                $it = ObjC::msg('p:L', $items, 'objectAtIndex:', $k);
                $start ??= CoreMedia::seconds(ObjC::msg('T:', $it, 'time'));
                $key = ObjC::msg('p:', $it, 'commonKey');
                if ($key !== null && ObjC::phpStr($key) === 'title') {
                    $v = ObjC::msg('p:', $it, 'stringValue');
                    $title = $v === null ? '' : ObjC::phpStr($v);
                }
            }
            if ($start !== null) { // a group of no valid time is no chapter one can go to
                $this->chapters[] = new Chapter($title, $start);
            }
        }
        return $this->chapters;
    }

    public function seek(float $seconds): void
    {
        $this->start($seconds);
    }

    /** A file's: always. */
    public function ready(): bool
    {
        return true;
    }

    /** The first keyframe of the video from a seek to $seconds; null for none. */
    /** All its tracks are read: they are of one file. */
    public function choose(?Track $video, ?Track $audio): void {}

    public function keyframe(float $seconds): ?Packet
    {
        $this->start($seconds);
        while (($p = $this->next()) !== null) {
            if ($p->track->type === Track::VIDEO && $p->keyframe) {
                return $p;
            }
        }
        return null;
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
