<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Decodes a PNG or an ICNS, the largest of its images, into RGBA pixels
 * (premultiplied, top row first), scaled down to fit $max pixels on a
 * side when larger, through a CGBitmapContext, on the calling thread:
 * the icons myphp build makes (Build\Icns), and a video player's cached
 * thumbnails; and encodes RGBA as a JPEG (the thumbnails' cache), an
 * image as another type ImageIO writes, and a frame with what a window
 * drew over it (a player's screenshots, their subtitles). The
 * UI's images decode off the main thread, through CoreImage.
 */
final class ImageIO
{
    private const RGBA_PREMULTIPLIED_BIG = 1 | (4 << 12); // kCGImageAlphaPremultipliedLast | kCGBitmapByteOrder32Big: R G B A in memory
    private const BGRA_PREMULTIPLIED_LITTLE = 2 | (2 << 12); // kCGImageAlphaPremultipliedFirst | kCGBitmapByteOrder32Little: B G R A in memory, Metal's BGRA8
    private static ?FFI $c = null;
    private static $colorSpace = null;

    private static function c(): FFI
    {
        if (self::$c === null) {
            // CFDataGetBytePtr's bytes a void*: a const char* return arrives as a PHP string, cut at its first NUL.
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
                void* CGBitmapContextCreateImage(void*);
                void* CFDataCreateMutable(void*, long);
                long  CFDataGetLength(void*);
                void* CFDataGetBytePtr(void*);
                void* CGImageDestinationCreateWithData(void*, void*, size_t, void*);
                void  CGImageDestinationAddImage(void*, void*, void*);
                bool  CGImageDestinationFinalize(void*);
                extern void* kCGImageDestinationLossyCompressionQuality;
                void* CGImageDestinationCopyTypeIdentifiers(void);
                void* CGImageCreateWithImageInRect(void*, CGRect);
                void* CGImageSourceCopyPropertiesAtIndex(void*, unsigned long, void*);
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

    /** The image source of an animated image's $bytes, and how many frames it has (UI\GifDecoder); release it with closeFrames(). @return array{0: CData, 1: int} */
    public static function openFrames(string $bytes): array
    {
        $c = self::c();
        $data = $c->CFDataCreate(null, $bytes, strlen($bytes));
        $source = $c->CGImageSourceCreateWithData($data, null);
        $c->CFRelease($data);
        if ($source === null) {
            throw new RuntimeException('not an image ImageIO can read');
        }
        return [$source, $c->CGImageSourceGetCount($source)];
    }

    /**
     * Frame $i of $source, as ImageIO composes it over what the frames
     * before it left, as its disposal says, and how long it shows in
     * seconds by its GIF properties (the unclamped delay, else the delay;
     * 0 for none): [w, h, premultiplied RGBA, top row first, seconds].
     * @return array{0: int, 1: int, 2: string, 3: float}
     */
    public static function frame(CData $source, int $i): array
    {
        $c = self::c();
        $image = $c->CGImageSourceCreateImageAtIndex($source, $i, null) ?? throw new RuntimeException("frame $i cannot be decoded");
        $w = $c->CGImageGetWidth($image); $h = $c->CGImageGetHeight($image);
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$colorSpace, self::RGBA_PREMULTIPLIED_BIG)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $rect = $c->new('CGRect');
        $rect->size->w = $w; $rect->size->h = $h;
        $c->CGContextDrawImage($ctx, $rect, $image);
        $c->CGImageRelease($image);
        $rgba = FFI::string($c->cast('char*', $c->CGBitmapContextGetData($ctx)), $w * $h * 4);
        $c->CGContextRelease($ctx);
        $delay = 0.0;
        if (($props = $c->CGImageSourceCopyPropertiesAtIndex($source, $i, null)) !== null) {
            ObjC::boot(['Foundation']);
            $gif = ObjC::msg('p:p', ObjC::import($props), 'objectForKey:', ObjC::str('{GIF}')); // kCGImagePropertyGIFDictionary
            foreach (['UnclampedDelayTime', 'DelayTime'] as $key) { // kCGImagePropertyGIFUnclampedDelayTime, kCGImagePropertyGIFDelayTime
                $v = $gif === null ? null : ObjC::msg('p:p', $gif, 'objectForKey:', ObjC::str($key));
                if ($v !== null && ($delay = ObjC::msg('d:', $v, 'doubleValue')) > 0) { break; }
            }
            $c->CFRelease($props);
        }
        return [$w, $h, $rgba, $delay];
    }

    public static function closeFrames(CData $source): void
    {
        self::c()->CFRelease($source);
    }

    /** $w by $h pixels $rgba (premultiplied, top row first, opaque) as a JPEG of $quality, 0 to 1. */
    public static function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        $c = self::c();
        ObjC::boot(['Foundation']);
        if (strlen($rgba) !== $w * $h * 4) {
            throw new RuntimeException("myphp: $w by $h pixels are not " . strlen($rgba) . ' bytes');
        }
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$colorSpace, self::RGBA_PREMULTIPLIED_BIG)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        FFI::memcpy($c->cast('char*', $c->CGBitmapContextGetData($ctx)), $rgba, $w * $h * 4); // its first row the top, as decode() reads one
        $image = $c->CGBitmapContextCreateImage($ctx);
        $c->CGContextRelease($ctx);
        $data = $c->CFDataCreateMutable(null, 0);
        $dest = $c->CGImageDestinationCreateWithData($data, $c->cast('void*', ObjC::addr(ObjC::str('public.jpeg'))), 1, null);
        $options = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $quality), ObjC::import($c->kCGImageDestinationLossyCompressionQuality));
        $c->CGImageDestinationAddImage($dest, $image, $c->cast('void*', ObjC::addr($options)));
        $ok = $c->CGImageDestinationFinalize($dest);
        $out = $ok ? FFI::string($c->CFDataGetBytePtr($data), $c->CFDataGetLength($data)) : '';
        $c->CFRelease($dest); $c->CFRelease($data); $c->CGImageRelease($image);
        if (!$ok) {
            throw new RuntimeException('myphp: the JPEG could not be made');
        }
        return $out;
    }

    /** The types of image ImageIO writes on this system, by their UTIs (public.png, public.jpeg, org.webmproject.webp…). @return list<string> */
    public static function writable(): array
    {
        $c = self::c();
        ObjC::boot(['Foundation']);
        $types = ObjC::import($c->CGImageDestinationCopyTypeIdentifiers());
        $out = [];
        for ($i = 0, $n = ObjC::msg('L:', $types, 'count'); $i < $n; $i++) {
            $out[] = ObjC::phpStr(ObjC::msg('p:L', $types, 'objectAtIndex:', $i));
        }
        $c->CFRelease($c->cast('void*', ObjC::addr($types)));
        return $out;
    }

    /** Image $bytes (a PNG, say) as an image of type $uti (writable()'s), of $quality where it is lossy. */
    public static function convert(string $bytes, string $uti, float $quality = 0.9): string
    {
        $c = self::c();
        ObjC::boot(['Foundation']);
        $in = $c->CFDataCreate(null, $bytes, strlen($bytes));
        $src = $c->CGImageSourceCreateWithData($in, null);
        $image = $src === null ? null : $c->CGImageSourceCreateImageAtIndex($src, 0, null);
        if ($image === null) {
            if ($src !== null) { $c->CFRelease($src); }
            $c->CFRelease($in);
            throw new RuntimeException('myphp: no image to convert');
        }
        $data = $c->CFDataCreateMutable(null, 0);
        $dest = $c->CGImageDestinationCreateWithData($data, $c->cast('void*', ObjC::addr(ObjC::str($uti))), 1, null);
        $ok = false;
        if ($dest !== null) {
            $options = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $quality), ObjC::import($c->kCGImageDestinationLossyCompressionQuality));
            $c->CGImageDestinationAddImage($dest, $image, $c->cast('void*', ObjC::addr($options)));
            $ok = $c->CGImageDestinationFinalize($dest);
        }
        $out = $ok ? FFI::string($c->CFDataGetBytePtr($data), $c->CFDataGetLength($data)) : '';
        if ($dest !== null) { $c->CFRelease($dest); }
        $c->CFRelease($data); $c->CGImageRelease($image); $c->CFRelease($src); $c->CFRelease($in);
        if (!$ok) {
            throw new RuntimeException("myphp: an image of type $uti could not be made");
        }
        return $out;
    }

    /**
     * The image $png (a video's frame) with the part $crop ([x, y, w, h],
     * in pixels from the top left) of $bw by $bh pixels $bgra (premultiplied,
     * the bytes B G R A, top row first: a window's drawing, Metal's) laid
     * over it, scaled to its size: a PNG of the frame's size.
     * @param array{0: int, 1: int, 2: int, 3: int} $crop
     */
    public static function overlay(string $png, string $bgra, int $bw, int $bh, array $crop): string
    {
        $c = self::c();
        ObjC::boot(['Foundation']);
        if (strlen($bgra) !== $bw * $bh * 4) {
            throw new RuntimeException("myphp: $bw by $bh pixels are not " . strlen($bgra) . ' bytes');
        }
        $in = $c->CFDataCreate(null, $png, strlen($png));
        $src = $c->CGImageSourceCreateWithData($in, null);
        $frame = $src === null ? null : $c->CGImageSourceCreateImageAtIndex($src, 0, null);
        if ($src !== null) { $c->CFRelease($src); }
        $c->CFRelease($in);
        if ($frame === null) {
            throw new RuntimeException('myphp: no frame to lay the drawing over');
        }
        $w = (int)$c->CGImageGetWidth($frame); $h = (int)$c->CGImageGetHeight($frame);
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$colorSpace, self::RGBA_PREMULTIPLIED_BIG)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextSetInterpolationQuality($ctx, 4); // high
        $all = $c->new('CGRect');
        $all->size->w = $w; $all->size->h = $h;
        $c->CGContextDrawImage($ctx, $all, $frame);
        $c->CGImageRelease($frame);
        $over = $c->CGBitmapContextCreate(null, $bw, $bh, 8, $bw * 4, self::$colorSpace, self::BGRA_PREMULTIPLIED_LITTLE)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        FFI::memcpy($c->cast('char*', $c->CGBitmapContextGetData($over)), $bgra, $bw * $bh * 4);
        $drawing = $c->CGBitmapContextCreateImage($over);
        $c->CGContextRelease($over);
        $part = $c->new('CGRect'); // an image's rect: from its top left
        [$part->origin->x, $part->origin->y, $part->size->w, $part->size->h] = $crop;
        $piece = $c->CGImageCreateWithImageInRect($drawing, $part);
        $c->CGImageRelease($drawing);
        if ($piece !== null) { // none where the crop missed the drawing
            $c->CGContextDrawImage($ctx, $all, $piece);
            $c->CGImageRelease($piece);
        }
        $image = $c->CGBitmapContextCreateImage($ctx);
        $c->CGContextRelease($ctx);
        $data = $c->CFDataCreateMutable(null, 0);
        $dest = $c->CGImageDestinationCreateWithData($data, $c->cast('void*', ObjC::addr(ObjC::str('public.png'))), 1, null);
        $c->CGImageDestinationAddImage($dest, $image, null);
        $ok = $c->CGImageDestinationFinalize($dest);
        $out = $ok ? FFI::string($c->CFDataGetBytePtr($data), $c->CFDataGetLength($data)) : '';
        $c->CFRelease($dest); $c->CFRelease($data); $c->CGImageRelease($image);
        if (!$ok) {
            throw new RuntimeException('myphp: the PNG could not be made');
        }
        return $out;
    }
}
