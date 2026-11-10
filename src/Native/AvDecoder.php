<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\{Packet, Track};
use RuntimeException;

/**
 * libavcodec's decoder of a track's packets (AvRenderers,
 * AvThumbnails): made of the track as the core reads it, its codec
 * Matroska's id and its CodecPrivate the decoder's extradata (an avcC,
 * an AudioSpecificConfig, an OpusHead, Vorbis's Xiph-laced headers…, as
 * FFmpeg's Matroska demuxer gives them), its packets' times microseconds.
 * With $threads 0 it decodes on as many threads as the cores (FFmpeg's
 * frame threads: a packet sent returns before its frame is decoded,
 * which a later receive() gives). With $hardware, a video track decodes
 * on the system's decoders where the codec has them and a device opens
 * (hardwareOf: VA-API on Linux, D3D11VA on Windows), its surfaces copied to
 * memory as they come out (av_hwframe_transfer_data: NV12 or P010, which
 * libswscale converts as any); else in software, why in $software.
 *
 * @internal
 */
final class AvDecoder
{
    /** The codec context, owned. */
    private CData $ctx;
    private CData $packet;
    /** FFmpeg's name of the decoder ("h264", "libdav1d"). */
    public readonly string $name;
    /** Whether it decodes on the system's decoders (hardwareName()'s); why not, where hardware was asked. */
    public readonly bool $accelerated;
    public readonly ?string $software;
    /** AVCodecContext's hw_device_ctx, by libavcodec's major version (FFmpeg 7.1's and 6.1's headers). */
    private const HW_DEVICE = [61 => 560, 60 => 864];
    /** AV_HWDEVICE_TYPE_VAAPI, AV_HWDEVICE_TYPE_D3D11VA, AV_CODEC_HW_CONFIG_METHOD_HW_DEVICE_CTX. */
    private const VAAPI = 3, D3D11VA = 7, HW_DEVICE_CTX = 1;
    /** The system's device (an AVBufferRef*), opened once; why it did not open; its frames' format (AV_PIX_FMT_VAAPI, AV_PIX_FMT_D3D11). */
    private static ?CData $device = null;
    private static ?string $deviceFailed = null;
    private static int $hardwareFormat = -1;

    public function __construct(public readonly Track $track, int $threads, bool $hardware = false)
    {
        $f = Av::ffi();
        $id = Av::codecId($track) ?? throw new RuntimeException("myphp: FFmpeg knows no codec {$track->codec}");
        $codec = $f->avcodec_find_decoder($id) ?? throw new RuntimeException("myphp: this FFmpeg has no decoder of {$track->codec}");
        $this->name = FFI::string($codec->name);
        $par = $f->avcodec_parameters_alloc();
        $par->codec_type = $track->type === Track::VIDEO ? Av::VIDEO : Av::AUDIO;
        $par->codec_id = $id;
        if ($track->private !== '') { // freed with the parameters: av_mallocz's, padded as FFmpeg reads past the end
            $bytes = $track->private; // FFI::memcpy takes its arguments by reference
            $n = strlen($bytes);
            $par->extradata = $f->cast('uint8_t*', $f->av_mallocz($n + 64));
            FFI::memcpy($par->extradata, $bytes, $n);
            $par->extradata_size = $n;
        }
        if ($track->type === Track::VIDEO) {
            [$par->width, $par->height] = [$track->width, $track->height];
        } else {
            $par->sample_rate = (int)$track->sampleRate;
            if ($track->channels > 0) { $f->av_channel_layout_default(FFI::addr($par->ch_layout), $track->channels); }
        }
        $ctx = $f->avcodec_alloc_context3($codec);
        try {
            Av::check($f->avcodec_parameters_to_context($ctx, $par), "a decoder of {$track->codec}");
        } finally {
            $f->avcodec_parameters_free(FFI::addr($par));
        }
        $why = !$hardware ? null : ($track->type !== Track::VIDEO ? 'not a video track' : self::hardwareOf($codec));
        if ($hardware && $why === null) { // libavcodec's own get_format picks the device's surfaces once it is set, falling back to software where it cannot (no PHP on its threads)
            $f->cast('void**', $f->cast('char*', $ctx) + self::HW_DEVICE[Av::$major])[0] = $f->av_buffer_ref(self::$device);
        }
        [$this->accelerated, $this->software] = [$hardware && $why === null, $hardware ? $why : 'software asked'];
        $tb = $f->new('AVRational');
        [$tb->num, $tb->den] = [1, Av::SCALE];
        $f->av_opt_set_q($ctx, 'pkt_timebase', $tb, 0);
        $f->av_opt_set_int($ctx, 'threads', $threads, 0);
        $this->ctx = $ctx;
        try {
            Av::check($f->avcodec_open2($ctx, $codec, null), "opening FFmpeg's decoder of {$track->codec}");
        } catch (RuntimeException $e) {
            $this->free();
            throw $e;
        }
        $this->packet = $f->av_packet_alloc();
    }

    /**
     * Sends $p to the decoder, null for the end (the frames it holds then
     * come out); false while it takes none before its frames are received.
     */
    public function send(?Packet $p): bool
    {
        $f = Av::ffi();
        if ($p === null) {
            $st = $f->avcodec_send_packet($this->ctx, null);
            return $st === Av::EOF || Av::check($st, "decoding {$this->track->codec}") >= 0;
        }
        $pk = $this->packet;
        $bytes = $p->data;
        $n = strlen($bytes);
        Av::check($f->av_new_packet($pk, $n), 'a packet');
        FFI::memcpy($pk->data, $bytes, $n);
        $pk->pts = (int)round($p->time * Av::SCALE);
        $pk->dts = Av::NOPTS;
        $pk->duration = (int)round($p->duration * Av::SCALE);
        $pk->flags = $p->keyframe ? 1 : 0; // AV_PKT_FLAG_KEY
        $st = $f->avcodec_send_packet($this->ctx, $pk);
        $f->av_packet_unref($pk);
        if ($st === Av::EAGAIN) {
            return false;
        }
        if ($st < 0) { // a broken packet: FFmpeg's decoders go on at the next, as mpv does
            \MyPHP\Logger::warn(sprintf('%s: the packet at %.3f s does not decode: %s', $this->name, $p->time, Av::error($st)), 'media');
        }
        return true;
    }

    /** The next frame decoded, an AVFrame* of the caller's (Av::ffi()->av_frame_free); null while none is ready, or at the end. */
    public function receive(): ?CData
    {
        $f = Av::ffi();
        $frame = $f->av_frame_alloc();
        $st = $f->avcodec_receive_frame($this->ctx, $frame);
        if ($st === 0 && $frame->format === self::$hardwareFormat && $this->accelerated) { // the device's surface: its pixels copied to memory
            $sw = $f->av_frame_alloc();
            try {
                Av::check($f->av_hwframe_transfer_data($sw, $frame, 0), 'copying a ' . self::hardwareName() . ' frame to memory');
                $f->av_frame_copy_props($sw, $frame);
            } catch (RuntimeException $e) {
                self::release($sw);
                throw $e;
            } finally {
                self::release($frame);
            }
            return $sw;
        }
        if ($st === 0) {
            return $frame;
        }
        self::release($frame);
        if ($st === Av::EAGAIN || $st === Av::EOF) {
            return null;
        }
        throw new RuntimeException("myphp: decoding {$this->track->codec}: " . Av::error($st));
    }

    /** The system's decoders: "VA-API" on Linux, "D3D11VA" on Windows. */
    public static function hardwareName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'D3D11VA' : 'VA-API';
    }

    /**
     * Why codec $codec does not decode on the system's decoders here, null
     * where it does: the codec has no configuration of the system's device
     * type, or no device opens (Linux: the render node $MYPHP_VAAPI_DEVICE,
     * else libva's choice, /dev/dri/renderD128…; Windows: the default
     * adapter's Direct3D 11 device). The device is opened once, for every
     * decoder.
     */
    private static function hardwareOf(CData $codec): ?string
    {
        $f = Av::ffi();
        [$type, $format] = PHP_OS_FAMILY === 'Windows' ? [self::D3D11VA, 'd3d11'] : [self::VAAPI, 'vaapi'];
        $has = false;
        for ($i = 0; ($c = $f->avcodec_get_hw_config($codec, $i)) !== null; $i++) {
            if ($c->device_type === $type && ($c->methods & self::HW_DEVICE_CTX) !== 0) { $has = true; }
        }
        if (!$has) {
            return 'its decoder (' . FFI::string($codec->name) . ') has no ' . self::hardwareName();
        }
        if (self::$device === null && self::$deviceFailed === null) {
            $dev = $f->new('void*');
            $node = PHP_OS_FAMILY === 'Windows' ? false : getenv('MYPHP_VAAPI_DEVICE');
            $st = $f->av_hwdevice_ctx_create(FFI::addr($dev), $type, $node === false || $node === '' ? null : $node, null, 0);
            if ($st < 0) {
                self::$deviceFailed = 'no ' . self::hardwareName() . ' device opens (' . ($node === false || $node === '' ? 'the default' : $node) . '): ' . Av::error($st);
            } else {
                [self::$device, self::$hardwareFormat] = [$dev, $f->av_get_pix_fmt($format)];
            }
        }
        return self::$deviceFailed;
    }

    /** Whether track $t decodes on the system's decoders here, and why not ([bool, ?string]): Media::hardwareDecodes, the Inspector's. @return array{0: bool, 1: ?string} */
    public static function hardwareFor(Track $t): array
    {
        $id = Av::codecId($t);
        $codec = $id === null ? null : Av::ffi()->avcodec_find_decoder($id);
        if ($t->type !== Track::VIDEO || $codec === null) {
            return [false, 'not a video track FFmpeg decodes'];
        }
        $why = self::hardwareOf($codec);
        return [$why === null, $why];
    }

    /** The time of $frame, seconds: its best effort timestamp, null where it has none. */
    public static function time(CData $frame): ?float
    {
        return $frame->best_effort_timestamp === Av::NOPTS ? null : $frame->best_effort_timestamp / Av::SCALE;
    }

    /** Frees $frame, receive()'s. */
    public static function release(CData $frame): void
    {
        Av::ffi()->av_frame_free(FFI::addr($frame));
    }

    /** Forgets what it holds, for a seek, and takes packets again after the end. */
    public function flush(): void
    {
        Av::ffi()->avcodec_flush_buffers($this->ctx);
    }

    private function free(): void
    {
        Av::ffi()->avcodec_free_context(FFI::addr($this->ctx));
    }

    public function __destruct()
    {
        if (isset($this->packet)) {
            Av::ffi()->av_packet_free(FFI::addr($this->packet));
            $this->free();
        }
    }
}
