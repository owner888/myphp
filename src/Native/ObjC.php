<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * Objective-C runtime bridge over ext-ffi. macOS on Apple Silicon only.
 *
 * One FFI instance holds every type, so structs (NSRect) flow between calls.
 * objc_msgSend is cast per signature from its dlsym address and never declared
 * variadic: on arm64 variadic arguments go to the stack while objc_msgSend
 * expects them in registers.
 *
 * Signatures are strings "ret:args" with one letter per type, see MAP:
 *   msg('p:rLLb', $win, 'initWithContentRect:styleMask:backing:defer:', $rect, 15, 2, false)
 */
final class ObjC
{
    public static FFI $c;
    private static array $send = [];
    private static array $sel = [];
    private static array $cls = [];
    /** Holders of PHP callbacks, kept alive for the life of the process. */
    private static array $keep = [];
    private static $msgSend;
    /** One void* slot and an integer view of the same bytes, for addr(). */
    private static $slot;
    private static $slotInt;

    private const MAP = [
        'p' => 'void*', 'd' => 'double', 'f' => 'float', 'l' => 'long', 'L' => 'unsigned long',
        'i' => 'int', 'b' => 'bool', 'r' => 'NSRect', 'z' => 'CGSize', 'k' => 'MTLClearColor', 'R' => 'MTLRegion',
        's' => 'const char*', 'v' => 'void',
    ];

    public static function boot(array $frameworks = ['Foundation', 'AppKit']): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
            // Intel Macs return NSRect through objc_msgSend_stret, which this bridge does not map.
            throw new RuntimeException('MyPHP\Native\ObjC needs macOS on Apple Silicon');
        }
        self::$c = FFI::cdef("
            typedef struct { double x, y, w, h; } NSRect;
            typedef struct { double w, h; } CGSize;
            typedef struct { double r, g, b, a; } MTLClearColor;
            typedef struct { unsigned long x, y, z, w, h, d; } MTLRegion;
            void* dlopen(const char*, int);
            void* dlsym(void*, const char*);
            void* objc_getClass(const char*);
            void* sel_registerName(const char*);
            void* objc_allocateClassPair(void*, const char*, size_t);
            void  objc_registerClassPair(void*);
            bool  class_addMethod(void*, void*, void*, const char*);
        ", '/usr/lib/libobjc.A.dylib');
        foreach ($frameworks as $f) {
            if (self::$c->dlopen("/System/Library/Frameworks/$f.framework/$f", 2) === null) {
                throw new RuntimeException("dlopen $f failed");
            }
        }
        self::$msgSend = self::$c->dlsym(self::$c->dlopen('/usr/lib/libobjc.A.dylib', 2), 'objc_msgSend');
        self::$slot = self::$c->new('void*[1]');
        self::$slotInt = self::$c->cast('unsigned long[1]', self::$slot);
    }

    public static function cls(string $name)
    {
        return self::$cls[$name] ??= self::$c->objc_getClass($name)
            ?? throw new RuntimeException("no Objective-C class $name");
    }

    public static function sel(string $name)
    {
        return self::$sel[$name] ??= self::$c->sel_registerName($name);
    }

    public static function msg(string $sig, $recv, string $sel, ...$args)
    {
        $fn = self::$send[$sig] ??= self::sender($sig);
        return $fn($recv, self::sel($sel), ...$args);
    }

    private static function sender(string $sig)
    {
        [$ret, $args] = explode(':', $sig);
        $params = ['void*', 'void*'];
        foreach (str_split($args) as $ch) {
            $params[] = self::MAP[$ch];
        }
        return self::$c->cast(self::MAP[$ret] . '(*)(' . implode(',', $params) . ')', self::$msgSend);
    }

    public static function alloc(string $cls)
    {
        return self::msg('p:', self::cls($cls), 'alloc');
    }

    public static function rect(float $x, float $y, float $w, float $h)
    {
        $r = self::$c->new('NSRect');
        $r->x = $x; $r->y = $y; $r->w = $w; $r->h = $h;
        return $r;
    }

    /**
     * The integer value of a pointer, for use as an array key.
     * Not FFI::cast('unsigned long', $p): casting a pointer CData to a scalar
     * dereferences it (for an object that reads the isa, whose inline
     * refcount bits change). Storing the pointer in a void* slot and reading
     * the slot's bytes as an integer gives the pointer itself.
     */
    public static function addr($p): int
    {
        if ($p === null) {
            return 0;
        }
        self::$slot[0] = $p;
        return self::$slotInt[0];
    }

    /**
     * A fresh void* CData in this bridge's scope holding the same address.
     * Pointers returned by another FFI instance must pass through here: a
     * CData keeps a type pointer into the instance that made it, and such
     * pointers turned invalid after the creating call returned (PHP 8.5.11).
     */
    public static function import($p)
    {
        return self::$c->cast('void*', self::addr($p));
    }

    public static function str(string $s)
    {
        return self::msg('p:s', self::cls('NSString'), 'stringWithUTF8String:', $s);
    }

    public static function phpStr($nsString): string
    {
        $p = self::msg('p:', $nsString, 'UTF8String');
        return $p === null ? '' : FFI::string(self::$c->cast('char*', $p));
    }

    /**
     * Defines an Objective-C class whose methods are PHP closures.
     * $methods: selector => [objc type encoding, C signature such as 'v(ppr)', closure].
     * Closures receive (self, _cmd, ...args) as CData and run on the main thread only.
     */
    public static function defineClass(string $name, string $super, array $methods)
    {
        $cls = self::$c->objc_allocateClassPair(self::cls($super), $name, 0);
        foreach ($methods as $sel => [$enc, $csig, $fn]) {
            preg_match('/^(\w)\((\w*)\)$/', $csig, $m);
            $params = [];
            foreach (str_split($m[2]) as $ch) {
                $params[] = self::MAP[$ch];
            }
            $type = self::MAP[$m[1]] . '(*)(' . implode(',', $params) . ')';
            // A struct holding the function pointer keeps the libffi closure alive.
            $holder = self::$c->new('struct { ' . str_replace('(*)', '(*fn)', $type) . '; }');
            $holder->fn = $fn;
            self::$keep[] = $holder;
            self::$c->class_addMethod($cls, self::sel($sel), self::$c->cast('void*', $holder->fn), $enc);
        }
        self::$c->objc_registerClassPair($cls);
        return self::$cls[$name] = $cls;
    }
}
