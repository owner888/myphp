<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * An ASS drawing's coverage as libass rasterizes it, by Core Graphics: its
 * contours filled by the non-zero rule, or grown by a border all round
 * (filled and stroked twice as wide, joins round: what its outline and
 * shadow are), blurred as a Gaussian (Gaussian), the fill taken out of
 * the grown one where asked (libass's ass_fix_outline), as premultiplied white
 * RGBA (vImage's planes to ARGB), for an image the painter tints.
 *
 * @internal UI\VideoPlayer
 */
final class VectorMask
{
    private static ?FFI $cg = null, $v = null;
    private const ALPHA_ONLY = 7; // kCGImageAlphaOnly

    /**
     * The $w × $h pixels of $cmds (Media\AssRun::drawing's, in pixels, y
     * down) moved by ($dx, $dy), grown by $grow pixels, blurred by $sigma
     * pixels, with $cutOut the fill (not blurred) taken out of it after:
     * premultiplied RGBA, four bytes a pixel, top row first.
     * @param list<list<string|float>> $cmds
     */
    public static function rgba(array $cmds, float $dx, float $dy, int $w, int $h, float $grow, float $sigma, bool $cutOut = false): string
    {
        $cg = self::$cg ??= FFI::cdef('
            void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
            void* CGBitmapContextGetData(void*);
            void CGContextRelease(void*);
            void CGContextTranslateCTM(void*, double, double);
            void CGContextScaleCTM(void*, double, double);
            void CGContextAddPath(void*, void*);
            void CGContextSetLineWidth(void*, double);
            void CGContextSetLineJoin(void*, int);
            void CGContextSetLineCap(void*, int);
            void CGContextDrawPath(void*, int);
            void CGContextSetBlendMode(void*, int);
            void* CGPathCreateMutable(void);
            void CGPathMoveToPoint(void*, void*, double, double);
            void CGPathAddLineToPoint(void*, void*, double, double);
            void CGPathAddCurveToPoint(void*, void*, double, double, double, double, double, double);
            void CGPathCloseSubpath(void*);
            void CGPathRelease(void*);
        ', '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
        $v = self::$v ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            long vImageConvert_Planar8toARGB8888(const vImage_Buffer*, const vImage_Buffer*, const vImage_Buffer*, const vImage_Buffer*, const vImage_Buffer*, unsigned int);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
        $ctx = $cg->CGBitmapContextCreate(null, $w, $h, 8, $w, null, self::ALPHA_ONLY) ?? throw new RuntimeException('CGBitmapContextCreate');
        $cg->CGContextTranslateCTM($ctx, 0, $h); // y down, as the drawing's
        $cg->CGContextScaleCTM($ctx, 1, -1);
        $path = $cg->CGPathCreateMutable();
        foreach ($cmds as $c) {
            match ($c[0]) {
                'm' => $cg->CGPathMoveToPoint($path, null, $c[1] + $dx, $c[2] + $dy),
                'l' => $cg->CGPathAddLineToPoint($path, null, $c[1] + $dx, $c[2] + $dy),
                'b' => $cg->CGPathAddCurveToPoint($path, null, $c[1] + $dx, $c[2] + $dy, $c[3] + $dx, $c[4] + $dy, $c[5] + $dx, $c[6] + $dy),
                'c' => $cg->CGPathCloseSubpath($path),
            };
        }
        $cg->CGContextAddPath($ctx, $path);
        if ($grow > 0) { // grown all round: filled and stroked twice as wide, joins and caps round
            $cg->CGContextSetLineWidth($ctx, 2 * $grow);
            $cg->CGContextSetLineJoin($ctx, 1);
            $cg->CGContextSetLineCap($ctx, 1);
            $cg->CGContextDrawPath($ctx, 3); // kCGPathFillStroke
        } else {
            $cg->CGContextDrawPath($ctx, 0); // kCGPathFill, non-zero
        }
        $mask = Mem::alloc($w * $h);
        $rgba = Mem::alloc(4 * $w * $h);
        try {
            Mem::copy(Mem::addr($mask), Mem::addr($cg->CGBitmapContextGetData($ctx)), $w * $h);
            $cg->CGContextRelease($ctx);
            if ($sigma > 0) { Gaussian::blur(Mem::addr($mask), $w, $h, $sigma); }
            if ($cutOut) { // the fill drawn over it so as to clear it
                $cut = $cg->CGBitmapContextCreate($cg->cast('void*', Mem::addr($mask)), $w, $h, 8, $w, null, self::ALPHA_ONLY) ?? throw new RuntimeException('CGBitmapContextCreate');
                $cg->CGContextTranslateCTM($cut, 0, $h);
                $cg->CGContextScaleCTM($cut, 1, -1);
                $cg->CGContextSetBlendMode($cut, 23); // kCGBlendModeDestinationOut
                $cg->CGContextAddPath($cut, $path);
                $cg->CGContextDrawPath($cut, 0);
                $cg->CGContextRelease($cut);
            }
            $plane = $v->new('vImage_Buffer'); $out = $v->new('vImage_Buffer');
            $plane->data = $mask; $plane->width = $w; $plane->height = $h; $plane->rowBytes = $w;
            $out->data = $rgba; $out->width = $w; $out->height = $h; $out->rowBytes = 4 * $w;
            $p = FFI::addr($plane);
            $err = $v->vImageConvert_Planar8toARGB8888($p, $p, $p, $p, FFI::addr($out), 0); // white, premultiplied: every channel the coverage
            if ($err < 0) {
                throw new RuntimeException("myphp: vImageConvert_Planar8toARGB8888 failed ($err)");
            }
            return Mem::read(Mem::addr($rgba), 4 * $w * $h);
        } finally {
            $cg->CGPathRelease($path);
            Mem::free($mask);
            Mem::free($rgba);
        }
    }
}
