<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\{Packet, Track};
use RuntimeException;

/**
 * CoreMedia's C side, for playing media (Media\Player): the format of a
 * track as its decoder knows it, and a packet as the sample buffer the
 * renderers take. Its pointers are of its own FFI instance: one of
 * ObjC's comes in through ptr(), and goes back through ObjC::import.
 *
 * @internal
 */
final class CoreMedia
{
    /**
     * Matroska's video codecs the system decodes: their CoreMedia codec type
     * and the atom their format takes (AV1's CodecPrivate is its av1C;
     * VP9's vpcC is made from its first keyframe). VP9 and AV1 play where
     * the Mac decodes them in hardware (VP9 on Apple silicon, AV1 from M3).
     */
    public const VIDEO = ['V_MPEG4/ISO/AVC' => ['avc1', 'avcC'], 'V_MPEGH/ISO/HEVC' => ['hvc1', 'hvcC'], 'V_VP9' => ['vp09', 'vpcC'], 'V_AV1' => ['av01', 'av1C']];
    /** Those the system decodes only with the decoders it adds (VTRegisterSupplementalVideoDecoderIfAvailable), in hardware. */
    private const SUPPLEMENTAL = ['vp09', 'av01'];
    /** Its audio codecs: their CoreAudio format and the frames a packet holds, 0 for as many as each packet says (Opus's, by its TOC byte). */
    public const AUDIO = ['A_AAC' => ['aac ', 1024], 'A_AC3' => ['ac-3', 1536], 'A_EAC3' => ['ec-3', 1536], 'A_MPEG/L3' => ['.mp3', 1152], 'A_OPUS' => ['opus', 0], 'A_FLAC' => ['flac', 0]];

    private static ?FFI $c = null, $vt = null;

    public static function c(): FFI
    {
        if (self::$c === null) {
            ObjC::boot(['Foundation', 'AVFoundation', 'CoreMedia']);
            self::$c = FFI::cdef('
                typedef struct { long long value; int timescale; unsigned int flags; long long epoch; } CMTime;
                typedef struct { CMTime duration, pts, dts; } CMSampleTimingInfo;
                typedef struct { double rate; unsigned int format, flags, bytesPerPacket, framesPerPacket, bytesPerFrame, channels, bits, reserved; } ASBD;
                typedef struct { long long start; unsigned int variableFrames, size; } ASPD;
                int  CMVideoFormatDescriptionCreate(void*, unsigned int, int, int, void*, void**);
                int  CMAudioFormatDescriptionCreate(void*, const ASBD*, size_t, const void*, size_t, const void*, void*, void**);
                unsigned int CMFormatDescriptionGetMediaSubType(void*);
                const ASBD* CMAudioFormatDescriptionGetStreamBasicDescription(void*);
                const void* CMAudioFormatDescriptionGetMagicCookie(void*, size_t*);
                const void* CMAudioFormatDescriptionGetChannelLayout(void*, size_t*);
                int  CMSampleBufferGetAudioStreamPacketDescriptionsPtr(void*, const void**, size_t*);
                int  CMBlockBufferCreateWithMemoryBlock(void*, void*, size_t, void*, void*, size_t, size_t, unsigned int, void**);
                int  CMBlockBufferReplaceDataBytes(const void*, void*, size_t, size_t);
                int  CMSampleBufferCreateReady(void*, void*, void*, long, long, const CMSampleTimingInfo*, long, const size_t*, void**);
                int  CMAudioSampleBufferCreateReadyWithPacketDescriptions(void*, void*, void*, long, CMTime, const ASPD*, void**);
                void* CMSampleBufferGetSampleAttachmentsArray(void*, bool);
                long CMSampleBufferGetNumSamples(void*);
                CMTime CMSampleBufferGetPresentationTimeStamp(void*);
                CMTime CMSampleBufferGetDecodeTimeStamp(void*);
                CMTime CMSampleBufferGetDuration(void*);
                void* CMSampleBufferGetFormatDescription(void*);
                void* CMSampleBufferGetDataBuffer(void*);
                size_t CMBlockBufferGetDataLength(void*);
                int  CMBlockBufferCopyDataBytes(void*, size_t, size_t, void*);
                long CFArrayGetCount(void*);
                void* CFArrayGetValueAtIndex(void*, long);
                void* CFDictionaryGetValue(void*, const void*);
                void CFDictionarySetValue(void*, const void*, const void*);
                void CFRelease(void*);
                extern void* kCMSampleAttachmentKey_NotSync;
                extern void* kCMSampleAttachmentKey_DoNotDisplay;
                extern void* kCMFormatDescriptionExtension_SampleDescriptionExtensionAtoms;
                extern void* kCMFormatDescriptionExtension_ColorPrimaries;
                extern void* kCMFormatDescriptionExtension_TransferFunction;
                extern void* kCMFormatDescriptionExtension_YCbCrMatrix;
                extern void* kCMFormatDescriptionExtension_FullRangeVideo;
                extern void* kCMFormatDescriptionColorPrimaries_ITU_R_709_2;
                extern void* kCMFormatDescriptionColorPrimaries_ITU_R_2020;
                extern void* kCMFormatDescriptionColorPrimaries_SMPTE_C;
                extern void* kCMFormatDescriptionTransferFunction_ITU_R_709_2;
                extern void* kCMFormatDescriptionTransferFunction_SMPTE_ST_2084_PQ;
                extern void* kCMFormatDescriptionTransferFunction_ITU_R_2100_HLG;
                extern void* kCMFormatDescriptionYCbCrMatrix_ITU_R_709_2;
                extern void* kCMFormatDescriptionYCbCrMatrix_ITU_R_601_4;
                extern void* kCMFormatDescriptionYCbCrMatrix_ITU_R_2020;
                extern void* kCFBooleanTrue;
                extern void* kCFBooleanFalse;
            ', '/System/Library/Frameworks/CoreMedia.framework/CoreMedia');
            self::vt(); // its VP9 and AV1 decoders, before a reader or a layer meets them
        }
        return self::$c;
    }

    /** VideoToolbox, its VP9 and AV1 decoders registered in the process (macOS 11), which the display layer and AVFoundation's readers then use. */
    private static function vt(): FFI
    {
        if (self::$vt === null) {
            self::$vt = FFI::cdef('
                unsigned char VTIsHardwareDecodeSupported(unsigned int);
                void VTRegisterSupplementalVideoDecoderIfAvailable(unsigned int);
            ', '/System/Library/Frameworks/VideoToolbox.framework/VideoToolbox');
            foreach (self::SUPPLEMENTAL as $type) {
                self::$vt->VTRegisterSupplementalVideoDecoderIfAvailable(self::fourcc($type));
            }
        }
        return self::$vt;
    }

    /** A pointer of ObjC's (or another instance's) as one of this instance's. */
    public static function ptr($p): CData
    {
        return self::c()->cast('void*', ObjC::addr($p));
    }

    /** A FourCC as the integer CoreMedia takes. */
    public static function fourcc(string $s): int
    {
        return unpack('N', $s)[1];
    }

    /** Whether the system decodes track $t: its codec is one of those above (a system reader names its tracks' codecs as Matroska does). */
    public static function playable(Track $t): bool
    {
        return match ($t->type) {
            Track::VIDEO => isset(self::VIDEO[$t->codec]) && (!in_array($type = self::VIDEO[$t->codec][0], self::SUPPLEMENTAL, true) || self::vt()->VTIsHardwareDecodeSupported(self::fourcc($type)) !== 0),
            Track::AUDIO => self::audio($t) !== null,
            default => false,
        };
    }

    /** The CoreAudio format and frames a packet of audio track $t: by its codec id, an MPEG-2 MP3 holding half as many. @return array{0: string, 1: int}|null */
    private static function audio(Track $t): ?array
    {
        foreach (self::AUDIO as $id => $f) {
            if ($t->codec === $id || $id === 'A_AAC' && str_starts_with($t->codec, 'A_AAC/')) { // A_AAC/MPEG4/LC and the like, of old muxers
                return $id === 'A_MPEG/L3' && $t->sampleRate < 32000 ? [$f[0], 576] : $f;
            }
        }
        return null;
    }

    /** The format description of track $t, owned (CFRelease it); $first is its first packet's bytes, a keyframe, which VP9's is made from. */
    public static function format(Track $t, string $first = ''): CData
    {
        $c = self::c();
        $out = $c->new('void*');
        if ($t->type === Track::VIDEO) {
            [$type, $atom] = self::VIDEO[$t->codec] ?? throw new RuntimeException("myphp: no decoder for {$t->codec}");
            $record = $type === 'vp09' ? self::vpcC($t, $first) : $t->private;
            if ($record === '') {
                throw new RuntimeException("myphp: track {$t->number} ({$t->codec}) has no $atom");
            }
            $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $record, strlen($record));
            $atoms = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', $data, ObjC::str($atom));
            $ext = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
            ObjC::msg('v:pp', $ext, 'setObject:forKey:', $atoms, ObjC::import($c->kCMFormatDescriptionExtension_SampleDescriptionExtensionAtoms));
            // The colors the container says, as AVFoundation's format of an MP4's colr says them: without them the layer takes
            // a Matroska AV1's PQ and BT.2020 for BT.709's (VideoToolbox's AV1 reads none of the sequence header's).
            $said = $t->colors;
            [$pri, $trc, $mtrx] = [$said['primaries'] ?? 2, $said['transfer'] ?? 2, $said['matrix'] ?? 2];
            [$m, $p, $tr] = self::colorNames($mtrx, $pri, $trc, $t->width, $t->height);
            foreach ([['ColorPrimaries', $pri, $p], ['TransferFunction', $trc, $tr], ['YCbCrMatrix', $mtrx, $m]] as [$key, $code, $name]) {
                if ($code === 2) { continue; } // none said: the decoder's own
                ObjC::msg('v:pp', $ext, 'setObject:forKey:', ObjC::import($c->{"kCMFormatDescription{$key}_$name"}), ObjC::import($c->{"kCMFormatDescriptionExtension_$key"}));
            }
            if (isset($said['full'])) {
                ObjC::msg('v:pp', $ext, 'setObject:forKey:', ObjC::import($said['full'] ? $c->kCFBooleanTrue : $c->kCFBooleanFalse), ObjC::import($c->kCMFormatDescriptionExtension_FullRangeVideo));
            }
            $st = $c->CMVideoFormatDescriptionCreate(null, self::fourcc($type), $t->width, $t->height, self::ptr($ext), FFI::addr($out));
        } else {
            [$format, $frames] = self::audio($t) ?? throw new RuntimeException("myphp: no decoder for {$t->codec}");
            $asbd = $c->new('ASBD');
            $asbd->rate = $t->sampleRate; $asbd->channels = $t->channels;
            $asbd->format = self::fourcc($format); $asbd->framesPerPacket = $format === 'flac' ? self::flacBlock($t) : $frames;
            $cookie = match ($format) {
                'aac ' => $t->private !== '' ? $t->private : self::audioSpecificConfig($t),
                'opus' => $t->private, // its OpusHead: the channels, their mapping, the pre-skip
                'flac' => self::dfLa($t),
                default => '',
            };
            $buf = null;
            if ($cookie !== '') {
                $buf = $c->new('unsigned char[' . strlen($cookie) . ']');
                FFI::memcpy($buf, $cookie, strlen($cookie));
            }
            $st = $c->CMAudioFormatDescriptionCreate(null, FFI::addr($asbd), 0, null, strlen($cookie), $buf === null ? null : FFI::addr($buf[0]), null, FFI::addr($out));
        }
        if ($st !== 0) {
            throw new RuntimeException("myphp: the format of {$t->codec} (track {$t->number}): CoreMedia error $st");
        }
        return $out;
    }

    /**
     * @internal SoftVideo: H.273's matrix, primaries and transfer as the
     * names of CoreVideo's and CoreMedia's constants (their suffixes:
     * ITU_R_709_2…), [matrix, primaries, transfer]; where a code is 2 (none
     * said), mpv's guess by the size: BT.709 from 1280 wide or past 576
     * lines, else BT.601 (SMPTE C's primaries); a transfer of none, BT.709's.
     * @return array{0: string, 1: string, 2: string}
     */
    public static function colorNames(int $mtrx, int $pri, int $trc, int $w, int $h): array
    {
        $hd = $w >= 1280 || $h > 576;
        if ($mtrx === 2) { $mtrx = $hd ? 1 : 6; }
        if ($pri === 2) { $pri = $hd ? 1 : 6; }
        return [
            match ($mtrx) { 5, 6 => 'ITU_R_601_4', 9, 10 => 'ITU_R_2020', default => 'ITU_R_709_2' },
            match ($pri) { 9 => 'ITU_R_2020', 5, 6 => 'SMPTE_C', default => 'ITU_R_709_2' },
            match ($trc) { 16 => 'SMPTE_ST_2084_PQ', 18 => 'ITU_R_2100_HLG', default => 'ITU_R_709_2' },
        ];
    }

    /** The STREAMINFO of FLAC track $t: Matroska's CodecPrivate is "fLaC" and the stream's metadata blocks, STREAMINFO the first (34 bytes after its 4 of header). */
    private static function streamInfo(Track $t): string
    {
        if (!str_starts_with($t->private, 'fLaC') || strlen($t->private) < 42 || (ord($t->private[4]) & 0x7f) !== 0) {
            throw new RuntimeException("myphp: track {$t->number} (A_FLAC) has no STREAMINFO");
        }
        return substr($t->private, 8, 34);
    }

    /**
     * The magic cookie of FLAC track $t, as AVFoundation makes it of an
     * MP4's: a whole dfLa box (its size, "dfLa", version and flags 0), with
     * the STREAMINFO block alone, marked the last.
     */
    private static function dfLa(Track $t): string
    {
        return pack('N', 50) . 'dfLa' . "\0\0\0\0" . "\x80\x00\x00\x22" . self::streamInfo($t);
    }

    /** The largest block of FLAC track $t, in frames (STREAMINFO's maximum block size): the frames a packet, as AVFoundation's format says them. */
    private static function flacBlock(Track $t): int
    {
        return unpack('n', self::streamInfo($t), 2)[1];
    }

    /**
     * The frames of FLAC frame $b, from its header (FLAC format, "FRAME
     * HEADER"): the block size's code, which for 6 and 7 is in the byte or
     * two after the frame's number, UTF-8 coded.
     */
    private static function flacFrames(string $b): int
    {
        if (strlen($b) < 5 || ord($b[0]) !== 0xff || (ord($b[1]) & 0xfe) !== 0xf8) {
            throw new RuntimeException('myphp: a FLAC packet of no frame header');
        }
        $code = ord($b[2]) >> 4;
        if ($code === 1) { return 192; }
        if ($code >= 2 && $code <= 5) { return 576 << ($code - 2); }
        if ($code >= 8) { return 256 << ($code - 8); }
        if ($code === 6 || $code === 7) {
            $lead = ord($b[4]);
            $n = 1; // the bytes of the frame's number: as many as its first byte's leading ones, else one
            for ($bit = 0x80; $lead & $bit && $bit > 1; $bit >>= 1) { $n++; }
            $n = $n === 1 ? 1 : $n - 1;
            $at = 4 + $n;
            return $code === 6 ? ord($b[$at] ?? "\0") + 1 : unpack('n', $b, $at)[1] + 1;
        }
        throw new RuntimeException("myphp: a FLAC frame of block size code $code");
    }

    /** The AudioSpecificConfig of AAC-LC at the track's rate and channels, for old Matroska files that carry none (A_AAC/MPEG2/LC). */
    private static function audioSpecificConfig(Track $t): string
    {
        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $i = array_search((int)$t->sampleRate, $rates, true);
        if ($i === false) {
            throw new RuntimeException("myphp: AAC at {$t->sampleRate} Hz");
        }
        return pack('n', (2 << 11) | ($i << 7) | ($t->channels << 3)); // object type 2 (LC), frequency index, channel configuration
    }

    /** A CMTime of $seconds, at $scale units a second. */
    public static function time(float $seconds, int $scale = 90000): CData
    {
        $t = self::c()->new('CMTime');
        $t->value = (int)round($seconds * $scale); $t->timescale = $scale; $t->flags = 1;
        return $t;
    }

    /** The seconds of a CMTime, of any instance; null for an invalid one. */
    public static function seconds(CData $t): ?float
    {
        return ($t->flags & 1) !== 0 && $t->timescale > 0 ? $t->value / $t->timescale : null;
    }

    /**
     * The sample buffer of video packet $p in format $format, owned: shown
     * unless $show is false (decoded, for the frames after it, and not
     * shown: those before where a seek goes).
     */
    public static function videoSample(Packet $p, CData $format, bool $show): CData
    {
        $c = self::c();
        $bb = self::block($p->data);
        $timing = $c->new('CMSampleTimingInfo');
        $timing->duration = self::time($p->duration); $timing->pts = self::time($p->time);
        $size = $c->new('size_t'); $size->cdata = strlen($p->data);
        $sb = $c->new('void*');
        $st = $c->CMSampleBufferCreateReady(null, $bb, $format, 1, 1, FFI::addr($timing), 1, FFI::addr($size), FFI::addr($sb));
        $c->CFRelease($bb);
        if ($st !== 0) {
            throw new RuntimeException("myphp: a video sample buffer: CoreMedia error $st");
        }
        self::mark($sb, !$p->keyframe, !$show);
        return $sb;
    }

    /**
     * VP9's codec configuration (the vpcC box's content, version 1, of the
     * VP9 ISO-BMFF binding) of track $t, from the uncompressed header of its
     * keyframe $frame: its profile, bit depth, chroma subsampling, range and
     * color space; the level the least whose picture size holds the frame.
     */
    private static function vpcC(Track $t, string $frame): string
    {
        $bit = 0;
        $read = function (int $n) use ($frame, &$bit): int {
            $v = 0;
            for ($i = 0; $i < $n; $i++, $bit++) {
                $byte = ord($frame[$bit >> 3] ?? throw new RuntimeException('myphp: a VP9 frame ends in its header'));
                $v = ($v << 1) | (($byte >> (7 - ($bit & 7))) & 1);
            }
            return $v;
        };
        if ($read(2) !== 2) {
            throw new RuntimeException("myphp: track {$t->number} starts with no VP9 frame");
        }
        $profile = $read(1); $profile |= $read(1) << 1;
        if ($profile === 3) { $read(1); }
        if ($read(1) === 1 || $read(1) !== 0) { // show_existing_frame, frame_type: a keyframe is 0
            throw new RuntimeException("myphp: track {$t->number}'s first VP9 frame is no keyframe");
        }
        $read(2); // show_frame, error_resilient_mode
        if ($read(24) !== 0x498342) {
            throw new RuntimeException("myphp: track {$t->number}'s VP9 keyframe has no sync code");
        }
        $depth = $profile >= 2 ? ($read(1) === 1 ? 12 : 10) : 8;
        $space = $read(3);
        if ($space !== 7) { // not RGB
            $full = $read(1);
            [$sx, $sy] = $profile === 1 || $profile === 3 ? [$read(1), $read(1)] : [1, 1];
        } else {
            $full = 1;
            [$sx, $sy] = [0, 0];
        }
        $chroma = match (true) { $sx === 1 && $sy === 1 => 1, $sx === 1 => 2, default => 3 }; // 4:2:0 at the luma's first sample, 4:2:2, 4:4:4
        // The color space's matrix (ISO/IEC 23091-2): BT.601 and SMPTE 170 are 6, BT.709 1, SMPTE 240 7, BT.2020 9, RGB 0; unknown 2.
        $matrix = [0 => 2, 1 => 6, 2 => 1, 3 => 6, 4 => 7, 5 => 9, 6 => 2, 7 => 0][$space];
        [$primaries, $transfer] = match ($space) { 2 => [1, 1], 5 => [9, 14], 1, 3 => [6, 6], 4 => [7, 7], default => [2, 2] };
        // The container's where it says them: a VP9 frame has no transfer, an HDR film's PQ or HLG (YouTube's VP9.2) only there.
        $said = fn(string $k, int $own): int => ($t->colors[$k] ?? 2) !== 2 ? $t->colors[$k] : $own;
        [$primaries, $transfer, $matrix] = [$said('primaries', $primaries), $said('transfer', $transfer), $said('matrix', $matrix)];
        $area = $t->width * $t->height;
        $level = 62;
        foreach ([10 => 36864, 11 => 73728, 20 => 122880, 21 => 245760, 30 => 552960, 31 => 983040, 40 => 2228224, 50 => 8912896, 60 => 35651584] as $l => $max) {
            if ($area <= $max) { $level = $l; break; }
        }
        return pack('CC3CCCCCCn', 1, 0, 0, 0, $profile, $level, $depth << 4 | $chroma << 1 | $full, $primaries, $transfer, $matrix, 0);
    }

    /**
     * The frames audio packet $p holds: its codec's, for FLAC as its frame
     * header says, or for Opus as its TOC
     * byte says (RFC 6716 3.1: the frame size of its configuration, 2.5 to
     * 60 ms at 48 kHz, times the frames in it).
     */
    public static function frames(Packet $p): int
    {
        $f = self::audio($p->track) ?? throw new RuntimeException("myphp: no decoder for {$p->track->codec}");
        if ($f[1] > 0) {
            return $f[1];
        }
        if ($f[0] === 'flac') {
            return self::flacFrames($p->data);
        }
        $toc = ord($p->data[0] ?? "\0");
        $config = $toc >> 3;
        $size = match (true) { $config < 12 => [480, 960, 1920, 2880][$config % 4], $config < 16 => [480, 960][$config % 2], default => [120, 240, 480, 960][$config % 4] }; // SILK, hybrid, CELT
        $count = match ($toc & 3) { 0 => 1, 1, 2 => 2, 3 => ord($p->data[1] ?? "\0") & 0x3f };
        return $size * $count;
    }

    /**
     * The format description of 32-bit float PCM, interleaved, of
     * $channels at $rate, in the order $layout says (an AudioChannelLayout's
     * bytes; '' for the system's default, of mono and stereo), owned: what
     * a decoder of the player's own gives.
     */
    public static function pcmFormat(int $rate, int $channels, string $layout): CData
    {
        $c = self::c();
        $asbd = $c->new('ASBD');
        $asbd->rate = $rate; $asbd->format = self::fourcc('lpcm'); $asbd->flags = 9; // kAudioFormatFlagIsFloat | IsPacked
        $asbd->bytesPerPacket = $asbd->bytesPerFrame = 4 * $channels; $asbd->framesPerPacket = 1; $asbd->channels = $channels; $asbd->bits = 32;
        $buf = null;
        if ($layout !== '') {
            $buf = $c->new('unsigned char[' . strlen($layout) . ']');
            FFI::memcpy($buf, $layout, strlen($layout));
        }
        $out = $c->new('void*');
        $st = $c->CMAudioFormatDescriptionCreate(null, FFI::addr($asbd), strlen($layout), $buf === null ? null : FFI::addr($buf[0]), 0, null, null, FFI::addr($out));
        if ($st !== 0) {
            throw new RuntimeException("myphp: the format of PCM of $channels channels: CoreMedia error $st");
        }
        return $out;
    }

    /** An AudioChannelLayout of channel bits $bits (kAudioChannelLayoutTag_UseChannelBitmap, no descriptions): the channels in WAVE's order. */
    public static function bitmapLayout(int $bits): string
    {
        return pack('V3', 1 << 16, $bits, 0);
    }

    /** The sample buffer of $frames frames of PCM $pcm in $format, starting at sample $at of $rate a second, owned. */
    public static function pcmSample(string $pcm, CData $format, int $at, int $rate, int $frames): CData
    {
        $c = self::c();
        $bb = self::block($pcm);
        $pts = $c->new('CMTime'); $pts->value = $at; $pts->timescale = $rate; $pts->flags = 1;
        $sb = $c->new('void*');
        $st = $c->CMAudioSampleBufferCreateReadyWithPacketDescriptions(null, $bb, $format, $frames, $pts, null, FFI::addr($sb));
        $c->CFRelease($bb);
        if ($st !== 0) {
            throw new RuntimeException("myphp: a PCM sample buffer: CoreMedia error $st");
        }
        return $sb;
    }

    /** The sample buffer of audio packet $p in $format, one packet of $frames frames, starting at sample $at of $rate a second, owned. */
    public static function audioSample(Packet $p, CData $format, int $at, int $rate, int $frames): CData
    {
        $c = self::c();
        $bb = self::block($p->data);
        $desc = $c->new('ASPD'); $desc->size = strlen($p->data);
        if ($c->CMAudioFormatDescriptionGetStreamBasicDescription($format)->framesPerPacket === 0) {
            $desc->variableFrames = $frames; // a format of packets of their own lengths (Opus)
        }
        $pts = $c->new('CMTime'); $pts->value = $at; $pts->timescale = $rate; $pts->flags = 1;
        $sb = $c->new('void*');
        $st = $c->CMAudioSampleBufferCreateReadyWithPacketDescriptions(null, $bb, $format, 1, $pts, FFI::addr($desc), FFI::addr($sb));
        $c->CFRelease($bb);
        if ($st !== 0) {
            throw new RuntimeException("myphp: an audio sample buffer: CoreMedia error $st");
        }
        return $sb;
    }

    /** Marks the sample of $sb as depending on others (no keyframe) and not to show. */
    public static function mark(CData $sb, bool $notSync, bool $hidden): void
    {
        if (!$notSync && !$hidden) {
            return;
        }
        $c = self::c();
        $att = $c->CFArrayGetValueAtIndex($c->CMSampleBufferGetSampleAttachmentsArray($sb, true), 0);
        if ($notSync) { $c->CFDictionarySetValue($att, $c->kCMSampleAttachmentKey_NotSync, $c->kCFBooleanTrue); }
        if ($hidden) { $c->CFDictionarySetValue($att, $c->kCMSampleAttachmentKey_DoNotDisplay, $c->kCFBooleanTrue); }
    }

    /** Whether the sample of $sb is a keyframe: no NotSync attachment says it is not. */
    public static function isSync(CData $sb): bool
    {
        $c = self::c();
        $arr = $c->CMSampleBufferGetSampleAttachmentsArray($sb, false);
        if ($arr === null || $c->CFArrayGetCount($arr) === 0) {
            return true;
        }
        $v = $c->CFDictionaryGetValue($c->CFArrayGetValueAtIndex($arr, 0), $c->kCMSampleAttachmentKey_NotSync);
        return $v === null || ObjC::addr($v) !== ObjC::addr($c->kCFBooleanTrue);
    }

    /** The bytes of sample buffer $sb, as stored. */
    public static function bytes(CData $sb): string
    {
        $c = self::c();
        $bb = $c->CMSampleBufferGetDataBuffer($sb);
        $n = $bb === null ? 0 : $c->CMBlockBufferGetDataLength($bb);
        if ($n === 0) {
            return '';
        }
        $buf = $c->new("char[$n]");
        $c->CMBlockBufferCopyDataBytes($bb, 0, $n, $buf);
        return FFI::string($buf, $n);
    }

    /** A block buffer holding a copy of $data, owned. */
    private static function block(string $data): CData
    {
        $c = self::c();
        $bb = $c->new('void*');
        $n = strlen($data);
        if ($c->CMBlockBufferCreateWithMemoryBlock(null, null, $n, null, null, 0, $n, 1, FFI::addr($bb)) !== 0) { // kCMBlockBufferAssureMemoryNowFlag
            throw new RuntimeException('myphp: CMBlockBufferCreateWithMemoryBlock');
        }
        $c->CMBlockBufferReplaceDataBytes($data, $bb, 0, $n);
        return $bb;
    }
}
