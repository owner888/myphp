<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Media\{Packet, Track};
use RuntimeException;

/**
 * A video track decoded in software (AV1, by libdav1d: Native\Dav1d), as
 * mpv decodes what the system does not: each picture made a CVPixelBuffer
 * of the display layer's own kind (of IOSurfaces, from a pool): NV12 of an
 * 8-bit one, the luma copied, the chroma planes interleaved by vImage;
 * x420 of a 10-bit one (VideoToolbox's of HEVC Main 10), each sample in
 * the high bits of 16, as WebKit's libvpx frames go (CopyPlane_16 and
 * MergeUVPlane_16 by 64), here by vImage through floats; with the
 * sequence header's colors, and a sample buffer of it at the picture's
 * time, which the display layer shows as it shows what it decodes itself.
 * The frames decoded only for those after them (a seek's) are not shown.
 *
 * @internal Native\Renderers
 */
final class SoftVideo
{
    private static ?FFI $c = null;
    private static ?FFI $vi = null;
    private Dav1d $dav1d;
    /** @var array<int, array{0: float, 1: bool}> each packet sent's duration and whether it shows, by its time (microseconds) */
    private array $sent = [];
    /** The pool of pixel buffers, the format description, of the size, depth and range they are of. */
    private ?CData $pool = null, $format = null;
    private string $kind = '';
    /** A 10-bit picture's floats on their way (Mem::alloc'd), of the size they are of: the luma, then the chroma planes, then the chroma interleaved. */
    private ?CData $floats = null;
    private string $floatsOf = '';

    public function __construct(private readonly Track $track)
    {
        $this->dav1d = new Dav1d();
    }

    /** Whether track $t is decoded so: AV1, without the system's decoder, or with it when $hardware is off (IINA's hardware decoding preference). */
    public static function wanted(Track $t, bool $hardware): bool
    {
        return $t->codec === 'V_AV1' && Dav1d::unavailable() === null && (!$hardware || !CoreMedia::playable($t));
    }

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct { long long value; int timescale; unsigned int flags; long long epoch; } CMTime;
            typedef struct { CMTime duration, pts, dts; } CMSampleTimingInfo;
            int CVPixelBufferPoolCreate(void*, void*, void*, void**);
            int CVPixelBufferPoolCreatePixelBuffer(void*, void*, void**);
            int CVPixelBufferLockBaseAddress(void*, unsigned long);
            int CVPixelBufferUnlockBaseAddress(void*, unsigned long);
            void* CVPixelBufferGetBaseAddressOfPlane(void*, size_t);
            size_t CVPixelBufferGetBytesPerRowOfPlane(void*, size_t);
            void CVBufferSetAttachment(void*, void*, void*, int);
            int CMVideoFormatDescriptionCreateForImageBuffer(void*, void*, void**);
            unsigned char CMVideoFormatDescriptionMatchesImageBuffer(void*, void*);
            int CMSampleBufferCreateReadyWithImageBuffer(void*, void*, void*, const CMSampleTimingInfo*, void**);
            void CFRelease(void*);
            extern void* kCVPixelBufferPixelFormatTypeKey;
            extern void* kCVPixelBufferWidthKey;
            extern void* kCVPixelBufferHeightKey;
            extern void* kCVPixelBufferIOSurfacePropertiesKey;
            extern void* kCVImageBufferYCbCrMatrixKey;
            extern void* kCVImageBufferColorPrimariesKey;
            extern void* kCVImageBufferTransferFunctionKey;
            extern void* kCVImageBufferYCbCrMatrix_ITU_R_709_2;
            extern void* kCVImageBufferYCbCrMatrix_ITU_R_601_4;
            extern void* kCVImageBufferYCbCrMatrix_ITU_R_2020;
            extern void* kCVImageBufferColorPrimaries_ITU_R_709_2;
            extern void* kCVImageBufferColorPrimaries_ITU_R_2020;
            extern void* kCVImageBufferColorPrimaries_SMPTE_C;
            extern void* kCVImageBufferTransferFunction_ITU_R_709_2;
            extern void* kCVImageBufferTransferFunction_SMPTE_ST_2084_PQ;
            extern void* kCVImageBufferTransferFunction_ITU_R_2100_HLG;
        ', '/System/Library/Frameworks/VideoToolbox.framework/VideoToolbox'); // its CoreVideo and CoreMedia, which it links
    }

    private static function vi(): FFI
    {
        return self::$vi ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            long vImageConvert_PlanarToChunky8(const vImage_Buffer* srcPlanarBuffers[], void* destChannels[], unsigned int channelCount, size_t destStrideBytes, unsigned long destWidth, unsigned long destHeight, size_t destRowBytes, unsigned int flags);
            long vImageConvert_PlanarToChunkyF(const vImage_Buffer* srcPlanarBuffers[], void* destChannels[], unsigned int channelCount, size_t destStrideBytes, unsigned long destWidth, unsigned long destHeight, size_t destRowBytes, unsigned int flags);
            long vImageConvert_16UToF(const vImage_Buffer* src, const vImage_Buffer* dest, float offset, float scale, unsigned int flags);
            long vImageConvert_FTo16U(const vImage_Buffer* src, const vImage_Buffer* dest, float offset, float scale, unsigned int flags);
            long vImageConvert_PlanarFtoPlanar8(const vImage_Buffer* src, const vImage_Buffer* dest, float maxFloat, float minFloat, unsigned int flags);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    /** Whether it takes another packet now. */
    public function ready(): bool
    {
        return !$this->dav1d->full();
    }

    /**
     * Takes the pictures decoded, each to $layer, while dav1d holds a packet
     * it refused (sent again at each take): whether it takes another now.
     * None is waited for: what dav1d's threads still decode (a seek's
     * frames from its keyframe) comes at a later call, the main thread free
     * meanwhile, as VideoToolbox decodes on threads of its own.
     */
    public function unclog($layer): bool
    {
        while (!$this->ready()) {
            if (!$this->take($layer) && !$this->ready()) {
                return false;
            }
        }
        return true;
    }

    /** Sends packet $p (when ready()), shown unless $show is false; a picture decoded, if one is, goes to $layer. */
    public function send(Packet $p, bool $show, $layer): void
    {
        if (!$this->ready()) {
            throw new RuntimeException('myphp: dav1d is full: take a picture first');
        }
        $at = (int)round($p->time * 1e6);
        $this->sent[$at] = [$p->duration, $show];
        $this->dav1d->send($p->data, $at);
        $this->take($layer);
    }

    /**
     * Gives $layer the picture decoded, if one is; at the end ($drain), all
     * those left. Once at a time otherwise: dav1d asked again with nothing
     * sent between drains, waiting for the frames its threads decode.
     * Whether it gave one.
     */
    public function take($layer, bool $drain = false): bool
    {
        $took = false;
        do {
            $pic = $this->dav1d->picture();
            if ($pic === null) {
                return $took;
            }
            $took = true;
            try {
                $at = (int)$pic->m->timestamp;
                [$duration, $show] = $this->sent[$at] ?? [0.0, true];
                unset($this->sent[$at]);
                if ($show) { $this->show($pic, $at / 1e6, $duration, $layer); }
            } finally {
                Dav1d::release($pic);
            }
        } while ($drain);
        return $took;
    }

    /** Drops what it holds, for a seek. */
    public function flush(): void
    {
        $this->dav1d->flush();
        $this->sent = [];
    }

    /** Picture $pic as a pixel buffer, in a sample buffer at $time, given to $layer. */
    private function show(CData $pic, float $time, float $duration, $layer): void
    {
        $c = self::c();
        $pb = $this->pixels($pic);
        try {
            $seq = FFI::isNull($pic->seq_hdr) ? null : $pic->seq_hdr;
            $this->colors($pb, $seq, $pic->p->w, $pic->p->h);
            if ($this->format !== null && $c->CMVideoFormatDescriptionMatchesImageBuffer($this->format, $pb) === 0) {
                $c->CFRelease($this->format);
                $this->format = null;
            }
            if ($this->format === null) {
                $fd = $c->new('void*');
                if ($c->CMVideoFormatDescriptionCreateForImageBuffer(null, $pb, FFI::addr($fd)) !== 0) {
                    throw new RuntimeException('myphp: CMVideoFormatDescriptionCreateForImageBuffer');
                }
                $this->format = $fd;
            }
            $timing = $c->new('CMSampleTimingInfo');
            foreach (['duration' => $duration, 'pts' => $time] as $k => $s) {
                $timing->$k->value = (int)round($s * 90000); $timing->$k->timescale = 90000; $timing->$k->flags = 1;
            }
            $timing->dts->flags = 0; // invalid: a decoded frame's
            $sb = $c->new('void*');
            if ($c->CMSampleBufferCreateReadyWithImageBuffer(null, $pb, $this->format, FFI::addr($timing), FFI::addr($sb)) !== 0) {
                throw new RuntimeException('myphp: CMSampleBufferCreateReadyWithImageBuffer');
            }
            ObjC::msg('v:p', $layer, 'enqueueSampleBuffer:', ObjC::import($sb));
            $c->CFRelease($sb);
        } finally {
            $c->CFRelease($pb);
        }
    }

    /**
     * @internal Picture $pic's samples in a pixel buffer of the pool (the
     * caller's to release): NV12 ('420v', '420f') of 8 bits, x420 ('x420',
     * 'xf20') of 10, its range the sequence header's.
     */
    public function pixels(CData $pic)
    {
        $w = $pic->p->w; $h = $pic->p->h; $bpc = $pic->p->bpc;
        if ($pic->p->layout !== 1 || ($bpc !== 8 && $bpc !== 10)) {
            throw new RuntimeException("myphp: AV1 of {$bpc}-bit samples, layout {$pic->p->layout}: only 4:2:0 of 8 or 10 bits decodes in software");
        }
        $seq = FFI::isNull($pic->seq_hdr) ? null : $pic->seq_hdr;
        $full = $seq !== null && $seq->color_range === 1;
        $c = self::c();
        $kind = "$w x $h $bpc " . ($full ? 'f' : 'v');
        if ($this->kind !== $kind) { $this->pool($w, $h, $bpc, $full); $this->kind = $kind; }
        $pb = $c->new('void*');
        if ($c->CVPixelBufferPoolCreatePixelBuffer(null, $this->pool, FFI::addr($pb)) !== 0) {
            throw new RuntimeException('myphp: CVPixelBufferPoolCreatePixelBuffer');
        }
        $c->CVPixelBufferLockBaseAddress($pb, 0);
        try {
            $bpc === 8 ? $this->nv12($pic, $pb) : $this->x420($pic, $pb);
        } catch (\Throwable $e) {
            $c->CVPixelBufferUnlockBaseAddress($pb, 0);
            $c->CFRelease($pb);
            throw $e;
        }
        $c->CVPixelBufferUnlockBaseAddress($pb, 0);
        return $pb;
    }

    /** An 8-bit picture into NV12: the luma copied (a plane at a time where the rows are as long), U and V interleaved. */
    private function nv12(CData $pic, $pb): void
    {
        $c = self::c(); $vi = self::vi();
        $w = $pic->p->w; $h = $pic->p->h;
        $dst = Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 0));
        $bpr = $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 0);
        $src = Mem::addr($pic->data[0]); $stride = $pic->stride[0];
        if ($bpr === $stride) { Mem::copy($dst, $src, $stride * $h); }
        else { for ($y = 0; $y < $h; $y++) { Mem::copy($dst + $y * $bpr, $src + $y * $stride, $w); } }
        $cw = ($w + 1) >> 1; $ch = ($h + 1) >> 1;
        $planes = $vi->new('const vImage_Buffer*[2]');
        $bufs = [self::buffer(Mem::addr($pic->data[1]), $cw, $ch, $pic->stride[1]), self::buffer(Mem::addr($pic->data[2]), $cw, $ch, $pic->stride[1])];
        foreach ($bufs as $k => $b) { $planes[$k] = FFI::addr($b); }
        $uv = Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 1));
        $dests = $vi->new('void*[2]');
        $dests[0] = $vi->cast('void*', $uv); $dests[1] = $vi->cast('void*', $uv + 1);
        self::ok($vi->vImageConvert_PlanarToChunky8($planes, $dests, 2, 2, $cw, $ch, $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 1), 0));
    }

    /**
     * A 10-bit picture into x420: each plane's samples (dav1d's 16-bit, the
     * 10 in the low bits) as floats 64 times as large, the chroma's
     * interleaved, and back to 16 bits, the 10 in the high bits.
     */
    private function x420(CData $pic, $pb): void
    {
        $c = self::c(); $vi = self::vi();
        $w = $pic->p->w; $h = $pic->p->h;
        $cw = ($w + 1) >> 1; $ch = ($h + 1) >> 1;
        if ($this->floatsOf !== "$w x $h") {
            if ($this->floats !== null) { Mem::free($this->floats); }
            $this->floats = Mem::alloc(4 * ($w * $h + 4 * $cw * $ch));
            $this->floatsOf = "$w x $h";
        }
        $at = Mem::addr($this->floats);
        [$ay, $au, $av, $uv] = [$at, $at + 4 * $w * $h, $at + 4 * ($w * $h + $cw * $ch), $at + 4 * ($w * $h + 2 * $cw * $ch)];
        // Each vImage_Buffer in a variable: FFI::addr() of a temporary would point at what is freed.
        $sy = self::buffer(Mem::addr($pic->data[0]), $w, $h, $pic->stride[0]);
        $fy = self::buffer($ay, $w, $h, 4 * $w);
        $dy = self::buffer(Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 0)), $w, $h, $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 0));
        self::ok($vi->vImageConvert_16UToF(FFI::addr($sy), FFI::addr($fy), 0.0, 64.0, 0));
        self::ok($vi->vImageConvert_FTo16U(FFI::addr($fy), FFI::addr($dy), 0.0, 1.0, 0));
        $planes = $vi->new('const vImage_Buffer*[2]');
        $fs = [self::buffer($au, $cw, $ch, 4 * $cw), self::buffer($av, $cw, $ch, 4 * $cw)];
        $ss = [self::buffer(Mem::addr($pic->data[1]), $cw, $ch, $pic->stride[1]), self::buffer(Mem::addr($pic->data[2]), $cw, $ch, $pic->stride[1])];
        foreach ([0, 1] as $k) {
            self::ok($vi->vImageConvert_16UToF(FFI::addr($ss[$k]), FFI::addr($fs[$k]), 0.0, 64.0, 0));
            $planes[$k] = FFI::addr($fs[$k]);
        }
        $dests = $vi->new('void*[2]');
        $dests[0] = $vi->cast('void*', $uv); $dests[1] = $vi->cast('void*', $uv + 4);
        self::ok($vi->vImageConvert_PlanarToChunkyF($planes, $dests, 2, 8, $cw, $ch, 8 * $cw, 0));
        $fuv = self::buffer($uv, 2 * $cw, $ch, 8 * $cw);
        $duv = self::buffer(Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 1)), 2 * $cw, $ch, $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 1));
        self::ok($vi->vImageConvert_FTo16U(FFI::addr($fuv), FFI::addr($duv), 0.0, 1.0, 0));
    }

    /**
     * @internal VideoDecoder: a 10-bit plane of dav1d's ($pw by $ph samples
     * at $src, $stride bytes a row) as 8 bits at $dst ($pw a row): a
     * quarter, rounded, through floats.
     */
    public static function eightBits(int $src, int $pw, int $ph, int $stride, int $dst): void
    {
        $vi = self::vi();
        $f = Mem::alloc(4 * $pw * $ph);
        try {
            $sb = self::buffer($src, $pw, $ph, $stride);
            $fb = self::buffer(Mem::addr($f), $pw, $ph, 4 * $pw);
            $db = self::buffer($dst, $pw, $ph, $pw);
            self::ok($vi->vImageConvert_16UToF(FFI::addr($sb), FFI::addr($fb), 0.0, 0.25, 0));
            self::ok($vi->vImageConvert_PlanarFtoPlanar8(FFI::addr($fb), FFI::addr($db), 255.0, 0.0, 0));
        } finally {
            Mem::free($f);
        }
    }

    /** A vImage_Buffer of $width by $height at $addr, $rowBytes a row. */
    private static function buffer(int $addr, int $width, int $height, int $rowBytes): CData
    {
        $vi = self::vi();
        $b = $vi->new('vImage_Buffer');
        $b->data = $vi->cast('void*', $addr); $b->width = $width; $b->height = $height; $b->rowBytes = $rowBytes;
        return $b;
    }

    private static function ok(int $st): void
    {
        if ($st !== 0) {
            throw new RuntimeException("myphp: vImage error $st");
        }
    }

    /** The pool of pixel buffers of IOSurfaces, $w by $h, NV12 of 8 bits or x420 of 10 ($bpc), of the video's range or the full one. */
    private function pool(int $w, int $h, int $bpc, bool $full): void
    {
        $c = self::c();
        if ($this->pool !== null) { $c->CFRelease($this->pool); $this->pool = null; }
        if ($this->format !== null) { $c->CFRelease($this->format); $this->format = null; }
        $num = fn(int $v) => ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v);
        $a = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num(CoreMedia::fourcc($bpc === 8 ? ($full ? '420f' : '420v') : ($full ? 'xf20' : 'x420'))), ObjC::import($c->kCVPixelBufferPixelFormatTypeKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($w), ObjC::import($c->kCVPixelBufferWidthKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($h), ObjC::import($c->kCVPixelBufferHeightKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary'), ObjC::import($c->kCVPixelBufferIOSurfacePropertiesKey));
        $pool = $c->new('void*');
        if ($c->CVPixelBufferPoolCreate(null, null, $c->cast('void*', ObjC::addr($a)), FFI::addr($pool)) !== 0) {
            throw new RuntimeException('myphp: CVPixelBufferPoolCreate');
        }
        $this->pool = $pool;
    }

    /**
     * The colors the sequence header says (AV1's color_config: H.273's
     * codes), on $pb; where it says none, mpv's guess (CoreMedia::colorNames).
     */
    private function colors(CData $pb, ?CData $seq, int $w, int $h): void
    {
        $c = self::c();
        [$mtrx, $pri, $trc] = $seq === null ? [2, 2, 2] : [$seq->mtrx, $seq->pri, $seq->trc];
        [$m, $p, $t] = CoreMedia::colorNames($mtrx, $pri, $trc, $w, $h);
        [$matrix, $primaries, $transfer] = [$c->{"kCVImageBufferYCbCrMatrix_$m"}, $c->{"kCVImageBufferColorPrimaries_$p"}, $c->{"kCVImageBufferTransferFunction_$t"}];
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferYCbCrMatrixKey, $matrix, 1); // kCVAttachmentMode_ShouldPropagate
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferColorPrimariesKey, $primaries, 1);
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferTransferFunctionKey, $transfer, 1);
    }

    public function __destruct()
    {
        if (self::$c === null) { return; }
        if ($this->pool !== null) { self::$c->CFRelease($this->pool); }
        if ($this->format !== null) { self::$c->CFRelease($this->format); }
        if ($this->floats !== null) { Mem::free($this->floats); }
    }
}
