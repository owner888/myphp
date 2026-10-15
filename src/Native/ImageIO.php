<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Decodes PNG, JPEG, HEIC, ICNS and whatever else ImageIO reads into
 * RGBA pixels (premultiplied, top row first), scaled down to fit $max
 * pixels on a side when larger, through a CGBitmapContext.
 */
final class ImageIO
{
    private const RGBA_PREMULTIPLIED_BIG = 1 | (4 << 12); // kCGImageAlphaPremultipliedLast | kCGBitmapByteOrder32Big: R G B A in memory
    private static ?FFI $c = null;
    private static $colorSpace = null;

    private static function c(): FFI
    {
        if (self::$c === null) {
            self::$c = FFI::cdef('
                typedef struct { double x, y; } CGPoint;
                typedef struct { double w, h; } CGSize;
                typedef struct { CGPoint origin; CGSize size; } CGRect;
                void* dlopen(const char*, int);
                void* CFDataCreate(void*, const char*, long);
                void  CFRelease(void*);
                void* CGImageSourceCreateWithData(void*, void*);
                void* CGImageSourceCreateImageAtIndex(void*, unsigned long, void*);
                unsigned long CGImageSourceGetCount(void*);
                unsigned long CGImageGetWidth(void*);
                unsigned long CGImageGetHeight(void*);
                void  CGImageRelease(void*);
                void* CGColorSpaceCreateDeviceRGB(void);
                void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
                void* CGBitmapContextGetData(void*);
                void  CGContextDrawImage(void*, CGRect, void*);
                void  CGContextSetInterpolationQuality(void*, int);
                void  CGContextRelease(void*);
            ', '/System/Library/Frameworks/ImageIO.framework/ImageIO');
            self::$c->dlopen('/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics', 2);
            self::$colorSpace = self::$c->CGColorSpaceCreateDeviceRGB();
        }
        return self::$c;
    }

    /**
     * @return array{w: int, h: int, pix: CData, addr: int} w*h*4 bytes, Mem-allocated (free with Mem::free)
     */
    public static function decode(string $bytes, int $max = 1024): array
    {
        $c = self::c();
        $data = $c->CFDataCreate(null, $bytes, strlen($bytes));
        $source = $c->CGImageSourceCreateWithData($data, null);
        $c->CFRelease($data);
        if ($source === null) {
            throw new RuntimeException('not an image ImageIO can read');
        }
        // A file may hold several images (an ICNS its sizes): take the largest.
        $image = null; $iw = 0; $ih = 0;
        for ($i = 0, $n = $c->CGImageSourceGetCount($source); $i < $n; $i++) {
            $candidate = $c->CGImageSourceCreateImageAtIndex($source, $i, null);
            if ($candidate === null) {
                continue;
            }
            $cw = $c->CGImageGetWidth($candidate); $chh = $c->CGImageGetHeight($candidate);
            if ($cw * $chh > $iw * $ih) {
                if ($image !== null) { $c->CGImageRelease($image); }
                $image = $candidate; $iw = $cw; $ih = $chh;
            } else {
                $c->CGImageRelease($candidate);
            }
        }
        $c->CFRelease($source);
        if ($image === null) {
            throw new RuntimeException('the image cannot be decoded');
        }
        $k = min(1.0, $max / max($iw, $ih));
        $w = max(1, (int)round($iw * $k)); $h = max(1, (int)round($ih * $k));
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$colorSpace, self::RGBA_PREMULTIPLIED_BIG)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextSetInterpolationQuality($ctx, 4); // high
        $rect = $c->new('CGRect');
        $rect->size->w = $w; $rect->size->h = $h;
        $c->CGContextDrawImage($ctx, $rect, $image);
        $c->CGImageRelease($image);
        // CG draws y-up, but the bitmap's first row in memory is the image's top.
        $pix = Mem::alloc($w * $h * 4);
        $addr = Mem::addr($pix);
        Mem::copy($addr, Mem::addr($c->CGBitmapContextGetData($ctx)), $w * $h * 4);
        $c->CGContextRelease($ctx);
        return ['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => $addr];
    }
}
