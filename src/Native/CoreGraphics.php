<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use MyPHP\UI\Path;
use RuntimeException;

/**
 * Rasterizes paths to 8-bit coverage masks through CoreGraphics, at the
 * display's backing scale, as CoreText does glyphs: the masks go into the
 * glyph atlas, and the GPU draws them in any color.
 */
final class CoreGraphics
{
    private const ALPHA_ONLY = 7;  // kCGImageAlphaOnly
    private const ROUND = 1;       // kCGLineCapRound, kCGLineJoinRound

    private FFI $c;

    public function __construct(public float $scale)
    {
        $this->c = FFI::cdef("
            void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
            void* CGBitmapContextGetData(void*);
            void  CGContextRelease(void*);
            void  CGContextTranslateCTM(void*, double, double);
            void  CGContextScaleCTM(void*, double, double);
            void  CGContextMoveToPoint(void*, double, double);
            void  CGContextAddLineToPoint(void*, double, double);
            void  CGContextAddQuadCurveToPoint(void*, double, double, double, double);
            void  CGContextAddCurveToPoint(void*, double, double, double, double, double, double);
            void  CGContextClosePath(void*);
            void  CGContextFillPath(void*);
            void  CGContextStrokePath(void*);
            void  CGContextSetLineWidth(void*, double);
            void  CGContextSetLineCap(void*, int);
            void  CGContextSetLineJoin(void*, int);
        ", '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
    }

    /**
     * The mask of $path, $w by $h device pixels, its points less ($x0, $y0)
     * points: filled (non-zero winding), or with $width > 0 stroked that
     * many points wide with round joins and caps. Rows top first.
     * @return array{w: int, h: int, rows: \FFI\CData, addr: int}
     */
    public function mask(Path $path, float $width, int $w, int $h, float $x0, float $y0): array
    {
        $c = $this->c; $s = $this->scale;
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w, null, self::ALPHA_ONLY) ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextTranslateCTM($ctx, 0, $h); // CG runs y up: the bitmap's first row in memory is then the top
        $c->CGContextScaleCTM($ctx, $s, -$s);
        $c->CGContextTranslateCTM($ctx, -$x0, -$y0);
        foreach ($path->cmds as $k) {
            match ($k[0]) {
                Path::MOVE => $c->CGContextMoveToPoint($ctx, $k[1], $k[2]),
                Path::LINE => $c->CGContextAddLineToPoint($ctx, $k[1], $k[2]),
                Path::QUAD => $c->CGContextAddQuadCurveToPoint($ctx, $k[1], $k[2], $k[3], $k[4]),
                Path::CUBE => $c->CGContextAddCurveToPoint($ctx, $k[1], $k[2], $k[3], $k[4], $k[5], $k[6]),
                Path::CLOSE => $c->CGContextClosePath($ctx),
            };
        }
        if ($width > 0) {
            $c->CGContextSetLineWidth($ctx, $width);
            $c->CGContextSetLineCap($ctx, self::ROUND);
            $c->CGContextSetLineJoin($ctx, self::ROUND);
            $c->CGContextStrokePath($ctx);
        } else {
            $c->CGContextFillPath($ctx);
        }
        $rows = Mem::alloc($w * $h);
        $addr = Mem::addr($rows);
        Mem::copy($addr, Mem::addr($c->CGBitmapContextGetData($ctx)), $w * $h);
        $c->CGContextRelease($ctx);
        return ['w' => $w, 'h' => $h, 'rows' => $rows, 'addr' => $addr];
    }
}
