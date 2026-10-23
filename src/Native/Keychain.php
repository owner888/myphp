<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use MyPHP\SecretNotFound;
use RuntimeException;

/**
 * Secrets as generic passwords of the login Keychain (mygo's darwin
 * secrets.go), the service and account as keytar does, through the
 * Security framework's SecItem functions over CoreFoundation dictionaries:
 * no Objective-C, so that it works before App::run. It uses the file-based
 * keychain: the data protection one needs a signed app with a
 * keychain-access-groups entitlement.
 *
 * @internal
 */
final class Keychain
{
    private const ITEM_NOT_FOUND = -25300, DUPLICATE_ITEM = -25299;
    private const UTF8 = 0x08000100;
    private static ?FFI $c = null;

    private static function c(): FFI
    {
        if (self::$c === null) {
            self::$c = FFI::cdef('
                typedef const void* CFTypeRef;
                typedef struct { long version; void* retain; void* release; void* copyDescription; void* equal; void* hash; } CFDictionaryKeyCallBacks;
                typedef struct { long version; void* retain; void* release; void* copyDescription; void* equal; } CFDictionaryValueCallBacks;
                extern const CFDictionaryKeyCallBacks kCFTypeDictionaryKeyCallBacks;
                extern const CFDictionaryValueCallBacks kCFTypeDictionaryValueCallBacks;
                extern const CFTypeRef kCFBooleanTrue;
                extern const CFTypeRef kSecClass, kSecClassGenericPassword, kSecAttrService, kSecAttrAccount, kSecAttrLabel, kSecValueData, kSecReturnData, kSecMatchLimit, kSecMatchLimitOne;
                void* CFDictionaryCreateMutable(void* alloc, long capacity, const CFDictionaryKeyCallBacks* k, const CFDictionaryValueCallBacks* v);
                void CFDictionarySetValue(void* d, CFTypeRef key, CFTypeRef value);
                CFTypeRef CFStringCreateWithCString(void* alloc, const char* s, unsigned int encoding);
                long CFStringGetLength(CFTypeRef s);
                bool CFStringGetCString(CFTypeRef s, char* buf, long size, unsigned int encoding);
                CFTypeRef CFDataCreate(void* alloc, const char* bytes, long length);
                const void* CFDataGetBytePtr(CFTypeRef d);
                long CFDataGetLength(CFTypeRef d);
                void CFRelease(CFTypeRef cf);
                int SecItemAdd(void* attributes, CFTypeRef* result);
                int SecItemCopyMatching(void* query, CFTypeRef* result);
                int SecItemUpdate(void* query, void* attributes);
                int SecItemDelete(void* query);
                CFTypeRef SecCopyErrorMessageString(int status, void* reserved);
            ', '/System/Library/Frameworks/Security.framework/Security');
        }
        return self::$c;
    }

    /** A dictionary of $kv, keys and values CF objects; the caller releases it and the strings it made. @param list<array{0: mixed, 1: mixed}> $kv */
    private static function dict(array $kv)
    {
        $c = self::c();
        $d = $c->CFDictionaryCreateMutable(null, 0, FFI::addr($c->kCFTypeDictionaryKeyCallBacks), FFI::addr($c->kCFTypeDictionaryValueCallBacks));
        foreach ($kv as [$k, $v]) {
            $c->CFDictionarySetValue($d, $k, $v);
        }
        return $d;
    }

    /** Runs $fn with CF strings of $strings, released after. @template T @param list<string> $strings @param \Closure(mixed...): T $fn @return T */
    private static function with(array $strings, \Closure $fn): mixed
    {
        $c = self::c();
        $refs = array_map(fn(string $s) => $c->CFStringCreateWithCString(null, $s, self::UTF8), $strings);
        try {
            return $fn(...$refs);
        } finally {
            foreach ($refs as $r) {
                $c->CFRelease($r);
            }
        }
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private static function query($service, $account): array
    {
        $c = self::c();
        return [[$c->kSecClass, $c->kSecClassGenericPassword], [$c->kSecAttrService, $service], [$c->kSecAttrAccount, $account]];
    }

    /** The error of status $s, with the system's message; SecretNotFound for no item. */
    private static function status(string $op, int $s): void
    {
        if ($s === 0) {
            return;
        }
        if ($s === self::ITEM_NOT_FOUND) {
            throw new SecretNotFound('myphp: no secret stored under the key');
        }
        $c = self::c();
        $msg = 'unknown error';
        $str = $c->SecCopyErrorMessageString($s, null);
        if ($str !== null) {
            $size = 4 * $c->CFStringGetLength($str) + 1;
            $buf = $c->new("char[$size]");
            if ($c->CFStringGetCString($str, $buf, $size, self::UTF8)) {
                $msg = FFI::string($buf);
            }
            $c->CFRelease($str);
        }
        throw new RuntimeException("myphp: secrets: keychain: $op: $msg (OSStatus $s)");
    }

    public static function set(string $service, string $account, string $secret): void
    {
        $c = self::c();
        self::with([$service, $account], function ($svc, $acc) use ($c, $secret): void {
            $data = $c->CFDataCreate(null, $secret, strlen($secret));
            $q = self::dict(self::query($svc, $acc));
            $update = self::dict([[$c->kSecValueData, $data]]);
            try {
                $s = $c->SecItemUpdate($q, $update);
                if ($s !== self::ITEM_NOT_FOUND) {
                    self::status('update', $s);
                    return;
                }
                $add = self::dict([...self::query($svc, $acc), [$c->kSecValueData, $data], [$c->kSecAttrLabel, $svc]]);
                try {
                    $s = $c->SecItemAdd($add, null);
                } finally {
                    $c->CFRelease($add);
                }
                if ($s === self::DUPLICATE_ITEM) {
                    // Added by another process since the update.
                    self::status('update', $c->SecItemUpdate($q, $update));
                } else {
                    self::status('add', $s);
                }
            } finally {
                $c->CFRelease($q);
                $c->CFRelease($update);
                $c->CFRelease($data);
            }
        });
    }

    public static function get(string $service, string $account): string
    {
        $c = self::c();
        return self::with([$service, $account], function ($svc, $acc) use ($c): string {
            $q = self::dict([...self::query($svc, $acc), [$c->kSecReturnData, $c->kCFBooleanTrue], [$c->kSecMatchLimit, $c->kSecMatchLimitOne]]);
            $out = $c->new('CFTypeRef[1]');
            try {
                self::status('read', $c->SecItemCopyMatching($q, $out));
            } finally {
                $c->CFRelease($q);
            }
            $data = $out[0];
            if ($data === null) {
                throw new RuntimeException('myphp: secrets: keychain: read: no data');
            }
            try {
                $n = $c->CFDataGetLength($data);
                return $n === 0 ? '' : FFI::string($c->CFDataGetBytePtr($data), $n);
            } finally {
                $c->CFRelease($data);
            }
        });
    }

    public static function delete(string $service, string $account): void
    {
        $c = self::c();
        self::with([$service, $account], function ($svc, $acc) use ($c): void {
            $q = self::dict(self::query($svc, $acc));
            try {
                $s = $c->SecItemDelete($q);
            } finally {
                $c->CFRelease($q);
            }
            if ($s !== self::ITEM_NOT_FOUND) {
                self::status('delete', $s);
            }
        });
    }
}
