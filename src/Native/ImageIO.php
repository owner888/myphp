<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Decodes PNG, JPEG, HEIC, ICNS and whatever else ImageIO reads into
 * RGBA pixels (premultiplied, top row first), scaled down to fit $max
 * pixels on a side when larger, through a CGBitmapContext. A JPEG photo
 * comes upright, as its camera's EXIF orientation says (mygo's
 * DecodeBitmap).
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
                typedef struct { double a, b, c, d, tx, ty; } CGAffineTransform;
                void  CGContextConcatCTM(void*, CGAffineTransform);
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
        $dw = max(1, (int)round($iw * $k)); $dh = max(1, (int)round($ih * $k)); // the image as stored, scaled
        $o = self::exifOrientation($bytes);
        [$w, $h] = $o >= 5 ? [$dh, $dw] : [$dw, $dh]; // turned a quarter: the sides swap
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$colorSpace, self::RGBA_PREMULTIPLIED_BIG)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextSetInterpolationQuality($ctx, 4); // high
        if ($o > 1) {
            $m = $c->new('CGAffineTransform');
            [$m->a, $m->b, $m->c, $m->d, $m->tx, $m->ty] = self::orientTransform($o, $dw, $dh);
            $c->CGContextConcatCTM($ctx, $m);
        }
        $rect = $c->new('CGRect');
        $rect->size->w = $dw; $rect->size->h = $dh;
        $c->CGContextDrawImage($ctx, $rect, $image);
        $c->CGImageRelease($image);
        // CG draws y-up, but the bitmap's first row in memory is the image's top.
        $pix = Mem::alloc($w * $h * 4);
        $addr = Mem::addr($pix);
        Mem::copy($addr, Mem::addr($c->CGBitmapContextGetData($ctx)), $w * $h * 4);
        $c->CGContextRelease($ctx);
        return ['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => $addr];
    }

    /** The orientation of a JPEG's EXIF data, 1 to 8, or 0 without one (mygo's exifOrientation). */
    public static function exifOrientation(string $jpg): int
    {
        $n = strlen($jpg);
        if ($n < 4 || $jpg[0] !== "\xff" || $jpg[1] !== "\xd8") {
            return 0;
        }
        for ($i = 2; $i + 4 <= $n;) {
            if ($jpg[$i] !== "\xff") {
                return 0;
            }
            $marker = ord($jpg[$i + 1]); $size = unpack('n', $jpg, $i + 2)[1];
            if ($marker === 0xda || $size < 2 || $i + 2 + $size > $n) { // the image data starts
                return 0;
            }
            $seg = substr($jpg, $i + 4, $size - 2);
            if ($marker === 0xe1 && strlen($seg) > 14 && str_starts_with($seg, "Exif\0\0")) {
                return self::tiffOrientation(substr($seg, 6));
            }
            $i += 2 + $size;
        }
        return 0;
    }

    /** The Orientation tag of the first IFD of TIFF data (mygo's tiffOrientation). */
    private static function tiffOrientation(string $t): int
    {
        $order = substr($t, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 0;
        }
        [$u16, $u32] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $n = strlen($t);
        $ifd = unpack($u32, $t, 4)[1];
        if ($ifd < 8 || $ifd + 2 > $n) {
            return 0;
        }
        for ($e = $ifd + 2, $k = unpack($u16, $t, $ifd)[1]; $e + 12 <= $n && $k > 0; $e += 12, $k--) {
            if (unpack($u16, $t, $e)[1] === 0x0112 && unpack($u16, $t, $e + 2)[1] === 3) { // Orientation, a SHORT
                $o = unpack($u16, $t, $e + 8)[1];
                return $o >= 1 && $o <= 8 ? $o : 0;
            }
        }
        return 0;
    }

    /**
     * The transform [a, b, c, d, tx, ty] that draws an image stored $w by
     * $h as EXIF orientation $o says it is to show, in CoreGraphics'
     * y-up space, the image drawn at (0, 0, $w, $h): mirrored, turned
     * half, or a quarter either way (mygo's orient, by pixel).
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public static function orientTransform(int $o, float $w, float $h): array
    {
        return match ($o) {
            2 => [-1.0, 0.0, 0.0, 1.0, $w, 0.0],
            3 => [-1.0, 0.0, 0.0, -1.0, $w, $h],
            4 => [1.0, 0.0, 0.0, -1.0, 0.0, $h],
            5 => [0.0, -1.0, -1.0, 0.0, $h, $w],
            6 => [0.0, -1.0, 1.0, 0.0, 0.0, $w],
            7 => [0.0, 1.0, 1.0, 0.0, 0.0, 0.0],
            8 => [0.0, 1.0, -1.0, 0.0, $h, 0.0],
            default => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        };
    }
}
