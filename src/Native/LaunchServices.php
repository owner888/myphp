<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;

/**
 * The default handlers of URL schemes, from Launch Services (mygo's
 * darwin deeplink.go): LSSetDefaultHandlerForURLScheme and
 * LSCopyDefaultHandlerForURLScheme, over CFStrings.
 *
 * @internal
 */
final class LaunchServices
{
    private const UTF8 = 0x08000100; // kCFStringEncodingUTF8
    private static ?FFI $c = null;

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef const void* CFStringRef;
            CFStringRef CFStringCreateWithCString(void* alloc, const char* s, unsigned int encoding);
            long CFStringGetLength(CFStringRef s);
            bool CFStringGetCString(CFStringRef s, char* buf, long size, unsigned int encoding);
            void CFRelease(const void* cf);
            int LSSetDefaultHandlerForURLScheme(CFStringRef scheme, CFStringRef bundleID);
            CFStringRef LSCopyDefaultHandlerForURLScheme(CFStringRef scheme);
        ', '/System/Library/Frameworks/CoreServices.framework/CoreServices');
    }

    /** Makes the bundle $bundleId the handler of $scheme's URLs: Launch Services' status, 0 for done. */
    public static function setDefaultHandler(string $scheme, string $bundleId): int
    {
        $c = self::c();
        $s = $c->CFStringCreateWithCString(null, $scheme, self::UTF8);
        $b = $c->CFStringCreateWithCString(null, $bundleId, self::UTF8);
        try {
            return $c->LSSetDefaultHandlerForURLScheme($s, $b);
        } finally {
            $c->CFRelease($s);
            $c->CFRelease($b);
        }
    }

    /** The identifier of the bundle that opens $scheme's URLs, or null. */
    public static function defaultHandler(string $scheme): ?string
    {
        $c = self::c();
        $s = $c->CFStringCreateWithCString(null, $scheme, self::UTF8);
        try {
            $h = $c->LSCopyDefaultHandlerForURLScheme($s);
        } finally {
            $c->CFRelease($s);
        }
        if ($h === null) {
            return null;
        }
        try {
            $size = 4 * $c->CFStringGetLength($h) + 1;
            $buf = $c->new("char[$size]");
            return $c->CFStringGetCString($h, $buf, $size, self::UTF8) ? FFI::string($buf) : null;
        } finally {
            $c->CFRelease($h);
        }
    }
}
