<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * A mask blurred as a Gaussian of a sigma in pixels (libass's
 * ass_gaussian_blur, which ASS's \blur and \be come to): Accelerate's
 * vImageConvolve_Planar8 across, then down, its kernel 3 sigmas each way,
 * what lies past the mask's edges clear.
 *
 * @internal CoreText, VectorMask
 */
final class Gaussian
{
    private static ?FFI $v = null;
    /** kvImageBackgroundColorFill: past the edges, the background (0). */
    private const BACKGROUND = 4;

    /** Blurs the $w × $h mask of a byte a pixel at address $addr, rows packed, in place, by $sigma pixels. */
    public static function blur(int $addr, int $w, int $h, float $sigma): void
    {
        if ($sigma <= 0 || $w <= 0 || $h <= 0) {
            return;
        }
        $v = self::$v ??= FFI::cdef('
            typedef struct { void* data; unsigned long height, width; size_t rowBytes; } vImage_Buffer;
            long vImageConvolve_Planar8(const vImage_Buffer*, const vImage_Buffer*, void*, unsigned long, unsigned long, const short*, unsigned int, unsigned int, int, unsigned char, unsigned int);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
        $r = max(1, (int)ceil(3 * $sigma));
        $n = 2 * $r + 1;
        $kernel = $v->new("short[$n]");
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $kernel[$i] = $q = (int)round(4096 * exp(-(($i - $r) ** 2) / (2 * $sigma * $sigma)));
            $sum += $q;
        }
        $tmp = Mem::alloc($w * $h);
        try {
            $src = $v->new('vImage_Buffer'); $dst = $v->new('vImage_Buffer');
            $src->data = $v->cast('void*', $addr); $dst->data = $tmp;
            $src->width = $dst->width = $w; $src->height = $dst->height = $h; $src->rowBytes = $dst->rowBytes = $w;
            $across = $v->vImageConvolve_Planar8(FFI::addr($src), FFI::addr($dst), null, 0, 0, $kernel, 1, $n, $sum, 0, self::BACKGROUND);
            $down = $across < 0 ? $across : $v->vImageConvolve_Planar8(FFI::addr($dst), FFI::addr($src), null, 0, 0, $kernel, $n, 1, $sum, 0, self::BACKGROUND);
            if ($down < 0) {
                throw new RuntimeException("myphp: vImageConvolve_Planar8 failed ($down)");
            }
        } finally {
            Mem::free($tmp);
        }
    }
}
