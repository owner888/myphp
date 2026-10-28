<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\{Packet, Track};
use RuntimeException;

/**
 * Decodes keyframes of a video track into small pictures, away from what
 * plays: a VideoToolbox decompression session whose output is of the
 * picture's size in BGRA (VideoToolbox scales as it decodes: no CoreImage,
 * 2 ms less a thumbnail of 1080p), each frame decoded synchronously, and
 * turned RGBA by vImage.
 * VideoToolbox calls its output callback on a thread of its own, while
 * the main thread waits in the decode for it: PHP runs on one thread at
 * a time still, and the callback only keeps the frame (retained), which
 * is scaled once the decode returns (tests/player_test.php's thumbnails,
 * docs/architecture.md).
 *
 * @internal Media\Player
 */
final class VideoDecoder
{
    private static ?FFI $vt = null;
    /** The session and the format it decodes, owned; null before the first frame. */
    private ?CData $session = null, $format = null;
    /** The size of the pictures. */
    public readonly int $width, $height;
    /** The callback's record and its holder, kept for the session. */
    private CData $record, $holder;
    /** What the callback gave: its status and the frame, retained, until taken (an object of its own: the callback holds no cycle to this). */
    private \stdClass $out;

    /** A decoder of $track's keyframes into pictures $width wide, as tall as the track's shape has it. */
    public function __construct(private readonly Track $track, int $width)
    {
        $this->width = max(1, $width);
        $this->height = max(1, (int)round($this->width * $track->height / max(1, $track->width)));
        $vt = self::vt();
        $out = $this->out = (object)['status' => 0, 'image' => null];
        $this->holder = $vt->new('struct { void (*fn)(void*, void*, int, unsigned int, void*); }');
        // On VideoToolbox's thread, the main one waiting: the frame kept, nothing more.
        $this->holder->fn = static function ($ref, $frameRef, int $status, int $flags, $image) use ($vt, $out): void {
            $out->status = $status;
            if ($status === 0 && $image !== null) { $out->image = $vt->CVPixelBufferRetain($image); }
        };
        $this->record = $vt->new('Rec');
        $this->record->cb = $vt->cast('void*', $this->holder->fn);
    }

    private static function vt(): FFI
    {
        return self::$vt ??= FFI::cdef('
            typedef struct { void* cb; void* refcon; } Rec;
            int VTDecompressionSessionCreate(void*, void*, void*, void*, const Rec*, void**);
            int VTDecompressionSessionDecodeFrame(void*, void*, unsigned int, void*, unsigned int*);
            void VTDecompressionSessionInvalidate(void*);
            void* CVPixelBufferRetain(void*);
            void CVPixelBufferRelease(void*);
            int CVPixelBufferLockBaseAddress(void*, unsigned long);
            int CVPixelBufferUnlockBaseAddress(void*, unsigned long);
            void* CVPixelBufferGetBaseAddress(void*);
            size_t CVPixelBufferGetBytesPerRow(void*);
            size_t CVPixelBufferGetWidth(void*);
            size_t CVPixelBufferGetHeight(void*);
            void CFRelease(void*);
            extern void* kCVPixelBufferWidthKey;
            extern void* kCVPixelBufferHeightKey;
            extern void* kCVPixelBufferPixelFormatTypeKey;
        ', '/System/Library/Frameworks/VideoToolbox.framework/VideoToolbox');
    }

    private static ?FFI $vimage = null;

    private static function vimage(): FFI
    {
        return self::$vimage ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            long vImagePermuteChannels_ARGB8888(const vImage_Buffer*, const vImage_Buffer*, const unsigned char*, unsigned int);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    /** The session's output: BGRA of the pictures' size, which VideoToolbox scales to. */
    private function attributes()
    {
        $vt = self::vt();
        $num = fn(int $v) => ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v);
        $a = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($this->width), ObjC::import($vt->kCVPixelBufferWidthKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($this->height), ObjC::import($vt->kCVPixelBufferHeightKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num(CoreMedia::fourcc('BGRA')), ObjC::import($vt->kCVPixelBufferPixelFormatTypeKey));
        return $a;
    }

    /** Keyframe $p decoded, as a picture of the decoder's size: RGBA (opaque), top row first. */
    public function frame(Packet $p): string
    {
        $vt = self::vt();
        $cm = CoreMedia::c();
        if ($p->sample !== null) {
            $sb = $p->sample->buffer;
            $format = $cm->CMSampleBufferGetFormatDescription($sb);
            $owned = null;
        } else {
            $this->format ??= CoreMedia::format($this->track, $p->data);
            $format = $this->format;
            $sb = $owned = CoreMedia::videoSample($p, $format, true);
        }
        if ($this->session === null) {
            $session = $vt->new('void*');
            $st = $vt->VTDecompressionSessionCreate(null, $vt->cast('void*', ObjC::addr($format)), null, $vt->cast('void*', ObjC::addr($this->attributes())), FFI::addr($this->record), FFI::addr($session));
            if ($st !== 0) {
                throw new RuntimeException("myphp: a decoder of {$this->track->codec}: VideoToolbox error $st");
            }
            $this->session = $session;
        }
        $info = $vt->new('unsigned int');
        $st = $vt->VTDecompressionSessionDecodeFrame($this->session, $vt->cast('void*', ObjC::addr($sb)), 0, null, FFI::addr($info)); // synchronous: the callback has run
        if ($owned !== null) { $cm->CFRelease($owned); }
        $image = $this->out->image;
        $this->out->image = null;
        if ($st !== 0 || $this->out->status !== 0 || $image === null) {
            throw new RuntimeException(sprintf('myphp: decoding the frame at %.3f s: VideoToolbox error %d', $p->time, $st !== 0 ? $st : $this->out->status));
        }
        try {
            if ($vt->CVPixelBufferGetWidth($image) !== $this->width || $vt->CVPixelBufferGetHeight($image) !== $this->height) {
                throw new RuntimeException(sprintf('myphp: VideoToolbox decoded %dx%d, not the %dx%d asked', $vt->CVPixelBufferGetWidth($image), $vt->CVPixelBufferGetHeight($image), $this->width, $this->height));
            }
            return $this->rgba($image);
        } finally {
            $vt->CVPixelBufferRelease($image);
        }
    }

    /** The BGRA pixels of $image, its rows padded as it has them, as RGBA rows of the width. */
    private function rgba(CData $image): string
    {
        $vt = self::vt(); $vi = self::vimage();
        $n = $this->width * $this->height * 4;
        $dst = Mem::alloc($n);
        $vt->CVPixelBufferLockBaseAddress($image, 1); // read only
        try {
            $from = $vi->new('vImage_Buffer'); $to = $vi->new('vImage_Buffer');
            $from->data = $vi->cast('void*', Mem::addr($vt->CVPixelBufferGetBaseAddress($image)));
            $from->height = $to->height = $this->height; $from->width = $to->width = $this->width;
            $from->rowBytes = $vt->CVPixelBufferGetBytesPerRow($image);
            $to->data = $vi->cast('void*', Mem::addr($dst)); $to->rowBytes = $this->width * 4;
            $map = $vi->new('unsigned char[4]');
            [$map[0], $map[1], $map[2], $map[3]] = [2, 1, 0, 3]; // B G R A to R G B A
            $st = $vi->vImagePermuteChannels_ARGB8888(FFI::addr($from), FFI::addr($to), $map, 0);
            if ($st !== 0) {
                throw new RuntimeException("myphp: vImage error $st");
            }
            return FFI::string($dst, $n);
        } finally {
            $vt->CVPixelBufferUnlockBaseAddress($image, 1);
            Mem::free($dst);
        }
    }

    public function __destruct()
    {
        if ($this->session !== null) {
            self::vt()->VTDecompressionSessionInvalidate($this->session);
            self::vt()->CFRelease($this->session);
        }
        if ($this->format !== null) { CoreMedia::c()->CFRelease($this->format); }
    }
}
