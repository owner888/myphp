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
 * of the display layer's own kind (NV12 of IOSurfaces, from a pool: the
 * luma copied, the chroma planes interleaved by vImage) with the
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
    /** The pool of pixel buffers, the format description, of the size and range they are of. */
    private ?CData $pool = null, $format = null;
    private string $kind = '';

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
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    /** Whether it takes another packet now. */
    public function ready(): bool
    {
        return !$this->dav1d->full();
    }

    /**
     * Takes pictures until dav1d takes a packet again (a packet it refused
     * sent each time: what its threads decode comes, the main thread
     * waiting as VideoToolbox's decode does), each to $layer.
     */
    public function unclog($layer): void
    {
        for ($i = 0; !$this->ready(); $i++) {
            if ($i === 10000) {
                throw new RuntimeException('myphp: dav1d takes no packet and gives no picture');
            }
            $this->take($layer);
        }
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
     */
    public function take($layer, bool $drain = false): void
    {
        do {
            $pic = $this->dav1d->picture();
            if ($pic === null) {
                return;
            }
            try {
                $at = (int)$pic->m->timestamp;
                [$duration, $show] = $this->sent[$at] ?? [0.0, true];
                unset($this->sent[$at]);
                if ($show) { $this->show($pic, $at / 1e6, $duration, $layer); }
            } finally {
                Dav1d::release($pic);
            }
        } while ($drain);
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
        $w = $pic->p->w; $h = $pic->p->h;
        if ($pic->p->layout !== 1 || $pic->p->bpc !== 8) {
            throw new RuntimeException("myphp: AV1 of {$pic->p->bpc}-bit samples, layout {$pic->p->layout}: only 8-bit 4:2:0 decodes in software yet");
        }
        $seq = FFI::isNull($pic->seq_hdr) ? null : $pic->seq_hdr;
        $full = $seq !== null && $seq->color_range === 1;
        $c = self::c();
        $kind = "$w x $h " . ($full ? 'f' : 'v');
        if ($this->kind !== $kind) { $this->pool($w, $h, $full); $this->kind = $kind; }
        $pb = $c->new('void*');
        if ($c->CVPixelBufferPoolCreatePixelBuffer(null, $this->pool, FFI::addr($pb)) !== 0) {
            throw new RuntimeException('myphp: CVPixelBufferPoolCreatePixelBuffer');
        }
        try {
            $c->CVPixelBufferLockBaseAddress($pb, 0);
            // Luma, a row at a time unless the rows are as long.
            $dst = Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 0));
            $bpr = $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 0);
            $src = Mem::addr($pic->data[0]); $stride = $pic->stride[0];
            if ($bpr === $stride) { Mem::copy($dst, $src, $stride * $h); }
            else { for ($y = 0; $y < $h; $y++) { Mem::copy($dst + $y * $bpr, $src + $y * $stride, $w); } }
            // Chroma: U and V, planar, interleaved as NV12's.
            $vi = self::vi();
            $cw = ($w + 1) >> 1; $ch = ($h + 1) >> 1;
            $planes = $vi->new('const vImage_Buffer*[2]');
            $bufs = [$vi->new('vImage_Buffer'), $vi->new('vImage_Buffer')];
            foreach ([1, 2] as $k => $plane) {
                $bufs[$k]->data = $vi->cast('void*', Mem::addr($pic->data[$plane]));
                $bufs[$k]->width = $cw; $bufs[$k]->height = $ch; $bufs[$k]->rowBytes = $pic->stride[1];
                $planes[$k] = FFI::addr($bufs[$k]);
            }
            $uv = Mem::addr($c->CVPixelBufferGetBaseAddressOfPlane($pb, 1));
            $dests = $vi->new('void*[2]');
            $dests[0] = $vi->cast('void*', $uv); $dests[1] = $vi->cast('void*', $uv + 1);
            $st = $vi->vImageConvert_PlanarToChunky8($planes, $dests, 2, 2, $cw, $ch, $c->CVPixelBufferGetBytesPerRowOfPlane($pb, 1), 0);
            $c->CVPixelBufferUnlockBaseAddress($pb, 0);
            if ($st !== 0) {
                throw new RuntimeException("myphp: vImage error $st");
            }
            $this->colors($pb, $seq, $w, $h);
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

    /** The pool of NV12 pixel buffers of IOSurfaces, $w by $h, of the video's range or the full one. */
    private function pool(int $w, int $h, bool $full): void
    {
        $c = self::c();
        if ($this->pool !== null) { $c->CFRelease($this->pool); $this->pool = null; }
        if ($this->format !== null) { $c->CFRelease($this->format); $this->format = null; }
        $num = fn(int $v) => ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v);
        $a = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num(CoreMedia::fourcc($full ? '420f' : '420v')), ObjC::import($c->kCVPixelBufferPixelFormatTypeKey));
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
     * codes), on $pb; where it says none (2), mpv's guess by the size: BT.709
     * from 1280 wide or past 576 lines, else BT.601 (SMPTE C's primaries).
     */
    private function colors(CData $pb, ?CData $seq, int $w, int $h): void
    {
        $c = self::c();
        [$mtrx, $pri, $trc] = $seq === null ? [2, 2, 2] : [$seq->mtrx, $seq->pri, $seq->trc];
        $hd = $w >= 1280 || $h > 576;
        if ($mtrx === 2) { $mtrx = $hd ? 1 : 6; }
        if ($pri === 2) { $pri = $hd ? 1 : 6; }
        $matrix = match ($mtrx) { 5, 6 => $c->kCVImageBufferYCbCrMatrix_ITU_R_601_4, 9, 10 => $c->kCVImageBufferYCbCrMatrix_ITU_R_2020, default => $c->kCVImageBufferYCbCrMatrix_ITU_R_709_2 };
        $primaries = match ($pri) { 9 => $c->kCVImageBufferColorPrimaries_ITU_R_2020, 5, 6 => $c->kCVImageBufferColorPrimaries_SMPTE_C, default => $c->kCVImageBufferColorPrimaries_ITU_R_709_2 };
        $transfer = match ($trc) { 16 => $c->kCVImageBufferTransferFunction_SMPTE_ST_2084_PQ, 18 => $c->kCVImageBufferTransferFunction_ITU_R_2100_HLG, default => $c->kCVImageBufferTransferFunction_ITU_R_709_2 };
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferYCbCrMatrixKey, $matrix, 1); // kCVAttachmentMode_ShouldPropagate
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferColorPrimariesKey, $primaries, 1);
        $c->CVBufferSetAttachment($pb, $c->kCVImageBufferTransferFunctionKey, $transfer, 1);
    }

    public function __destruct()
    {
        if (self::$c === null) { return; }
        if ($this->pool !== null) { self::$c->CFRelease($this->pool); }
        if ($this->format !== null) { self::$c->CFRelease($this->format); }
    }
}
