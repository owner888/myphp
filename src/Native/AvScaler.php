<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * libswscale's conversion of decoded video frames to RGBA of a size
 * (AvRenderers' picture, AvThumbnails): bilinear, from the
 * frame's colors as it says them (BT.709, BT.601 or BT.2020's matrix,
 * limited or full range; unsaid, BT.709 from 720 lines up, as FFmpeg's
 * players guess), its context made again only as the frames change.
 *
 * @internal
 */
final class AvScaler
{
    /** AVCOL_SPC_BT709, BT470BG, SMPTE170M, BT2020_NCL, BT2020_CL and the SWS_CS_ of each; AVCOL_RANGE_JPEG. */
    private const MATRICES = [1 => 1, 5 => 5, 6 => 5, 9 => 9, 10 => 9], FULL = 2;

    private ?CData $ctx = null;
    /** The output's pixels, reused while its size stays, and its planes and strides as libswscale takes them. */
    private ?CData $pixels = null;
    private CData $planes, $strides;
    private int $size = 0;
    /** What the context was made for: the source's size, format, matrix and range, the size made. */
    private array $made = [];

    public function __construct()
    {
        $f = Av::ffi();
        $this->planes = $f->new('uint8_t*[4]');
        $this->strides = $f->new('int[4]');
    }

    /** $frame (a video AVFrame*) as $w × $h RGBA pixels, top row first. */
    public function rgba(CData $frame, int $w, int $h): string
    {
        $f = Av::ffi();
        $cs = $frame->colorspace;
        $matrix = self::MATRICES[$cs] ?? ($frame->height >= 720 ? 1 : 5);
        $full = $frame->color_range === self::FULL ? 1 : 0;
        $made = [$frame->width, $frame->height, $frame->format, $matrix, $full, $w, $h];
        if ($made !== $this->made) {
            $this->ctx = $f->sws_getCachedContext($this->ctx, $frame->width, $frame->height, $frame->format, $w, $h, Av::RGBA, Av::BILINEAR, null, null, null)
                ?? throw new \RuntimeException("myphp: libswscale converts no frames of format {$frame->format} ({$frame->width}x{$frame->height}) to RGBA");
            $f->sws_setColorspaceDetails($this->ctx, $f->sws_getCoefficients($matrix), $full, $f->sws_getCoefficients(1), 1, 0, 1 << 16, 1 << 16);
            $this->made = $made;
        }
        $n = $w * $h * 4;
        if ($n !== $this->size) {
            if ($this->pixels !== null) { Mem::free($this->pixels); }
            $this->pixels = Mem::alloc($n);
            $this->size = $n;
            $this->planes[0] = $f->cast('uint8_t*', Mem::addr($this->pixels));
            $this->strides[0] = $w * 4;
        }
        $f->sws_scale($this->ctx, FFI::addr($frame->data[0]), FFI::addr($frame->linesize[0]), 0, $frame->height, FFI::addr($this->planes[0]), FFI::addr($this->strides[0]));
        return FFI::string($this->pixels, $n);
    }

    public function __destruct()
    {
        if ($this->ctx !== null) { Av::ffi()->sws_freeContext($this->ctx); }
        if ($this->pixels !== null) { Mem::free($this->pixels); }
    }
}
