<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * Raw memory through libc, for buffers whose rows are copied around.
 * PHP's FFI has no pointer arithmetic, so offsets are done on integer
 * addresses and turned back into pointers with a cast.
 */
final class Mem
{
    private static FFI $c;
    private static CData $slot;
    private static CData $slotInt;

    private static function c(): FFI
    {
        if (!isset(self::$c)) {
            self::$c = FFI::cdef('void* calloc(size_t, size_t); void free(void*);');
            self::$slot = self::$c->new('void*[1]');
            self::$slotInt = self::$c->cast('unsigned long[1]', self::$slot);
        }
        return self::$c;
    }

    /** Zeroed bytes; free with free(). */
    public static function alloc(int $bytes): CData
    {
        return self::c()->calloc($bytes, 1);
    }

    public static function free(CData $p): void
    {
        self::c()->free($p);
    }

    /** The integer address a pointer CData holds. */
    public static function addr(CData $p): int
    {
        self::c();
        self::$slot[0] = $p;
        return self::$slotInt[0];
    }

    /** Copies $n bytes between integer addresses. */
    public static function copy(int $dst, int $src, int $n): void
    {
        $c = self::c();
        FFI::memcpy($c->cast('unsigned char*', $dst), $c->cast('unsigned char*', $src), $n);
    }
}
