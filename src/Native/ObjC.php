<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
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
    private static $msgSend, $msgSendSuper;
    private static array $sendSuper = [];
    private static $globalBlockIsa;
    /** @var array<int, array> live blocks by address: literal, descriptor, holder, signature */
    private static array $blocks = [];
    /** @var list<int> once-blocks that fired and can go at the next block() */
    private static array $spentBlocks = [];
    /** One void* slot and an integer view of the same bytes, for addr(). */
    private static $slot;
    private static $slotInt;

    /** Objective-C type encodings of the MAP letters, for block signatures. */
    private const ENCODING = [
        'p' => '@', 'd' => 'd', 'f' => 'f', 'l' => 'q', 'L' => 'Q', 'i' => 'i', 'S' => 'S', 'b' => 'B',
        's' => '*', 'v' => 'v', 'r' => '{CGRect={CGPoint=dd}{CGSize=dd}}', 'q' => '{CGPoint=dd}', 'z' => '{CGSize=dd}',
        'k' => '{MTLClearColor=dddd}', 'R' => '{MTLRegion={MTLOrigin=QQQ}{MTLSize=QQQ}}', 'n' => '{_NSRange=QQ}',
        'o' => '{MTLOrigin=QQQ}', 'Z' => '{MTLSize=QQQ}',
    ];

    private const MAP = [
        'p' => 'void*', 'd' => 'double', 'f' => 'float', 'l' => 'long', 'L' => 'unsigned long',
        'i' => 'int', 'S' => 'unsigned short', 'b' => 'bool', 'r' => 'NSRect', 'q' => 'NSPoint', 'z' => 'CGSize',
        'k' => 'MTLClearColor', 'R' => 'MTLRegion', 'n' => 'NSRange', 'o' => 'MTLOrigin', 'Z' => 'MTLSize',
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
            typedef struct { double x, y; } NSPoint;
            typedef struct { double w, h; } CGSize;
            typedef struct { double r, g, b, a; } MTLClearColor;
            typedef struct { unsigned long x, y, z, w, h, d; } MTLRegion;
            typedef struct { unsigned long x, y, z; } MTLOrigin;
            typedef struct { unsigned long w, h, d; } MTLSize;
            typedef struct { unsigned long location, length; } NSRange;
            typedef struct { void* isa; int flags; int reserved; void* invoke; void* descriptor; } BlockLiteral;
            typedef struct { unsigned long reserved, size; const char* signature; } BlockDescriptor;
            typedef struct { void* receiver; void* super_class; } objc_super;
            void* dlopen(const char*, int);
            void* dlsym(void*, const char*);
            void* objc_getClass(const char*);
            void* sel_registerName(const char*);
            const char* sel_getName(void*);
            void* objc_allocateClassPair(void*, const char*, size_t);
            void  objc_registerClassPair(void*);
            bool  class_addMethod(void*, void*, void*, const char*);
            void* objc_getProtocol(const char*);
            bool  class_addProtocol(void*, void*);
        ", '/usr/lib/libobjc.A.dylib');
        foreach ($frameworks as $f) {
            if (self::$c->dlopen("/System/Library/Frameworks/$f.framework/$f", 2) === null) {
                throw new RuntimeException("dlopen $f failed");
            }
        }
        self::$msgSend = self::$c->dlsym(self::$c->dlopen('/usr/lib/libobjc.A.dylib', 2), 'objc_msgSend');
        self::$msgSendSuper = self::$c->dlsym(self::$c->dlopen('/usr/lib/libobjc.A.dylib', 2), 'objc_msgSendSuper');
        self::$globalBlockIsa = self::$c->dlsym(self::$c->dlopen('/usr/lib/libSystem.B.dylib', 2), '_NSConcreteGlobalBlock')
            ?? throw new RuntimeException('_NSConcreteGlobalBlock');
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

    public static function selName($sel): string
    {
        return self::$c->sel_getName($sel); // a const char* return arrives as a PHP string
    }

    public static function range(int $location, int $length)
    {
        $r = self::$c->new('NSRange');
        $r->location = $location; $r->length = $length;
        return $r;
    }

    public static function msg(string $sig, $recv, string $sel, ...$args)
    {
        $fn = self::$send[$sig] ??= self::sender($sig);
        return $fn($recv, self::sel($sel), ...$args);
    }

    /** Sends $sel to $recv as an instance of its class's superclass $super would answer it: what an overriding method of a class defineClass made calls (objc_msgSendSuper). */
    public static function msgSuper(string $sig, $recv, string $super, string $sel, ...$args)
    {
        $fn = self::$sendSuper[$sig] ??= self::sender($sig, true);
        $s = self::$c->new('objc_super');
        $s->receiver = $recv; $s->super_class = self::cls($super);
        return $fn(FFI::addr($s), self::sel($sel), ...$args);
    }

    private static function sender(string $sig, bool $super = false)
    {
        [$ret, $args] = explode(':', $sig);
        $params = [$super ? 'objc_super*' : 'void*', 'void*'];
        foreach (str_split($args) as $ch) {
            $params[] = self::MAP[$ch];
        }
        return self::$c->cast(self::MAP[$ret] . '(*)(' . implode(',', $params) . ')', $super ? self::$msgSendSuper : self::$msgSend);
    }

    /**
     * An Objective-C block whose body is a PHP closure, for completion
     * handlers. $args are the block's parameter letters (MAP), without the
     * block itself: block('lp', fn($response, $obj) => ...), and $ret the
     * letter of what it returns ('v' for nothing). A global block (never
     * copied or freed by the runtime); a once block is released after it
     * fires, otherwise it lives for the process.
     */
    public static function block(string $args, Closure $fn, bool $once = true, string $ret = 'v')
    {
        foreach (self::$spentBlocks as $addr) {
            unset(self::$blocks[$addr]);
        }
        self::$spentBlocks = [];
        $params = ['void*'];
        $encoding = self::ENCODING[$ret] . '@?';
        foreach (str_split($args) as $ch) {
            $params[] = self::MAP[$ch];
            $encoding .= self::ENCODING[$ch];
        }
        $type = self::MAP[$ret] . '(*)(' . implode(',', $params) . ')';
        $holder = self::$c->new('struct { ' . str_replace('(*)', '(*fn)', $type) . '; }');
        $literal = self::$c->new('BlockLiteral');
        $addr = self::addr(FFI::addr($literal));
        $holder->fn = function ($block, ...$a) use ($fn, $once, $addr) {
            if ($once) {
                self::$spentBlocks[] = $addr;
            }
            return $fn(...$a);
        };
        $sig = self::$c->new('char[' . (strlen($encoding) + 1) . ']');
        FFI::memcpy($sig, $encoding, strlen($encoding));
        $descriptor = self::$c->new('BlockDescriptor');
        $descriptor->size = FFI::sizeof(self::$c->type('BlockLiteral'));
        $descriptor->signature = self::$c->cast('char*', $sig);
        $literal->isa = self::$globalBlockIsa;
        $literal->flags = (1 << 28) | (1 << 30); // BLOCK_IS_GLOBAL | BLOCK_HAS_SIGNATURE
        $literal->invoke = self::$c->cast('void*', $holder->fn);
        $literal->descriptor = FFI::addr($descriptor);
        self::$blocks[$addr] = [$literal, $descriptor, $holder, $sig];
        return self::$c->cast('void*', $addr);
    }

    /**
     * A block that does nothing, for completion handlers the system calls
     * on another thread, where PHP cannot run: its invoke is getpid, which
     * ignores its arguments.
     */
    public static function noopBlock()
    {
        static $literal = null, $descriptor = null;
        if ($literal === null) {
            $literal = self::$c->new('BlockLiteral');
            $descriptor = self::$c->new('BlockDescriptor');
            $descriptor->size = FFI::sizeof(self::$c->type('BlockLiteral'));
            $literal->isa = self::$globalBlockIsa;
            $literal->flags = 1 << 28; // BLOCK_IS_GLOBAL
            $literal->invoke = self::$c->dlsym(self::$c->dlopen('/usr/lib/libSystem.B.dylib', 2), 'getpid');
            $literal->descriptor = FFI::addr($descriptor);
        }
        return self::$c->cast('void*', FFI::addr($literal));
    }

    /** Calls a block the system handed us (a completion handler) with $args as msg()'s letters, $sig "ret:args". */
    public static function callBlock(string $sig, $block, ...$args)
    {
        [$ret, $letters] = explode(':', $sig);
        $params = ['void*'];
        foreach (str_split($letters) as $ch) {
            $params[] = self::MAP[$ch];
        }
        $literal = self::$c->cast('BlockLiteral*', $block);
        $fn = self::$c->cast(self::MAP[$ret] . '(*)(' . implode(',', $params) . ')', $literal->invoke);
        return $fn($block, ...$args);
    }

    public static function alloc(string $cls)
    {
        return self::msg('p:', self::cls($cls), 'alloc');
    }

    public static function point(float $x, float $y)
    {
        $p = self::$c->new('NSPoint');
        $p->x = $x; $p->y = $y;
        return $p;
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
     * $protocols the class declares, for frameworks that check conformance
     * (AppKit creates a text input context only for an NSTextInputClient).
     */
    public static function defineClass(string $name, string $super, array $methods, array $protocols = [])
    {
        $cls = self::$c->objc_allocateClassPair(self::cls($super), $name, 0);
        foreach ($protocols as $protocol) {
            $p = self::$c->objc_getProtocol($protocol) ?? throw new RuntimeException("no protocol $protocol");
            self::$c->class_addProtocol($cls, $p);
        }
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
