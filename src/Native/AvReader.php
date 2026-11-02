<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\{Chapter, Packet, Track};
use RuntimeException;

/**
 * Reads what libavformat reads (MP4, MOV, M4V, MP3, M4A…) as a Player's
 * source on Linux, as AssetReader does on macOS: its audio, video and
 * text subtitle streams as tracks, numbered from 1 in the file's order,
 * their codecs named as Matroska names them (Av::CODECS; another FFmpeg
 * knows, by its name after Av::OTHER) and their decoder configuration
 * (FFmpeg's extradata: an avcC, an AudioSpecificConfig…) as Matroska's
 * CodecPrivate holds it; the packets in the order stored, their bytes
 * copied, their times in seconds. A seek reads anew from the keyframe at
 * or before the time.
 *
 * @internal
 */
final class AvReader implements \MyPHP\Platform\MediaReader
{
    /** AVMEDIA_TYPE_VIDEO, _AUDIO, _SUBTITLE; AV_DISPOSITION_DEFAULT, _ATTACHED_PIC; AV_PKT_FLAG_KEY; AV_TIME_BASE. */
    private const TYPES = [0 => Track::VIDEO, 1 => Track::AUDIO, 3 => Track::SUBTITLE], DEFAULT = 1, ATTACHED_PIC = 1024, KEY = 1, TIME_BASE = 1000000;

    /** AVFormatContext*, owned; the packet read into. */
    private CData $fmt, $packet;
    /** @var array<int, Track> the tracks, by their stream's index */
    private array $tracks = [];
    /** @var array<int, float> each track's stream's time base, seconds a tick, by index */
    private array $scales = [];
    private float $duration;
    /** @var ?list<Chapter> */
    private ?array $chapters = null;

    public function __construct(string $path)
    {
        $f = Av::ffi();
        if (!is_file($path)) {
            throw new RuntimeException("cannot open $path");
        }
        $fmt = $f->new('AVFormatContext*');
        Av::check($f->avformat_open_input(FFI::addr($fmt), $path, null, null), "opening $path");
        $this->fmt = $fmt;
        try {
            $format = FFI::string($fmt->iformat->name);
            if ($format === 'image2' || str_ends_with($format, '_pipe')) { // a still picture (JPEG, PNG…), which FFmpeg reads as a stream of one frame: not a film, as AVFoundation reads none
                throw new RuntimeException("$path is a picture ($format), not a film");
            }
            $extensions = $fmt->iformat->extensions === null ? '' : FFI::string($fmt->iformat->extensions);
            if (!$f->av_match_ext($path, $extensions)) { // read by its kind, its extension, as AVFoundation reads a file: a film named notes.txt is no film
                throw new RuntimeException("$path is not named as a $format file is (" . ($extensions === '' ? 'none' : $extensions) . ')');
            }
            Av::check($f->avformat_find_stream_info($fmt, null), "reading $path");
        } catch (RuntimeException $e) {
            $f->avformat_close_input(FFI::addr($this->fmt));
            throw $e;
        }
        $this->duration = $fmt->duration === Av::NOPTS ? 0.0 : $fmt->duration / self::TIME_BASE;
        for ($i = 0; $i < $fmt->nb_streams; $i++) {
            $s = $fmt->streams[$i];
            $par = $s->codecpar;
            $type = self::TYPES[$par->codec_type] ?? null;
            if ($type === null || ($s->disposition & self::ATTACHED_PIC) !== 0) { // a cover is no video: cover()'s
                continue;
            }
            $fps = $s->avg_frame_rate;
            $language = Av::tag($s->metadata, 'language');
            $this->tracks[$i] = new Track(
                number: $i + 1,
                type: $type,
                codec: Av::codecName($f->avcodec_get_name($par->codec_id)),
                private: $par->extradata_size > 0 ? FFI::string($par->extradata, $par->extradata_size) : '',
                language: $language === '' ? 'und' : $language,
                name: Av::tag($s->metadata, 'title'),
                isDefault: ($s->disposition & self::DEFAULT) !== 0,
                frameDuration: $type === Track::VIDEO && $fps->num > 0 && $fps->den > 0 ? $fps->den / $fps->num : 0.0,
                width: $type === Track::VIDEO ? $par->width : 0,
                height: $type === Track::VIDEO ? $par->height : 0,
                sampleRate: $type === Track::AUDIO ? (float)$par->sample_rate : 0.0,
                channels: $type === Track::AUDIO ? $par->ch_layout->nb_channels : 0,
            );
            $this->scales[$i] = $s->time_base->num / $s->time_base->den;
        }
        if (!array_filter($this->tracks, fn(Track $t) => $t->type !== Track::SUBTITLE)) {
            $this->__destruct();
            throw new RuntimeException("$path has no audio or video FFmpeg reads");
        }
        $this->packet = $f->av_packet_alloc();
    }

    public function tracks(): array
    {
        return array_values($this->tracks);
    }

    public function duration(): float
    {
        return $this->duration;
    }

    /** The file's tags (ID3's, iTunes', QuickTime's, as libavformat names them), title, artist and album. */
    public function tags(): array
    {
        return ['title' => trim(Av::tag($this->fmt->metadata, 'title')), 'artist' => trim(Av::tag($this->fmt->metadata, 'artist')), 'album' => trim(Av::tag($this->fmt->metadata, 'album'))];
    }

    /** The file's ReplayGain tags (ID3's TXXX, iTunes's ----, as libavformat names them; those of the first audio stream after), dB and linear peaks. */
    public function replayGain(): array
    {
        $out = ['trackGain' => null, 'trackPeak' => null, 'albumGain' => null, 'albumPeak' => null];
        $dicts = [$this->fmt->metadata];
        foreach ($this->tracks as $i => $t) {
            if ($t->type === Track::AUDIO) { $dicts[] = $this->fmt->streams[$i]->metadata; break; }
        }
        foreach (['replaygain_track_gain' => 'trackGain', 'replaygain_track_peak' => 'trackPeak', 'replaygain_album_gain' => 'albumGain', 'replaygain_album_peak' => 'albumPeak'] as $key => $field) {
            foreach ($dicts as $d) {
                if (is_numeric($v = trim((string)preg_replace('/\s*dB$/i', '', Av::tag($d, $key))))) { $out[$field] = (float)$v; break; }
            }
        }
        return $out;
    }

    /** The file's cover: the picture a stream attached (ID3's APIC, iTunes's covr), its bytes; null for none. */
    public function cover(): ?string
    {
        for ($i = 0; $i < $this->fmt->nb_streams; $i++) {
            $s = $this->fmt->streams[$i];
            if (($s->disposition & self::ATTACHED_PIC) !== 0 && $s->attached_pic->size > 0) {
                return FFI::string($s->attached_pic->data, $s->attached_pic->size);
            }
        }
        return null;
    }

    /** The file's chapters (QuickTime's, Nero's, ID3's CHAP), their titles and starts. */
    public function chapters(): array
    {
        if ($this->chapters !== null) {
            return $this->chapters;
        }
        $this->chapters = [];
        for ($i = 0; $i < $this->fmt->nb_chapters; $i++) {
            $c = $this->fmt->chapters[$i];
            $this->chapters[] = new Chapter(Av::tag($c->metadata, 'title'), $c->start * $c->time_base->num / $c->time_base->den);
        }
        return $this->chapters;
    }

    public function seek(float $seconds): void
    {
        $ts = (int)round($seconds * self::TIME_BASE);
        Av::check(Av::ffi()->avformat_seek_file($this->fmt, -1, PHP_INT_MIN, $ts, $ts, 0), sprintf('seeking to %.3f s', $seconds));
    }

    /** A file's: always. */
    public function ready(): bool
    {
        return true;
    }

    /** All its tracks are read: they are of one file. */
    public function choose(?Track $video, ?Track $audio): void {}

    /** The first keyframe of the video from a seek to $seconds; null for none. */
    public function keyframe(float $seconds): ?Packet
    {
        $this->seek($seconds);
        while (($p = $this->next()) !== null) {
            if ($p->track->type === Track::VIDEO && $p->keyframe) {
                return $p;
            }
        }
        return null;
    }

    /** The next packet of the tracks, in the order stored; null at the end. */
    public function next(): ?Packet
    {
        $f = Av::ffi();
        $pk = $this->packet;
        while (true) {
            $st = $f->av_read_frame($this->fmt, $pk);
            if ($st === Av::EOF) {
                return null;
            }
            Av::check($st, 'reading a packet');
            try {
                $t = $this->tracks[$pk->stream_index] ?? null;
                if ($t === null || $pk->size <= 0) {
                    continue;
                }
                $k = $this->scales[$pk->stream_index];
                $time = ($pk->pts !== Av::NOPTS ? $pk->pts : ($pk->dts !== Av::NOPTS ? $pk->dts : 0)) * $k;
                return new Packet($t, $time, $pk->duration * $k, $t->type !== Track::VIDEO || ($pk->flags & self::KEY) !== 0, FFI::string($pk->data, $pk->size));
            } finally {
                $f->av_packet_unref($pk);
            }
        }
    }

    public function __destruct()
    {
        $f = Av::ffi();
        if (isset($this->packet)) { $f->av_packet_free(FFI::addr($this->packet)); }
        if (isset($this->fmt)) { $f->avformat_close_input(FFI::addr($this->fmt)); }
    }
}
