<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\Track;
use RuntimeException;

/**
 * FFmpeg's libraries over FFI, for the player on Linux (Platform\Linux\
 * Renderers, AvReader, AvDecoder): libavformat, libavcodec and libavutil,
 * libswscale and libswresample of FFmpeg 6 (libavcodec 60, libavutil 58),
 * the sonames Ubuntu 24.04 and Debian 12 ship. They are loaded into the
 * global namespace (dlopen RTLD_GLOBAL) so that one FFI instance has the
 * functions of all five, whose structs are passed among them. The structs
 * are declared as far as they are read, at FFmpeg 6.1's layout (checked
 * against its headers: docs/architecture.md); another major version of
 * the libraries has other layouts and other sonames, and does not load.
 * FFmpeg's own log is off: what fails is told by its return codes, which
 * the callers throw or log (MyPHP\Logger), its threads never calling PHP.
 *
 * @internal
 */
final class Av
{
    /** The sonames of FFmpeg 6, the libraries loaded globally, the others first. */
    public const LIBRARIES = ['libavutil.so.58', 'libswresample.so.4', 'libswscale.so.7', 'libavcodec.so.60', 'libavformat.so.60'];
    /** Matroska's codec ids (the core names codecs so) and FFmpeg's codec names of them. */
    public const CODECS = [
        'V_MPEG4/ISO/AVC' => 'h264', 'V_MPEGH/ISO/HEVC' => 'hevc', 'V_VP9' => 'vp9', 'V_VP8' => 'vp8', 'V_AV1' => 'av1',
        'V_MPEG2' => 'mpeg2video', 'V_MPEG1' => 'mpeg1video', 'V_MPEG4/ISO/ASP' => 'mpeg4', 'V_THEORA' => 'theora',
        'A_AAC' => 'aac', 'A_AC3' => 'ac3', 'A_EAC3' => 'eac3', 'A_MPEG/L3' => 'mp3', 'A_MPEG/L2' => 'mp2', 'A_OPUS' => 'opus',
        'A_FLAC' => 'flac', 'A_VORBIS' => 'vorbis', 'A_DTS' => 'dts', 'A_TRUEHD' => 'truehd', 'A_ALAC' => 'alac', 'S_TEXT/TX3G' => 'mov_text',
    ];
    /** What a track names a codec Matroska has no id of: FFmpeg's name after it. */
    public const OTHER = 'ffmpeg:';
    /** AVERROR(EAGAIN), AVERROR_EOF; AV_NOPTS_VALUE; AVMEDIA_TYPE_VIDEO, _AUDIO; AV_PIX_FMT_RGBA; AV_SAMPLE_FMT_FLT; SWS_BILINEAR. */
    public const EAGAIN = -11, EOF = -541478725, NOPTS = PHP_INT_MIN, VIDEO = 0, AUDIO = 1, RGBA = 26, FLT = 3, BILINEAR = 2;
    /** The packets' and frames' times, microseconds. */
    public const SCALE = 1000000;

    private static ?FFI $f = null;

    public static function ffi(): FFI
    {
        if (self::$f !== null) {
            return self::$f;
        }
        $dl = FFI::cdef('void* dlopen(const char*, int); char* dlerror(void);', 'libc.so.6');
        foreach (self::LIBRARIES as $lib) {
            if ($dl->dlopen($lib, 2 | 0x100 | 0x1000) === null) { // RTLD_NOW | RTLD_GLOBAL | RTLD_NODELETE: its threads may outlive PHP's shutdown
                throw new RuntimeException("myphp: FFmpeg 6's $lib does not load (" . FFI::string($dl->dlerror()) . '): the player on Linux decodes with it (apt install ffmpeg)');
            }
        }
        $f = FFI::cdef(<<<'C'
            typedef struct { int num, den; } AVRational;
            typedef struct { int order; int nb_channels; uint64_t mask; void *opaque; } AVChannelLayout;
            typedef struct { const char *key; char *value; } AVDictionaryEntry;
            typedef struct AVPacket { void *buf; int64_t pts, dts; uint8_t *data; int size, stream_index, flags; void *side_data; int side_data_elems; int64_t duration, pos; void *opaque, *opaque_ref; AVRational time_base; } AVPacket;
            typedef struct AVFrame {
                uint8_t *data[8]; int linesize[8]; uint8_t **extended_data; int width, height, nb_samples, format;
                int key_frame, pict_type; AVRational sample_aspect_ratio; int64_t pts, pkt_dts; AVRational time_base;
                char _a[48]; int sample_rate; char _b[104]; int flags, color_range, color_primaries, color_trc, colorspace;
                char _c[8]; int64_t best_effort_timestamp; char _d[96]; AVChannelLayout ch_layout; int64_t duration;
            } AVFrame;
            typedef struct AVCodecParameters {
                int codec_type, codec_id; uint32_t codec_tag; uint8_t *extradata; int extradata_size, format; int64_t bit_rate;
                int bits_per_coded_sample, bits_per_raw_sample, profile, level, width, height; AVRational sample_aspect_ratio;
                int field_order, color_range, color_primaries, color_trc, color_space, chroma_location, video_delay;
                uint64_t channel_layout; int channels, sample_rate, block_align, frame_size, initial_padding, trailing_padding, seek_preroll;
                AVChannelLayout ch_layout; AVRational framerate; void *coded_side_data; int nb_coded_side_data;
            } AVCodecParameters;
            typedef struct AVCodec { const char *name; const char *long_name; int type, id; } AVCodec;
            typedef struct AVCodecDescriptor { int id, type; const char *name; const char *long_name; } AVCodecDescriptor;
            typedef struct AVInputFormat { const char *name; const char *long_name; int flags; const char *extensions; } AVInputFormat;
            typedef struct AVStream {
                void *av_class; int index, id; AVCodecParameters *codecpar; void *priv_data; AVRational time_base;
                int64_t start_time, duration, nb_frames; int disposition, discard; AVRational sample_aspect_ratio;
                void *metadata; AVRational avg_frame_rate; AVPacket attached_pic;
            } AVStream;
            typedef struct AVChapter { int64_t id; AVRational time_base; int64_t start, end; void *metadata; } AVChapter;
            typedef struct AVFormatContext {
                void *av_class; const AVInputFormat *iformat; void *oformat, *priv_data, *pb; int ctx_flags; unsigned int nb_streams;
                AVStream **streams; char *url; int64_t start_time, duration, bit_rate; unsigned int packet_size; int max_delay, flags;
                int64_t probesize, max_analyze_duration; const uint8_t *key; int keylen; unsigned int nb_programs; void **programs;
                int video_codec_id, audio_codec_id, subtitle_codec_id; unsigned int max_index_size, max_picture_buffer, nb_chapters;
                AVChapter **chapters; void *metadata;
            } AVFormatContext;

            void av_log_set_level(int);
            int av_strerror(int, char*, size_t);
            void *av_mallocz(size_t);
            void av_free(void*);
            AVDictionaryEntry *av_dict_get(const void*, const char*, const AVDictionaryEntry*, int);
            void av_channel_layout_default(AVChannelLayout*, int);
            void av_channel_layout_uninit(AVChannelLayout*);
            int av_opt_set_int(void*, const char*, int64_t, int);
            int av_opt_set_q(void*, const char*, AVRational, int);
            AVFrame *av_frame_alloc(void);
            void av_frame_free(AVFrame**);
            AVPacket *av_packet_alloc(void);
            void av_packet_free(AVPacket**);
            void av_packet_unref(AVPacket*);
            int av_new_packet(AVPacket*, int);

            const AVCodecDescriptor *avcodec_descriptor_get_by_name(const char*);
            const char *avcodec_get_name(int);
            const AVCodec *avcodec_find_decoder(int);
            void *avcodec_alloc_context3(const AVCodec*);
            void avcodec_free_context(void**);
            AVCodecParameters *avcodec_parameters_alloc(void);
            void avcodec_parameters_free(AVCodecParameters**);
            int avcodec_parameters_to_context(void*, const AVCodecParameters*);
            int avcodec_open2(void*, const AVCodec*, void**);
            int avcodec_send_packet(void*, const AVPacket*);
            int avcodec_receive_frame(void*, AVFrame*);
            void avcodec_flush_buffers(void*);

            int avformat_open_input(AVFormatContext**, const char*, const void*, void**);
            int av_match_ext(const char*, const char*);
            int avformat_find_stream_info(AVFormatContext*, void**);
            int av_read_frame(AVFormatContext*, AVPacket*);
            int avformat_seek_file(AVFormatContext*, int, int64_t, int64_t, int64_t, int);
            void avformat_close_input(AVFormatContext**);

            void *sws_getCachedContext(void*, int, int, int, int, int, int, int, void*, void*, const double*);
            const int *sws_getCoefficients(int);
            int sws_setColorspaceDetails(void*, const int*, int, const int*, int, int, int, int);
            int sws_scale(void*, uint8_t **, const int*, int, int, uint8_t **, const int*);
            void sws_freeContext(void*);

            int swr_alloc_set_opts2(void**, const AVChannelLayout*, int, int, const AVChannelLayout*, int, int, int, void*);
            int swr_init(void*);
            int swr_get_out_samples(void*, int);
            int swr_convert(void*, uint8_t **, int, uint8_t **, int);
            void swr_free(void**);
            C);
        $f->av_log_set_level(-8); // AV_LOG_QUIET: on stderr, from its threads, else
        return self::$f = $f;
    }

    /** FFmpeg's message of error $code. */
    public static function error(int $code): string
    {
        $f = self::ffi();
        $buf = $f->new('char[128]');
        $f->av_strerror($code, $buf, 128);
        return FFI::string($buf);
    }

    /** FFmpeg's codec id of track $t's codec (Matroska's id, or FFmpeg's name after OTHER), null for one FFmpeg does not know. */
    public static function codecId(Track $t): ?int
    {
        $name = str_starts_with($t->codec, self::OTHER) ? substr($t->codec, strlen(self::OTHER)) : (self::CODECS[$t->codec] ?? null);
        $d = $name === null ? null : self::ffi()->avcodec_descriptor_get_by_name($name);
        return $d === null ? null : $d->id;
    }

    /** The codec id of a track of FFmpeg's codec named $name: Matroska's where it has one, else OTHER and the name. */
    public static function codecName(string $name): string
    {
        $id = array_search($name, self::CODECS, true);
        return $id === false ? self::OTHER . $name : $id;
    }

    /** The value of $key in AVDictionary $dict, '' where it has none (case-insensitive, as FFmpeg's tags are). */
    public static function tag(?CData $dict, string $key): string
    {
        if ($dict === null) {
            return '';
        }
        $e = self::ffi()->av_dict_get($dict, $key, null, 0);
        return $e === null ? '' : FFI::string($e->value);
    }

    /** Throws FFmpeg's error of $code (negative) as what $doing failed; returns $code otherwise. */
    public static function check(int $code, string $doing): int
    {
        if ($code < 0) {
            throw new RuntimeException("myphp: $doing: " . self::error($code));
        }
        return $code;
    }
}
