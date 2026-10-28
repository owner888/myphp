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

    /** libdav1d's, for a track decoded in software (SoftVideo::wanted): a keyframe in, its picture out. */
    private ?Dav1d $dav1d = null;

    /** A decoder of $track's keyframes into pictures $width wide, as tall as the track's shape has it; in software as the player decodes it ($hardware off: Renderers'). */
    public function __construct(private readonly Track $track, int $width, bool $hardware = true)
    {
        $this->width = max(1, $width);
        $this->height = max(1, (int)round($this->width * $track->height / max(1, $track->width)));
        if (SoftVideo::wanted($track, $hardware)) {
            $this->dav1d = new Dav1d(1, 1);
            return;
        }
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

    private static ?FFI $yuv = null;

    /** vImage's YCbCr to ARGB and its scaling, for libdav1d's pictures. */
    private static function yuv(): FFI
    {
        return self::$yuv ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            typedef struct { int Yp_bias, CbCr_bias, YpRangeMax, CbCrRangeMax, YpMax, YpMin, CbCrMax, CbCrMin; } vImage_YpCbCrPixelRange;
            typedef struct { char opaque[128]; } vImage_YpCbCrToARGB;
            typedef struct { float Yp, Cr_R, Cr_G, Cb_G, Cb_B; } vImage_YpCbCrToARGBMatrix;
            extern const vImage_YpCbCrToARGBMatrix* kvImage_YpCbCrToARGBMatrix_ITU_R_709_2;
            extern const vImage_YpCbCrToARGBMatrix* kvImage_YpCbCrToARGBMatrix_ITU_R_601_4;
            long vImageConvert_YpCbCrToARGB_GenerateConversion(const vImage_YpCbCrToARGBMatrix*, const vImage_YpCbCrPixelRange*, vImage_YpCbCrToARGB*, int, int, unsigned int);
            long vImageConvert_420Yp8_Cb8_Cr8ToARGB8888(const vImage_Buffer*, const vImage_Buffer*, const vImage_Buffer*, const vImage_Buffer*, const vImage_YpCbCrToARGB*, const unsigned char*, unsigned char, unsigned int);
            long vImageScale_ARGB8888(const vImage_Buffer*, const vImage_Buffer*, void*, unsigned int);
            long vImagePermuteChannels_ARGB8888(const vImage_Buffer*, const vImage_Buffer*, const unsigned char*, unsigned int);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    /** Keyframe $p decoded by libdav1d, scaled to the decoder's size: RGBA, top row first, of the matrix and range its sequence header says (mpv's guess by the size where it says none, as SoftVideo's). */
    private function softFrame(Packet $p): string
    {
        $this->dav1d->flush();
        $this->dav1d->send($p->data, 0);
        $pic = $this->dav1d->picture() ?? $this->dav1d->picture(); // the second drains: the one frame
        if ($pic === null) {
            throw new RuntimeException(sprintf('myphp: libdav1d decoded no picture of the frame at %.3f s', $p->time));
        }
        $y = self::yuv();
        $w = $pic->p->w; $h = $pic->p->h;
        $full = $y->new('char[' . ($w * $h * 4) . ']');
        $small = $y->new('char[' . ($this->width * $this->height * 4) . ']');
        try {
            if ($pic->p->layout !== 1 || ($pic->p->bpc !== 8 && $pic->p->bpc !== 10)) {
                throw new RuntimeException("myphp: AV1 of {$pic->p->bpc}-bit samples, layout {$pic->p->layout}: only 4:2:0 of 8 or 10 bits decodes in software");
            }
            $seq = FFI::isNull($pic->seq_hdr) ? null : $pic->seq_hdr;
            $wholeRange = $seq !== null && $seq->color_range === 1;
            $mtrx = $seq?->mtrx ?? 2;
            $sd = $mtrx === 5 || $mtrx === 6 || $mtrx === 2 && $w < 1280 && $h <= 576;
            $range = $y->new('vImage_YpCbCrPixelRange');
            [$range->Yp_bias, $range->CbCr_bias, $range->YpRangeMax, $range->CbCrRangeMax, $range->YpMax, $range->YpMin, $range->CbCrMax, $range->CbCrMin]
                = $wholeRange ? [0, 128, 255, 255, 255, 0, 255, 0] : [16, 128, 235, 240, 235, 16, 240, 16];
            $info = $y->new('vImage_YpCbCrToARGB');
            $y->vImageConvert_YpCbCrToARGB_GenerateConversion($sd ? $y->kvImage_YpCbCrToARGBMatrix_ITU_R_601_4 : $y->kvImage_YpCbCrToARGBMatrix_ITU_R_709_2, FFI::addr($range), FFI::addr($info), 3, 0, 0); // kvImage420Yp8_Cb8_Cr8, kvImageARGB8888
            $cw = ($w + 1) >> 1; $ch = ($h + 1) >> 1;
            // The planes as they are, of 8 bits; of 10, a quarter of each in 8 (SoftVideo::eightBits), a row as long as the plane is wide.
            [$planes, $strides] = [[Mem::addr($pic->data[0]), Mem::addr($pic->data[1]), Mem::addr($pic->data[2])], [$pic->stride[0], $pic->stride[1], $pic->stride[1]]];
            if ($pic->p->bpc === 10) {
                $eight = Mem::alloc($w * $h + 2 * $cw * $ch);
                $at = Mem::addr($eight);
                foreach ([[$w, $h, 0], [$cw, $ch, $w * $h], [$cw, $ch, $w * $h + $cw * $ch]] as $k => [$pw, $ph, $off]) {
                    SoftVideo::eightBits($planes[$k], $pw, $ph, $strides[$k], $at + $off);
                    [$planes[$k], $strides[$k]] = [$at + $off, $pw];
                }
            }
            $plane = function (int $k, int $pw, int $ph) use ($y, $planes, $strides) {
                $b = $y->new('vImage_Buffer');
                $b->data = $y->cast('void*', $planes[$k]); $b->width = $pw; $b->height = $ph; $b->rowBytes = $strides[$k];
                return $b;
            };
            $yp = $plane(0, $w, $h); $cb = $plane(1, $cw, $ch); $cr = $plane(2, $cw, $ch);
            $argb = $y->new('vImage_Buffer');
            $argb->data = $y->cast('void*', FFI::addr($full)); $argb->width = $w; $argb->height = $h; $argb->rowBytes = $w * 4;
            $map = $y->new('unsigned char[4]');
            [$map[0], $map[1], $map[2], $map[3]] = [0, 1, 2, 3];
            $st = $y->vImageConvert_420Yp8_Cb8_Cr8ToARGB8888(FFI::addr($yp), FFI::addr($cb), FFI::addr($cr), FFI::addr($argb), FFI::addr($info), $map, 255, 0);
            $out = $y->new('vImage_Buffer');
            $out->data = $y->cast('void*', FFI::addr($small)); $out->width = $this->width; $out->height = $this->height; $out->rowBytes = $this->width * 4;
            $st = $st ?: $y->vImageScale_ARGB8888(FFI::addr($argb), FFI::addr($out), null, 0x10); // kvImageHighQualityResampling
            [$map[0], $map[1], $map[2], $map[3]] = [1, 2, 3, 0]; // A R G B to R G B A
            $st = $st ?: $y->vImagePermuteChannels_ARGB8888(FFI::addr($out), FFI::addr($out), $map, 0);
            if ($st !== 0) {
                throw new RuntimeException("myphp: vImage error $st");
            }
            return FFI::string($small, $this->width * $this->height * 4);
        } finally {
            Dav1d::release($pic);
            if (isset($eight)) { Mem::free($eight); }
        }
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
        if ($this->dav1d !== null) {
            return $this->softFrame($p);
        }
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
