<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use Fiber;
use RuntimeException;

/**
 * Decodes PNG, JPEG, HEIC and whatever else ImageIO reads into RGBA
 * pixels (premultiplied, top row first), upright as the file's
 * orientation says (EXIF, HEIC's), scaled down with Lanczos to fit $max
 * pixels on a side when larger (mygo's DecodeBitmap): off the main
 * thread. An NSOperationQueue's thread makes the CIImage, which reads
 * the file, and renders it into the pixels, which decodes it; the main
 * thread only builds the two NSInvocations and the scaling, a fraction of
 * a millisecond, and no PHP runs elsewhere. In a fiber (App::go) the
 * decoding waits letting the app run; outside one it blocks until done.
 */
final class CoreImage
{
    private static ?FFI $c = null;
    private static $context = null, $queue = null, $colorSpace = null, $orientKey = null;
    private static int $format = 0;

    private static function boot(): void
    {
        if (self::$c !== null) {
            return;
        }
        ObjC::boot(['Foundation', 'CoreImage']);
        self::$c = FFI::cdef('
            extern int kCIFormatRGBA8;
            extern void* kCIImageApplyOrientationProperty;
            void* CGColorSpaceCreateDeviceRGB(void);
        ', '/System/Library/Frameworks/CoreImage.framework/CoreImage');
        self::$format = self::$c->kCIFormatRGBA8;
        self::$orientKey = ObjC::import(self::$c->kCIImageApplyOrientationProperty);
        self::$colorSpace = ObjC::addr(self::$c->CGColorSpaceCreateDeviceRGB()); // passed to render by its address, kept for the process
        self::$context = ObjC::msg('p:', ObjC::msg('p:p', ObjC::cls('CIContext'), 'contextWithOptions:', null), 'retain');
        self::$queue = ObjC::msg('p:', ObjC::alloc('NSOperationQueue'), 'init');
    }

    /**
     * @return array{w: int, h: int, pix: \FFI\CData, addr: int} w*h*4 bytes, Mem-allocated (free with Mem::free)
     */
    public static function decode(string $bytes, int $max): array
    {
        self::boot();
        // On the queue: the CIImage of the bytes, upright.
        $pool = ObjC::msg('p:', ObjC::alloc('NSAutoreleasePool'), 'init'); // drained before each wait: a fiber's wait is no scope of the main thread's pools
        $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $bytes, strlen($bytes));
        $options = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', true), self::$orientKey);
        $made = self::run(ObjC::cls('CIImage'), 'imageWithData:options:', [2 => ['p', $data], 3 => ['p', $options]]);
        ObjC::msg('v:', $pool, 'drain');
        self::wait($made);
        $pool = ObjC::msg('p:', ObjC::alloc('NSAutoreleasePool'), 'init');
        $image = ObjC::msg('p:', $made, 'result');
        if ($image === null) {
            ObjC::msg('v:', $made, 'release');
            ObjC::msg('v:', $pool, 'drain');
            throw new RuntimeException('not an image ImageIO can read');
        }
        ObjC::msg('p:', ObjC::msg('p:', $image, 'retain'), 'autorelease'); // past the operation, which holds it, until the pool drains
        ObjC::msg('v:', $made, 'release');
        // Here: scaled to whole pixels, the sides rounded, as Lanczos' scale (of the height) and aspect ratio say.
        $e = ObjC::msg('r:', $image, 'extent');
        $k = min(1.0, $max / max($e->w, $e->h));
        $w = max(1, (int)round($e->w * $k)); $h = max(1, (int)round($e->h * $k));
        if ($k < 1) {
            $f = ObjC::msg('p:p', ObjC::cls('CIFilter'), 'filterWithName:', ObjC::str('CILanczosScaleTransform'));
            ObjC::msg('v:pp', $f, 'setValue:forKey:', $image, ObjC::str('inputImage'));
            ObjC::msg('v:pp', $f, 'setValue:forKey:', ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $h / $e->h), ObjC::str('inputScale'));
            ObjC::msg('v:pp', $f, 'setValue:forKey:', ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', ($w / $e->w) / ($h / $e->h)), ObjC::str('inputAspectRatio'));
            $image = ObjC::msg('p:', $f, 'outputImage');
            $e = ObjC::msg('r:', $image, 'extent');
        }
        // On the queue: rendered into the pixels, which decodes the image; CoreImage's first row is the top.
        $pix = Mem::alloc($w * $h * 4);
        $addr = Mem::addr($pix);
        $rendered = self::run(self::$context, 'render:toBitmap:rowBytes:bounds:format:colorSpace:', [
            2 => ['p', $image], 3 => ['L', $addr], 4 => ['l', $w * 4], 5 => ['r', ObjC::rect($e->x, $e->y, $w, $h)], 6 => ['i', self::$format], 7 => ['L', self::$colorSpace],
        ]);
        ObjC::msg('v:', $pool, 'drain');
        self::wait($rendered);
        ObjC::msg('v:', $rendered, 'release');
        return ['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => $addr];
    }

    /**
     * Sends $sel to $target with $args on the queue: an NSInvocationOperation
     * of an NSInvocation that keeps the objects among them. $args are by
     * index (2 the first), each [letter, value]: 'p' an object, 'L' an
     * address, 'l' and 'i' integers, 'r' a CGRect. Returns the operation,
     * owned (release it).
     *
     * @param array<int, array{0: string, 1: mixed}> $args
     */
    private static function run($target, string $sel, array $args)
    {
        $c = ObjC::$c;
        $sig = ObjC::msg('p:p', $target, 'methodSignatureForSelector:', ObjC::sel($sel));
        $inv = ObjC::msg('p:p', ObjC::cls('NSInvocation'), 'invocationWithMethodSignature:', $sig);
        ObjC::msg('v:p', $inv, 'setTarget:', $target);
        ObjC::msg('v:p', $inv, 'setSelector:', ObjC::sel($sel));
        foreach ($args as $i => [$type, $v]) {
            $slot = match ($type) { 'p', 'L' => $c->new('unsigned long'), 'l' => $c->new('long'), 'i' => $c->new('int'), 'r' => $v }; // a pointer as its address: a void* CData takes no cdata
            if ($type !== 'r') {
                $slot->cdata = $type === 'p' ? ObjC::addr($v) : $v;
            }
            ObjC::msg('v:pl', $inv, 'setArgument:atIndex:', FFI::addr($slot), $i); // copied by the invocation
        }
        ObjC::msg('v:', $inv, 'retainArguments'); // the objects, and the result once there
        $op = ObjC::msg('p:p', ObjC::alloc('NSInvocationOperation'), 'initWithInvocation:', $inv);
        ObjC::msg('v:p', self::$queue, 'addOperation:', $op);
        return $op;
    }

    /** Waits for $op: in a fiber, looking every few milliseconds while the app runs; outside one, blocking. */
    private static function wait($op): void
    {
        if (Fiber::getCurrent() !== null) {
            Fibers::until(fn(): bool => ObjC::msg('b:', $op, 'isFinished'), 0.004, 0.016);
        } else {
            ObjC::msg('v:', $op, 'waitUntilFinished');
        }
    }
}
