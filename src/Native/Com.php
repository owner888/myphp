<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * COM through PHP's FFI, as Windows's renderers and text call it
 * (Native\D3d, Native\DWrite): GUIDs, QueryInterface, Release, HRESULTs,
 * and pointers made of their addresses. Its own FFI has the types these
 * need: a library's vtables take an IID as `const void *`, which a GUID of
 * any FFI's passes as, so one GUID type serves them all.
 */
final class Com
{
    /** E_NOINTERFACE, as a signed HRESULT: a QueryInterface of PHP's answer for an interface it is not. */
    public const E_NOINTERFACE = 0x80004002 - 0x100000000;

    private static ?FFI $f = null;

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { uint32_t a; uint16_t b, c; uint8_t d[8]; } GUID;
            typedef struct { int32_t (*QueryInterface)(void *self, const GUID *iid, void **out); uint32_t (*AddRef)(void *self); uint32_t (*Release)(void *self); } IUnknownVtbl;
            typedef struct { IUnknownVtbl *v; } IUnknown;
            C);
    }

    /** A GUID of its text form (8-4-4-4-12 hexadecimal digits); hold it through the call it goes to: FFI::addr() of a temporary points at what is freed. */
    public static function guid(string $s): CData
    {
        $h = str_replace('-', '', $s);
        $g = self::f()->new('GUID');
        $g->a = hexdec(substr($h, 0, 8)); $g->b = hexdec(substr($h, 8, 4)); $g->c = hexdec(substr($h, 12, 4));
        for ($i = 0; $i < 8; $i++) { $g->d[$i] = hexdec(substr($h, 16 + 2 * $i, 2)); }
        return $g;
    }

    /** The text form of GUID $g (of any FFI's: its fields a, b, c, d), as guid() takes it, lowercase. */
    public static function iid(CData $g): string
    {
        $d = bin2hex(FFI::string($g->d, 8));
        return sprintf('%08x-%04x-%04x-%s-%s', $g->a, $g->b, $g->c, substr($d, 0, 4), substr($d, 4));
    }

    /**
     * Pointer $p as one of $type of $f's, made of its address. FFI's cast
     * of a pointer is a view of the memory holding it, which goes with the
     * variable it came from (a local's, a struct's field read in a
     * callback): a pointer kept is made of its address.
     */
    public static function typed(FFI $f, CData $p, string $type): CData
    {
        return $f->cast($type, Mem::addr($p));
    }

    /** $obj's interface $iid, held (a `void *`), or null where it has none. */
    public static function query(CData $obj, string $iid): ?CData
    {
        $f = self::f();
        $q = self::typed($f, $obj, 'IUnknown *');
        $out = $f->new('void *');
        $g = self::guid($iid);
        if (($q->v->QueryInterface)($q, FFI::addr($g), FFI::addr($out)) < 0 || FFI::isNull($out)) {
            return null;
        }
        return self::typed($f, $out, 'void *');
    }

    /** Holds COM object $obj once more (AddRef), which Release lets go: a reference kept, not asked for (no QueryInterface, which a proxy calls across apartments). Returns $obj. */
    public static function addRef(CData $obj): CData
    {
        $u = self::typed(self::f(), $obj, 'IUnknown *');
        ($u->v->AddRef)($u);
        return $obj;
    }

    /** Releases COM object $obj, unless it is none. */
    public static function release(?CData $obj): void
    {
        if ($obj !== null && !FFI::isNull($obj)) {
            $u = self::typed(self::f(), $obj, 'IUnknown *');
            ($u->v->Release)($u);
        }
    }

    /** Throws for a failed HRESULT: $what failed, and its code. */
    public static function check(int $hr, string $what): void
    {
        if ($hr < 0) {
            throw new RuntimeException(sprintf('myphp: %s failed (HRESULT 0x%08X)', $what, $hr & 0xFFFFFFFF));
        }
    }
}
