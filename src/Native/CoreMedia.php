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
    /** Matroska's video codecs the system decodes: their CoreMedia codec type and the atom their CodecPrivate is. */
    public const VIDEO = ['V_MPEG4/ISO/AVC' => ['avc1', 'avcC'], 'V_MPEGH/ISO/HEVC' => ['hvc1', 'hvcC']];
    /** Its audio codecs: their CoreAudio format and the frames a packet holds. */
    public const AUDIO = ['A_AAC' => ['aac ', 1024], 'A_AC3' => ['ac-3', 1536], 'A_EAC3' => ['ec-3', 1536], 'A_MPEG/L3' => ['.mp3', 1152]];

    private static ?FFI $c = null;

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
                long CFArrayGetCount(void*);
                void* CFArrayGetValueAtIndex(void*, long);
                void* CFDictionaryGetValue(void*, const void*);
                void CFDictionarySetValue(void*, const void*, const void*);
                void CFRelease(void*);
                extern void* kCMSampleAttachmentKey_NotSync;
                extern void* kCMSampleAttachmentKey_DoNotDisplay;
                extern void* kCMFormatDescriptionExtension_SampleDescriptionExtensionAtoms;
                extern void* kCFBooleanTrue;
            ', '/System/Library/Frameworks/CoreMedia.framework/CoreMedia');
        }
        return self::$c;
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
            Track::VIDEO => isset(self::VIDEO[$t->codec]),
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

    /** The format description of track $t, owned (CFRelease it). */
    public static function format(Track $t): CData
    {
        $c = self::c();
        $out = $c->new('void*');
        if ($t->type === Track::VIDEO) {
            [$type, $atom] = self::VIDEO[$t->codec] ?? throw new RuntimeException("myphp: no decoder for {$t->codec}");
            $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $t->private, strlen($t->private));
            $atoms = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', $data, ObjC::str($atom));
            $ext = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', $atoms, ObjC::import($c->kCMFormatDescriptionExtension_SampleDescriptionExtensionAtoms));
            $st = $c->CMVideoFormatDescriptionCreate(null, self::fourcc($type), $t->width, $t->height, self::ptr($ext), FFI::addr($out));
        } else {
            [$format, $frames] = self::audio($t) ?? throw new RuntimeException("myphp: no decoder for {$t->codec}");
            $asbd = $c->new('ASBD');
            $asbd->rate = $t->sampleRate; $asbd->channels = $t->channels;
            $asbd->format = self::fourcc($format); $asbd->framesPerPacket = $frames;
            $cookie = $format === 'aac ' ? ($t->private !== '' ? $t->private : self::audioSpecificConfig($t)) : '';
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

    /** The sample buffer of audio packet $p in $format, one packet of its frames, starting at sample $at of $rate a second, owned. */
    public static function audioSample(Packet $p, CData $format, int $at, int $rate): CData
    {
        $c = self::c();
        $bb = self::block($p->data);
        $desc = $c->new('ASPD'); $desc->size = strlen($p->data);
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
