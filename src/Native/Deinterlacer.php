<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * A video track decoded here and deinterlaced before it shows (IINA's
 * Deinterlace, mpv's deinterlace option), where the display layer would
 * decode it itself: a VideoToolbox decompression session whose output is
 * NV12 ('420v') of the picture's size, each frame decoded synchronously
 * (its callback on VideoToolbox's thread keeps the frame, as
 * ThumbnailDecoder's), then each plane blended down its lines by vImage
 * ([1 2 1] / 4: the fields mixed, the combs gone; FFmpeg's pp lb), into a
 * pixel buffer of a pool, of the frame's attachments (its colors), which
 * goes to the layer at the frame's time. Frames come out of the decoder
 * in the order they are decoded: a few are held, those of the least time
 * given first (the order they show in). Those decoded only for those
 * after them (a seek's) are not shown.
 *
 * @internal Native\Renderers
 */
final class Deinterlacer
{
    /** The frames held to be given in the order they show: more than a stream's reordering (H.264's B-pyramids). */
    private const HELD = 4;
    private static ?FFI $vt = null, $vi = null;
    private ?CData $session = null, $pool = null, $format = null;
    /** The size of the pool's pixel buffers. */
    private string $poolOf = '';
    private CData $record, $holder;
    private \stdClass $out;
    /** @var list<array{0: float, 1: float, 2: CData}> the frames held: their time, duration and pixel buffer (owned) */
    private array $held = [];

    public function __construct()
    {
        $vt = self::vt();
        $out = $this->out = (object)['status' => 0, 'image' => null];
        $this->holder = $vt->new('struct { void (*fn)(void*, void*, int, unsigned int, void*); }');
        // On VideoToolbox's thread, the main one waiting in the decode: the frame kept, nothing more.
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
            typedef struct { long long value; int timescale; unsigned int flags; long long epoch; } CMTime;
            typedef struct { CMTime duration, pts, dts; } CMSampleTimingInfo;
            int VTDecompressionSessionCreate(void*, void*, void*, void*, const Rec*, void**);
            int VTDecompressionSessionDecodeFrame(void*, void*, unsigned int, void*, unsigned int*);
            void VTDecompressionSessionInvalidate(void*);
            unsigned char VTDecompressionSessionCanAcceptFormatDescription(void*, void*);
            void* CMSampleBufferGetFormatDescription(void*);
            void* CVPixelBufferRetain(void*);
            int CVPixelBufferPoolCreate(void*, void*, void*, void**);
            int CVPixelBufferPoolCreatePixelBuffer(void*, void*, void**);
            int CVPixelBufferLockBaseAddress(void*, unsigned long);
            int CVPixelBufferUnlockBaseAddress(void*, unsigned long);
            void* CVPixelBufferGetBaseAddressOfPlane(void*, size_t);
            size_t CVPixelBufferGetBytesPerRowOfPlane(void*, size_t);
            size_t CVPixelBufferGetWidthOfPlane(void*, size_t);
            size_t CVPixelBufferGetHeightOfPlane(void*, size_t);
            size_t CVPixelBufferGetWidth(void*);
            size_t CVPixelBufferGetHeight(void*);
            unsigned int CVPixelBufferGetPixelFormatType(void*);
            void CVBufferPropagateAttachments(void*, void*);
            int CMVideoFormatDescriptionCreateForImageBuffer(void*, void*, void**);
            unsigned char CMVideoFormatDescriptionMatchesImageBuffer(void*, void*);
            int CMSampleBufferCreateReadyWithImageBuffer(void*, void*, void*, const CMSampleTimingInfo*, void**);
            void CFRelease(void*);
            extern void* kCVPixelBufferPixelFormatTypeKey;
            extern void* kCVPixelBufferIOSurfacePropertiesKey;
            extern void* kCVPixelBufferWidthKey;
            extern void* kCVPixelBufferHeightKey;
        ', '/System/Library/Frameworks/VideoToolbox.framework/VideoToolbox'); // its CoreVideo and CoreMedia, which it links
    }

    private static function vi(): FFI
    {
        return self::$vi ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            long vImageConvolve_Planar8(const vImage_Buffer*, const vImage_Buffer*, void*, unsigned long, unsigned long, const short*, unsigned int, unsigned int, int, unsigned char, unsigned int);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    /**
     * Decodes sample buffer $sb (a frame of $time and $duration), and gives
     * $layer the frames held past HELD, deinterlaced; shown unless $show is
     * false.
     */
    public function send(CData $sb, float $time, float $duration, bool $show, $layer): void
    {
        $vt = self::vt();
        $sb = $vt->cast('void*', Mem::addr($sb)); // CoreMedia's, through this library's declarations
        $format = $vt->CMSampleBufferGetFormatDescription($sb);
        if ($this->session !== null && ($format === null || !$vt->VTDecompressionSessionCanAcceptFormatDescription($this->session, $format))) {
            $this->close(); // another format (a resolution change): a session of it
        }
        if ($this->session === null) {
            $session = $vt->new('void*');
            $st = $vt->VTDecompressionSessionCreate(null, $format, null, $vt->cast('void*', Mem::addr(self::attributes())), FFI::addr($this->record), FFI::addr($session));
            if ($st !== 0) {
                throw new RuntimeException("myphp: a decoder to deinterlace: VideoToolbox error $st");
            }
            $this->session = $session;
        }
        $info = $vt->new('unsigned int');
        $st = $vt->VTDecompressionSessionDecodeFrame($this->session, $sb, 0, null, FFI::addr($info)); // synchronous: the callback has run
        $image = $this->out->image;
        $this->out->image = null;
        if ($st !== 0 || $this->out->status !== 0) {
            if ($image !== null) { $vt->CFRelease($image); }
            throw new RuntimeException(sprintf('myphp: decoding the frame at %.3f s to deinterlace it: VideoToolbox error %d', $time, $st !== 0 ? $st : $this->out->status));
        }
        if ($image === null) {
            return; // a frame the decoder drops
        }
        if (!$show) {
            $vt->CFRelease($image);
            return;
        }
        try {
            $this->held[] = [$time, $duration, $this->deinterlaced($image)];
        } finally {
            $vt->CFRelease($image);
        }
        usort($this->held, fn(array $a, array $b) => $a[0] <=> $b[0]);
        while (count($this->held) > self::HELD) {
            $this->give(array_shift($this->held), $layer);
        }
    }

    /** At the end: the frames held given to $layer. */
    public function drain($layer): void
    {
        foreach ($this->held as $f) { $this->give($f, $layer); }
        $this->held = [];
    }

    /** Drops the frames held, for a seek. */
    public function flush(): void
    {
        foreach ($this->held as $f) { self::vt()->CFRelease($f[2]); }
        $this->held = [];
    }

    /** NV12 of the decoded picture's size, of IOSurfaces. */
    private static function attributes()
    {
        $vt = self::vt();
        $a = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $a, 'setObject:forKey:', ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', CoreMedia::fourcc('420v')), ObjC::import($vt->kCVPixelBufferPixelFormatTypeKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary'), ObjC::import($vt->kCVPixelBufferIOSurfacePropertiesKey));
        return $a;
    }

    /** Picture $image blended down its lines ([1 2 1] / 4 each plane, its edges extended) into a pixel buffer of the pool (owned), its attachments with it. */
    private function deinterlaced(CData $image): CData
    {
        $vt = self::vt(); $vi = self::vi();
        [$w, $h] = [$vt->CVPixelBufferGetWidth($image), $vt->CVPixelBufferGetHeight($image)];
        if ($this->poolOf !== "$w x $h") { $this->newPool($w, $h); }
        $pb = $vt->new('void*');
        if ($vt->CVPixelBufferPoolCreatePixelBuffer(null, $this->pool, FFI::addr($pb)) !== 0) {
            throw new RuntimeException('myphp: CVPixelBufferPoolCreatePixelBuffer');
        }
        $kernel = $vi->new('short[3]');
        [$kernel[0], $kernel[1], $kernel[2]] = [1, 2, 1];
        $vt->CVPixelBufferLockBaseAddress($image, 1); // kCVPixelBufferLock_ReadOnly
        $vt->CVPixelBufferLockBaseAddress($pb, 0);
        try {
            for ($plane = 0; $plane < 2; $plane++) { // the luma, then the chroma (interleaved, its bytes a plane of twice the width: down its lines, U stays U)
                $pw = $vt->CVPixelBufferGetWidthOfPlane($image, $plane) * ($plane === 0 ? 1 : 2);
                $ph = $vt->CVPixelBufferGetHeightOfPlane($image, $plane);
                $src = self::buffer($vt->CVPixelBufferGetBaseAddressOfPlane($image, $plane), $pw, $ph, $vt->CVPixelBufferGetBytesPerRowOfPlane($image, $plane));
                $dst = self::buffer($vt->CVPixelBufferGetBaseAddressOfPlane($pb, $plane), $pw, $ph, $vt->CVPixelBufferGetBytesPerRowOfPlane($pb, $plane));
                $st = $vi->vImageConvolve_Planar8(FFI::addr($src), FFI::addr($dst), null, 0, 0, $kernel, 3, 1, 4, 0, 8); // kvImageEdgeExtend
                if ($st !== 0) {
                    throw new RuntimeException("myphp: deinterlacing: vImage error $st");
                }
            }
        } catch (\Throwable $e) {
            $vt->CVPixelBufferUnlockBaseAddress($pb, 0);
            $vt->CFRelease($pb);
            throw $e;
        } finally {
            $vt->CVPixelBufferUnlockBaseAddress($image, 1);
        }
        $vt->CVPixelBufferUnlockBaseAddress($pb, 0);
        $vt->CVBufferPropagateAttachments($image, $pb); // its colors, its HDR metadata
        return $pb;
    }

    /** A vImage_Buffer of a plane at $data. */
    private static function buffer($data, int $w, int $h, int $rowBytes): CData
    {
        $vi = self::vi();
        $b = $vi->new('vImage_Buffer');
        $b->data = $vi->cast('void*', $data); $b->width = $w; $b->height = $h; $b->rowBytes = $rowBytes;
        return $b;
    }

    /** Frame $f (time, duration, pixel buffer) in a sample buffer at its time, given to $layer; the pixel buffer released. @param array{0: float, 1: float, 2: CData} $f */
    private function give(array $f, $layer): void
    {
        $vt = self::vt();
        [$time, $duration, $pb] = $f;
        try {
            if ($this->format !== null && $vt->CMVideoFormatDescriptionMatchesImageBuffer($this->format, $pb) === 0) {
                $vt->CFRelease($this->format);
                $this->format = null;
            }
            if ($this->format === null) {
                $fd = $vt->new('void*');
                if ($vt->CMVideoFormatDescriptionCreateForImageBuffer(null, $pb, FFI::addr($fd)) !== 0) {
                    throw new RuntimeException('myphp: CMVideoFormatDescriptionCreateForImageBuffer');
                }
                $this->format = $fd;
            }
            $timing = $vt->new('CMSampleTimingInfo');
            foreach (['duration' => $duration, 'pts' => $time] as $k => $s) {
                $timing->$k->value = (int)round($s * 90000); $timing->$k->timescale = 90000; $timing->$k->flags = 1;
            }
            $timing->dts->flags = 0; // invalid: a decoded frame's
            $sb = $vt->new('void*');
            if ($vt->CMSampleBufferCreateReadyWithImageBuffer(null, $pb, $this->format, FFI::addr($timing), FFI::addr($sb)) !== 0) {
                throw new RuntimeException('myphp: CMSampleBufferCreateReadyWithImageBuffer');
            }
            ObjC::msg('v:p', $layer, 'enqueueSampleBuffer:', ObjC::import($sb));
            $vt->CFRelease($sb);
        } finally {
            $vt->CFRelease($pb);
        }
    }

    /** A pool of NV12 pixel buffers of IOSurfaces, $w by $h. */
    private function newPool(int $w, int $h): void
    {
        $vt = self::vt();
        if ($this->pool !== null) { $vt->CFRelease($this->pool); $this->pool = null; }
        $num = fn(int $v) => ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v);
        $a = self::attributes();
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($w), ObjC::import($vt->kCVPixelBufferWidthKey));
        ObjC::msg('v:pp', $a, 'setObject:forKey:', $num($h), ObjC::import($vt->kCVPixelBufferHeightKey));
        $pool = $vt->new('void*');
        if ($vt->CVPixelBufferPoolCreate(null, null, $vt->cast('void*', Mem::addr($a)), FFI::addr($pool)) !== 0) {
            throw new RuntimeException('myphp: CVPixelBufferPoolCreate');
        }
        $this->pool = $pool;
        $this->poolOf = "$w x $h";
    }

    /** The session ended. */
    private function close(): void
    {
        $vt = self::vt();
        $vt->VTDecompressionSessionInvalidate($this->session);
        $vt->CFRelease($this->session);
        $this->session = null;
    }

    public function __destruct()
    {
        if (self::$vt === null) { return; }
        $this->flush();
        if ($this->session !== null) { $this->close(); }
        if ($this->pool !== null) { self::$vt->CFRelease($this->pool); }
        if ($this->format !== null) { self::$vt->CFRelease($this->format); }
    }
}
