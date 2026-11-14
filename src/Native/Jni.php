<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Direct JNI Bridge over ext-ffi (Flutter 3.50-style direct Java/Kotlin interop).
 *
 * Allows PHP code and `myphp jnigen`-generated wrapper classes to instantiate
 * and call any Android SDK or third-party Java/Kotlin class synchronously
 * without writing custom C or Java glue per method.
 */
final class Jni
{
    public const CDEF = <<<'C'
        typedef union {
            uint8_t  z;
            int8_t   b;
            uint16_t c;
            int16_t  s;
            int32_t  i;
            int64_t  j;
            float    f;
            double   d;
            void*    l;
        } jvalue;

        void*   myphp_jni_env(void);
        void*   myphp_jni_activity(void);
        void*   myphp_jni_find_class(const char *name);
        void*   myphp_jni_get_method(void *cls, const char *name, const char *sig, int is_static);
        void*   myphp_jni_get_field(void *cls, const char *name, const char *sig, int is_static);
        void*   myphp_jni_new_string(const char *utf8);
        int     myphp_jni_get_string(void *jstr, char *out, int max_len);
        void    myphp_jni_delete_ref(void *global_ref);
        void*   myphp_jni_new_object(void *cls, void *ctor_mid, const jvalue *args);
        int64_t myphp_jni_call_long(void *target, void *mid, int is_static, char ret_type, const jvalue *args);
        double  myphp_jni_call_double(void *target, void *mid, int is_static, char ret_type, const jvalue *args);
        void*   myphp_jni_call_object(void *target, void *mid, int is_static, const jvalue *args);
    C;

    private static ?FFI $ffi = null;
    /** @var array<string, mixed> Cached global jclass handles */
    private static array $classes = [];
    /** @var array<string, mixed> Cached jmethodID handles */
    private static array $methods = [];

    public static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF);
    }

    /**
     * Returns the current Android Activity instance (`jobject`).
     */
    public static function activity(): mixed
    {
        return self::ffi()->myphp_jni_activity();
    }

    /**
     * Finds and caches a Java class by slash-separated or dot-separated name
     * (e.g. 'android/os/Build$VERSION' or 'android.os.BatteryManager').
     */
    public static function cls(string $className): mixed
    {
        $slashName = str_replace('.', '/', $className);
        if (isset(self::$classes[$slashName])) {
            return self::$classes[$slashName];
        }
        $c = self::ffi()->myphp_jni_find_class($slashName);
        if ($c === null || FFI::isNull($c)) {
            throw new RuntimeException("JNI: Java class not found: $slashName");
        }
        return self::$classes[$slashName] = $c;
    }

    /**
     * Resolves and caches a `jmethodID` for ($className, $method, $sig, $isStatic).
     */
    public static function methodId(string $className, string $method, string $sig, bool $isStatic): mixed
    {
        $key = ($isStatic ? 'S:' : 'I:') . "$className::$method:$sig";
        if (isset(self::$methods[$key])) {
            return self::$methods[$key];
        }
        $cls = self::cls($className);
        $mid = self::ffi()->myphp_jni_get_method($cls, $method, $sig, $isStatic ? 1 : 0);
        if ($mid === null || FFI::isNull($mid)) {
            throw new RuntimeException("JNI: method not found: $className::$method $sig");
        }
        return self::$methods[$key] = $mid;
    }

    /**
     * Instantiates a new Java object via constructor signature $ctorSig (e.g. '(Ljava/lang/String;)V').
     */
    public static function newObject(string $className, string $ctorSig = '()V', mixed ...$args): mixed
    {
        $f = self::ffi();
        $cls = self::cls($className);
        $mid = self::methodId($className, '<init>', $ctorSig, false);
        [$jargs, $tempRefs] = self::packArgs($f, $ctorSig, $args);
        try {
            return $f->myphp_jni_new_object($cls, $mid, $jargs);
        } finally {
            foreach ($tempRefs as $ref) {
                $f->myphp_jni_delete_ref($ref);
            }
        }
    }

    /**
     * Calls a static Java method with JNI signature $sig (e.g. '(Ljava/lang/String;)I').
     */
    public static function callStatic(string $className, string $method, string $sig, mixed ...$args): mixed
    {
        $cls = self::cls($className);
        $mid = self::methodId($className, $method, $sig, true);
        return self::invoke($cls, $mid, true, $sig, $args);
    }

    /**
     * Calls an instance Java method on $object (`jobject` or wrapper holding `$handle`).
     */
    public static function call(mixed $object, string $className, string $method, string $sig, mixed ...$args): mixed
    {
        $handle = is_object($object) && property_exists($object, 'handle') ? $object->handle : $object;
        $mid = self::methodId($className, $method, $sig, false);
        return self::invoke($handle, $mid, false, $sig, $args);
    }

    /**
     * Converts a `jstring` global ref to a PHP UTF-8 string and releases the global ref.
     */
    public static function toPhpString(mixed $jstr, bool $release = true): ?string
    {
        if ($jstr === null || ($jstr instanceof CData && FFI::isNull($jstr))) {
            return null;
        }
        $f = self::ffi();
        $buf = $f->new('char[65536]');
        $len = $f->myphp_jni_get_string($jstr, $buf, 65536);
        $str = FFI::string($buf, max(0, $len));
        if ($release) {
            $f->myphp_jni_delete_ref($jstr);
        }
        return $str;
    }

    /**
     * Releases a Java global reference (`jobject`).
     */
    public static function release(mixed $globalRef): void
    {
        if ($globalRef !== null && !($globalRef instanceof CData && FFI::isNull($globalRef))) {
            self::ffi()->myphp_jni_delete_ref($globalRef);
        }
    }

    /**
     * @param list<mixed> $args
     */
    private static function invoke(mixed $target, mixed $mid, bool $isStatic, string $sig, array $args): mixed
    {
        $f = self::ffi();
        $retSpec = substr($sig, (int)strrpos($sig, ')') + 1);
        $retChar = $retSpec[0] ?? 'V';

        [$jargs, $tempRefs] = self::packArgs($f, $sig, $args);
        try {
            $staticFlag = $isStatic ? 1 : 0;
            return match ($retChar) {
                'V' => (static function () use ($f, $target, $mid, $staticFlag, $jargs): null {
                    $f->myphp_jni_call_long($target, $mid, $staticFlag, ord('V'), $jargs);
                    return null;
                })(),
                'Z' => $f->myphp_jni_call_long($target, $mid, $staticFlag, ord('Z'), $jargs) !== 0,
                'B', 'S', 'I', 'J' => (int)$f->myphp_jni_call_long($target, $mid, $staticFlag, ord($retChar), $jargs),
                'F', 'D' => (float)$f->myphp_jni_call_double($target, $mid, $staticFlag, ord($retChar), $jargs),
                default => (static function () use ($f, $target, $mid, $staticFlag, $jargs, $retSpec): mixed {
                    $obj = $f->myphp_jni_call_object($target, $mid, $staticFlag, $jargs);
                    if ($retSpec === 'Ljava/lang/String;') {
                        return self::toPhpString($obj, true);
                    }
                    return $obj;
                })(),
            };
        } finally {
            foreach ($tempRefs as $ref) {
                $f->myphp_jni_delete_ref($ref);
            }
        }
    }

    /**
     * Parses JNI argument descriptors inside `(...)` and packs PHP values into `jvalue[N]`.
     *
     * @param list<mixed> $args
     * @return array{0: ?CData, 1: list<mixed>}
     */
    public static function packArgs(FFI $f, string $sig, array $args): array
    {
        $types = self::parseArgDescriptors($sig);
        $n = count($types);
        if ($n === 0) {
            return [null, []];
        }

        $arr = $f->new("jvalue[$n]");
        $tempRefs = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $types[$i];
            $val = $args[$i] ?? null;
            switch ($t[0]) {
                case 'Z':
                    $arr[$i]->z = $val ? 1 : 0;
                    break;
                case 'B':
                    $arr[$i]->b = (int)$val;
                    break;
                case 'C':
                    $arr[$i]->c = (int)$val;
                    break;
                case 'S':
                    $arr[$i]->s = (int)$val;
                    break;
                case 'I':
                    $arr[$i]->i = (int)$val;
                    break;
                case 'J':
                    $arr[$i]->j = (int)$val;
                    break;
                case 'F':
                    $arr[$i]->f = (float)$val;
                    break;
                case 'D':
                    $arr[$i]->d = (float)$val;
                    break;
                default:
                    if (is_string($val)) {
                        $jstr = $f->myphp_jni_new_string($val);
                        $tempRefs[] = $jstr;
                        $arr[$i]->l = $jstr;
                    } elseif (is_object($val) && property_exists($val, 'handle')) {
                        $arr[$i]->l = $val->handle;
                    } else {
                        $arr[$i]->l = $val;
                    }
                    break;
            }
        }

        return [$arr, $tempRefs];
    }

    /**
     * Parses a JNI method signature like `(ILjava/lang/String;Z)V` into `['I', 'Ljava/lang/String;', 'Z']`.
     *
     * @return list<string>
     */
    public static function parseArgDescriptors(string $sig): array
    {
        $open = strpos($sig, '(');
        $close = strpos($sig, ')');
        if ($open === false || $close === false || $close <= $open + 1) {
            return [];
        }
        $inner = substr($sig, $open + 1, $close - $open - 1);
        $out = [];
        $len = strlen($inner);
        $i = 0;
        while ($i < $len) {
            $ch = $inner[$i];
            if ($ch === '[') {
                $start = $i;
                while ($i < $len && $inner[$i] === '[') { $i++; }
                if ($i < $len && $inner[$i] === 'L') {
                    $semi = strpos($inner, ';', $i);
                    $i = $semi !== false ? $semi + 1 : $len;
                } else {
                    $i++;
                }
                $out[] = substr($inner, $start, $i - $start);
            } elseif ($ch === 'L') {
                $semi = strpos($inner, ';', $i);
                $end = $semi !== false ? $semi + 1 : $len;
                $out[] = substr($inner, $i, $end - $i);
                $i = $end;
            } else {
                $out[] = $ch;
                $i++;
            }
        }
        return $out;
    }
}
