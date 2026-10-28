<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\Packet;
use RuntimeException;

/**
 * Audio decoded by the system into the player's hands (AudioToolbox's
 * AudioConverter), as mpv decodes before its filters: what the audio
 * renderer decodes itself, made 32-bit float PCM, interleaved, for the
 * equalizer to filter. Of a format description (a track's, or the sample
 * buffers' AVFoundation reads); a packet's bytes, or the packets of a
 * sample buffer as its packet descriptions say, in; the frames out, as
 * many as the codec gives (a packet's, or a part, the converter keeping
 * the rest for the next call), in the order of the channels its layout
 * says.
 *
 * @internal Native\Renderers
 */
final class AudioDecoder
{
    /** AudioConverter's properties: the magic cookie, the layout of the channels out. */
    private const COOKIE = 'dmgc', OUTPUT_LAYOUT = 'ocl ';
    /** What the input's callback says when it has given its packets: no more now. */
    private const NO_MORE = 1;
    /** The frames each call of the converter takes at most. */
    private const CAPACITY = 8192;

    private static ?FFI $at = null;
    private CData $converter, $out, $list, $count;
    public readonly int $channels, $rate;
    /** The layout of the channels out (an AudioChannelLayout's bytes), '' for the system's default (mono, stereo). */
    public readonly string $layout;
    /** What the input's callback gives next: the packets' bytes and descriptions, how many; null once given. */
    private ?array $input = null;
    /** What the converter reads of the packets given, kept until it took them. */
    private ?CData $held = null, $heldDescs = null;
    /** Whether the format's packets are of frames of their own (Opus's), which their descriptions say. */
    private bool $variable;
    /** The input callback, kept alive in a holder of its pointer for the converter's calls (FFI's callbacks as curl's are). */
    private CData $holder;

    /** A decoder of the audio of format description $format (CoreMedia's, owned by the caller). */
    public function __construct(CData $format)
    {
        $cm = CoreMedia::c();
        $at = self::at();
        $src = $cm->CMAudioFormatDescriptionGetStreamBasicDescription($format);
        $in = $at->new('ASBD');
        foreach (['rate', 'format', 'flags', 'bytesPerPacket', 'framesPerPacket', 'bytesPerFrame', 'channels', 'bits'] as $f) { $in->$f = $src->$f; }
        $this->variable = $in->framesPerPacket === 0;
        if ($in->format === CoreMedia::fourcc('opus') && $in->framesPerPacket === 0) {
            $in->framesPerPacket = 960; // Apple's Opus format: 20 ms of 48 kHz a packet, a packet's own frames in its description
        }
        $this->channels = $in->channels; $this->rate = (int)$in->rate;
        $out = $at->new('ASBD');
        $out->rate = $in->rate; $out->format = CoreMedia::fourcc('lpcm'); $out->flags = 9; // float, packed
        $out->bytesPerPacket = $out->bytesPerFrame = 4 * $this->channels; $out->framesPerPacket = 1; $out->channels = $this->channels; $out->bits = 32;
        $conv = $at->new('void*');
        if (($st = $at->AudioConverterNew(FFI::addr($in), FFI::addr($out), FFI::addr($conv))) !== 0) {
            throw new RuntimeException("myphp: no decoder of the system for " . self::fourccName($in->format) . " to PCM (AudioConverterNew: $st)");
        }
        $this->converter = $conv;
        $size = $cm->new('size_t');
        $cookie = $cm->CMAudioFormatDescriptionGetMagicCookie($format, FFI::addr($size));
        if ($cookie !== null && $size->cdata > 0) {
            $bytes = FFI::string($cookie, $size->cdata);
            if ($in->format === CoreMedia::fourcc('aac ') && ord($bytes[0]) !== 0x03) {
                $bytes = self::esds($bytes); // a Matroska track's AudioSpecificConfig: the converter takes an ES descriptor of it (AVFoundation's readers give one)
            }
            $buf = $at->new('unsigned char[' . strlen($bytes) . ']');
            FFI::memcpy($buf, $bytes, strlen($bytes));
            if (($st = $at->AudioConverterSetProperty($conv, CoreMedia::fourcc(self::COOKIE), strlen($bytes), FFI::addr($buf[0]))) !== 0) {
                throw new RuntimeException("myphp: the decoder of the system took no magic cookie (AudioConverterSetProperty: $st)");
            }
        }
        $this->layout = $this->layoutOut($format);
        $this->out = $at->new('float[' . (self::CAPACITY * $this->channels) . ']');
        $this->list = $at->new('AudioBufferList');
        $this->count = $at->new('unsigned int');
        $self = \WeakReference::create($this);
        $this->holder = $at->new('InputHolder');
        $this->holder->proc = static function ($conv, $packets, $data, $descs, $user) use ($self): int {
            return $self->get()?->give($packets, $data, $descs) ?? self::NO_MORE;
        };
    }

    /**
     * Packet $p decoded: its frames as float PCM bytes, interleaved, and
     * how many there are (none while the codec holds them).
     * @return array{0: string, 1: int}
     */
    public function decode(Packet $p): array
    {
        $this->input = $p->sample !== null ? $this->ofSample($p->sample->buffer) : [$p->data, [[0, $this->variable ? CoreMedia::frames($p) : 0, strlen($p->data)]], 1];
        $pcm = ''; $frames = 0;
        do {
            $this->list->count = 1;
            $this->list->buffers[0]->channels = $this->channels;
            $this->list->buffers[0]->size = 4 * self::CAPACITY * $this->channels;
            $this->list->buffers[0]->data = FFI::addr($this->out[0]);
            $this->count->cdata = self::CAPACITY;
            $st = self::at()->AudioConverterFillComplexBuffer($this->converter, $this->holder->proc, null, FFI::addr($this->count), FFI::addr($this->list), null);
            if ($st !== 0 && $st !== self::NO_MORE) {
                throw new RuntimeException("myphp: the decoder of the system: AudioConverterFillComplexBuffer: $st");
            }
            $n = $this->count->cdata;
            if ($n > 0) { $pcm .= FFI::string($this->out, 4 * $n * $this->channels); $frames += $n; }
        } while ($n === self::CAPACITY); // filled: there may be more of what it holds
        $this->input = null;
        return [$pcm, $frames];
    }

    /** Forgets what it holds, for a seek. */
    public function reset(): void
    {
        self::at()->AudioConverterReset($this->converter);
    }

    /**
     * The converter's input callback: the packets to decode, once, then
     * no more for now. @internal its callback
     */
    public function give(CData $packets, CData $data, $descs): int
    {
        if ($this->input === null) {
            $packets[0] = 0;
            return self::NO_MORE;
        }
        [$bytes, $list, $count] = $this->input;
        $at = self::at();
        $this->held = $at->new('unsigned char[' . max(1, strlen($bytes)) . ']');
        FFI::memcpy($this->held, $bytes, strlen($bytes));
        $data->count = 1;
        $data->buffers[0]->channels = $this->channels;
        $data->buffers[0]->size = strlen($bytes);
        $data->buffers[0]->data = FFI::addr($this->held[0]);
        if ($list !== [] && $descs !== null && !FFI::isNull($descs)) {
            $this->heldDescs = $at->new('ASPD[' . count($list) . ']');
            foreach ($list as $i => [$start, $frames, $size]) {
                $this->heldDescs[$i]->start = $start; $this->heldDescs[$i]->variableFrames = $frames; $this->heldDescs[$i]->size = $size;
            }
            $descs[0] = FFI::addr($this->heldDescs[0]);
        }
        $packets[0] = $count;
        $this->input = null;
        return 0;
    }

    /**
     * The packets of sample buffer $sb: its bytes, its packets' descriptions
     * (none for PCM, its samples its packets), how many.
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int}>, 2: int}
     */
    private function ofSample($sb): array
    {
        $cm = CoreMedia::c();
        $block = $cm->CMSampleBufferGetDataBuffer($sb);
        $len = $cm->CMBlockBufferGetDataLength($block);
        $buf = $cm->new("unsigned char[" . max(1, $len) . "]");
        $cm->CMBlockBufferCopyDataBytes($block, 0, $len, FFI::addr($buf[0]));
        $bytes = FFI::string($buf, $len);
        $ptr = $cm->new('void*'); $size = $cm->new('size_t');
        $list = [];
        if ($cm->CMSampleBufferGetAudioStreamPacketDescriptionsPtr($sb, FFI::addr($ptr), FFI::addr($size)) === 0 && !FFI::isNull($ptr) && $size->cdata > 0) {
            $d = $cm->cast('ASPD*', $ptr);
            for ($i = 0, $n = intdiv($size->cdata, FFI::sizeof($cm->type('ASPD'))); $i < $n; $i++) { $list[] = [$d[$i]->start, $d[$i]->variableFrames, $d[$i]->size]; }
        }
        return [$bytes, $list, $list !== [] ? count($list) : $cm->CMSampleBufferGetNumSamples($sb)];
    }

    /** The layout of the channels the converter gives: its own, else the format's, else none (mono and stereo need none). */
    private function layoutOut(CData $format): string
    {
        $at = self::at();
        $size = $at->new('unsigned int');
        $writable = $at->new('unsigned char');
        if ($at->AudioConverterGetPropertyInfo($this->converter, CoreMedia::fourcc(self::OUTPUT_LAYOUT), FFI::addr($size), FFI::addr($writable)) === 0 && $size->cdata >= 12) {
            $buf = $at->new("unsigned char[{$size->cdata}]");
            if ($at->AudioConverterGetProperty($this->converter, CoreMedia::fourcc(self::OUTPUT_LAYOUT), FFI::addr($size), FFI::addr($buf[0])) === 0) {
                return FFI::string($buf, $size->cdata);
            }
        }
        $cm = CoreMedia::c();
        $n = $cm->new('size_t');
        $l = $cm->CMAudioFormatDescriptionGetChannelLayout($format, FFI::addr($n));
        if ($l !== null && $n->cdata >= 12) {
            return FFI::string($l, $n->cdata);
        }
        if ($this->channels > 2) {
            throw new RuntimeException("myphp: audio of {$this->channels} channels whose order the system does not say");
        }
        return '';
    }

    /**
     * The ES descriptor of AudioSpecificConfig $asc, as AudioConverter takes
     * an AAC's magic cookie (FFmpeg's audiotoolboxdec ffat_get_magic_cookie):
     * ES_ID 0, a DecoderConfigDescriptor of AAC (0x40), an audio stream,
     * and $asc its DecoderSpecificInfo; each length in four bytes of seven
     * bits.
     */
    private static function esds(string $asc): string
    {
        $descr = fn(int $tag, int $size): string => chr($tag) . chr(($size >> 21) & 0x7f | 0x80) . chr(($size >> 14) & 0x7f | 0x80) . chr(($size >> 7) & 0x7f | 0x80) . chr($size & 0x7f);
        $n = strlen($asc);
        return $descr(0x03, 3 + 5 + 13 + 5 + $n) . "\0\0\0"
            . $descr(0x04, 13 + 5 + $n) . "\x40\x15" . "\0\0\0" . "\0\0\0\0" . "\0\0\0\0"
            . $descr(0x05, $n) . $asc;
    }

    private static function fourccName(int $f): string
    {
        return pack('N', $f);
    }

    private static function at(): FFI
    {
        return self::$at ??= FFI::cdef('
            typedef struct { double rate; unsigned int format, flags, bytesPerPacket, framesPerPacket, bytesPerFrame, channels, bits, reserved; } ASBD;
            typedef struct { long long start; unsigned int variableFrames, size; } ASPD;
            typedef struct { unsigned int channels, size; void *data; } AudioBuffer;
            typedef struct { unsigned int count; AudioBuffer buffers[1]; } AudioBufferList;
            typedef int (*AudioConverterComplexInputDataProc)(void *converter, unsigned int *packets, AudioBufferList *data, ASPD **descs, void *user);
            typedef struct { AudioConverterComplexInputDataProc proc; } InputHolder;
            int AudioConverterNew(const ASBD *in, const ASBD *out, void **converter);
            int AudioConverterSetProperty(void *converter, unsigned int property, unsigned int size, const void *data);
            int AudioConverterGetPropertyInfo(void *converter, unsigned int property, unsigned int *size, unsigned char *writable);
            int AudioConverterGetProperty(void *converter, unsigned int property, unsigned int *size, void *data);
            int AudioConverterFillComplexBuffer(void *converter, AudioConverterComplexInputDataProc proc, void *user, unsigned int *packets, AudioBufferList *out, ASPD *descs);
            int AudioConverterReset(void *converter);
            int AudioConverterDispose(void *converter);
        ', '/System/Library/Frameworks/AudioToolbox.framework/AudioToolbox');
    }

    public function __destruct()
    {
        self::at()->AudioConverterDispose($this->converter);
    }
}
