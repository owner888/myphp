<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * The system's proxy for a URL, as Safari asks CFNetwork (mygo's netproxy
 * system_darwin.go): CFNetworkCopyProxiesForURL applies the settings of
 * System Settings > Network > Proxies to the URL, with their exceptions,
 * and names the setup script (PAC file) to run when there is one, which
 * CFNetworkExecuteProxyAutoConfigurationURL runs on this thread's run loop,
 * in a mode of its own, for at most 5 seconds. The answer is a list, as
 * "http://host:port", "socks5://host:port" or "direct://".
 * @internal MyPHP\NetProxy
 */
final class SystemProxy
{
    private const UTF8 = 0x08000100, SINT32 = 3, RUN_FINISHED = 1, PAC_TIMEOUT = 5.0;
    private static ?FFI $cf = null, $net = null;
    private static ?CData $mode = null;
    /** @var ?array{0: bool, 1: list<string>} what the PAC callback got: done, the proxies */
    private static ?array $pac = null;

    private static function load(): bool
    {
        if (self::$cf !== null) { return true; }
        if (PHP_OS_FAMILY !== 'Darwin') { return false; }
        self::$cf = FFI::cdef('
            typedef struct { long version; void *info; void *retain, *release, *copyDescription; } CFStreamClientContext;
            void CFRelease(void*);
            unsigned long CFGetTypeID(void*);
            bool CFEqual(void*, void*);
            unsigned long CFStringGetTypeID(void);
            unsigned long CFNumberGetTypeID(void);
            unsigned long CFDictionaryGetTypeID(void);
            void* CFStringCreateWithCString(void*, const char*, unsigned int);
            long CFStringGetLength(void*);
            long CFStringGetMaximumSizeForEncoding(long, unsigned int);
            bool CFStringGetCString(void*, char*, long, unsigned int);
            bool CFNumberGetValue(void*, long, int32_t*);
            void* CFURLCreateWithString(void*, void*, void*);
            long CFArrayGetCount(void*);
            void* CFArrayGetValueAtIndex(void*, long);
            void* CFDictionaryGetValue(void*, void*);
            void* CFRunLoopGetCurrent(void);
            void CFRunLoopAddSource(void*, void*, void*);
            void CFRunLoopRemoveSource(void*, void*, void*);
            void CFRunLoopSourceInvalidate(void*);
            int32_t CFRunLoopRunInMode(void*, double, bool);
            void CFRunLoopStop(void*);
        ', '/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation');
        self::$net = FFI::cdef('
            typedef struct { long version; void *info; void *retain, *release, *copyDescription; } CFStreamClientContext;
            typedef void (*myphp_pac_result)(void *client, void *proxies, void *error);
            void* CFNetworkCopySystemProxySettings(void);
            void* CFNetworkCopyProxiesForURL(void*, void*);
            void* CFNetworkExecuteProxyAutoConfigurationURL(void*, void*, myphp_pac_result, CFStreamClientContext*);
            void* CFNetworkExecuteProxyAutoConfigurationScript(void*, void*, myphp_pac_result, CFStreamClientContext*);
            extern void* kCFProxyTypeKey; extern void* kCFProxyHostNameKey; extern void* kCFProxyPortNumberKey;
            extern void* kCFProxyAutoConfigurationURLKey; extern void* kCFProxyAutoConfigurationJavaScriptKey;
            extern void* kCFProxyTypeNone; extern void* kCFProxyTypeHTTP; extern void* kCFProxyTypeHTTPS; extern void* kCFProxyTypeSOCKS;
            extern void* kCFProxyTypeAutoConfigurationURL; extern void* kCFProxyTypeAutoConfigurationJavaScript;
        ', '/System/Library/Frameworks/CFNetwork.framework/CFNetwork');
        self::$mode = self::$cf->CFStringCreateWithCString(null, 'myphp.netproxy', self::UTF8);
        return true;
    }

    /** The proxies the system gives $url, in order; [] for none known (not macOS, or no answer). @return list<string> */
    public static function forURL(string $url): array
    {
        if (!self::load()) { return []; }
        $cf = self::$cf; $net = self::$net;
        $s = $cf->CFStringCreateWithCString(null, $url, self::UTF8);
        if ($s === null) { return []; }
        $u = $cf->CFURLCreateWithString(null, $s, null);
        $cf->CFRelease($s);
        if ($u === null) { return []; }
        $settings = $net->CFNetworkCopySystemProxySettings();
        $out = [];
        if ($settings !== null) {
            $list = $net->CFNetworkCopyProxiesForURL($u, $settings);
            if ($list !== null) {
                $out = self::proxies($list, $u);
                $cf->CFRelease($list);
            }
            $cf->CFRelease($settings);
        }
        $cf->CFRelease($u);
        return $out;
    }

    /** The proxies of a CFNetwork list, setup scripts run against $target (none: not run). @return list<string> */
    private static function proxies(CData $list, ?CData $target): array
    {
        $cf = self::$cf; $net = self::$net;
        $is = fn(CData $a, string $const): bool => $cf->CFEqual($a, $net->$const);
        $out = [];
        for ($i = 0, $n = $cf->CFArrayGetCount($list); $i < $n; $i++) {
            $d = $cf->CFArrayGetValueAtIndex($list, $i);
            if ($d === null || $cf->CFGetTypeID($d) !== $cf->CFDictionaryGetTypeID()) { continue; }
            $type = $cf->CFDictionaryGetValue($d, $net->kCFProxyTypeKey);
            if ($type === null) { continue; }
            if ($is($type, 'kCFProxyTypeNone')) {
                $out[] = 'direct://';
            } elseif ($is($type, 'kCFProxyTypeHTTP') || $is($type, 'kCFProxyTypeHTTPS')) {
                $out[] = self::server('http', $d);
            } elseif ($is($type, 'kCFProxyTypeSOCKS')) {
                $out[] = self::server('socks5', $d);
            } elseif ($target !== null && $is($type, 'kCFProxyTypeAutoConfigurationURL')) {
                array_push($out, ...self::runPAC($cf->CFDictionaryGetValue($d, $net->kCFProxyAutoConfigurationURLKey), $target, false));
            } elseif ($target !== null && $is($type, 'kCFProxyTypeAutoConfigurationJavaScript')) {
                array_push($out, ...self::runPAC($cf->CFDictionaryGetValue($d, $net->kCFProxyAutoConfigurationJavaScriptKey), $target, true));
            }
        }
        return $out;
    }

    private static function server(string $scheme, CData $d): string
    {
        $cf = self::$cf;
        $host = self::string($cf->CFDictionaryGetValue($d, self::$net->kCFProxyHostNameKey));
        if ($host === '') { return ''; }
        if (str_contains($host, ':')) { $host = "[$host]"; }
        $port = 0;
        $n = $cf->CFDictionaryGetValue($d, self::$net->kCFProxyPortNumberKey);
        if ($n !== null && $cf->CFGetTypeID($n) === $cf->CFNumberGetTypeID()) {
            $v = $cf->new('int32_t');
            $cf->CFNumberGetValue($n, self::SINT32, FFI::addr($v));
            $port = $v->cdata;
        }
        return $port > 0 ? "$scheme://$host:$port" : "$scheme://$host";
    }

    private static function string(?CData $s): string
    {
        $cf = self::$cf;
        if ($s === null || $cf->CFGetTypeID($s) !== $cf->CFStringGetTypeID()) { return ''; }
        $n = $cf->CFStringGetMaximumSizeForEncoding($cf->CFStringGetLength($s), self::UTF8) + 1;
        $buf = $cf->new("char[$n]");
        return $cf->CFStringGetCString($s, $buf, $n, self::UTF8) ? FFI::string($buf) : '';
    }

    /** Runs a setup script, of its URL or its text, for $target on this thread's run loop, in a mode of its own, for at most PAC_TIMEOUT. @return list<string> */
    private static function runPAC(?CData $script, CData $target, bool $isScript): array
    {
        if ($script === null) { return []; }
        $cf = self::$cf; $net = self::$net;
        self::$pac = [false, []];
        $done = static function ($client, $list, $err): void {
            self::$pac = [true, $err === null && $list !== null ? self::proxies($list, null) : []];
            self::$cf->CFRunLoopStop(self::$cf->CFRunLoopGetCurrent());
        };
        $ctx = $net->new('CFStreamClientContext');
        $source = $isScript ? $net->CFNetworkExecuteProxyAutoConfigurationScript($script, $target, $done, FFI::addr($ctx))
            : $net->CFNetworkExecuteProxyAutoConfigurationURL($script, $target, $done, FFI::addr($ctx));
        if ($source === null) { return []; }
        $rl = $cf->CFRunLoopGetCurrent();
        $cf->CFRunLoopAddSource($rl, $source, self::$mode);
        $deadline = microtime(true) + self::PAC_TIMEOUT;
        while (!self::$pac[0] && ($left = $deadline - microtime(true)) > 0) {
            if ($cf->CFRunLoopRunInMode(self::$mode, $left, false) === self::RUN_FINISHED) { break; }
        }
        $cf->CFRunLoopSourceInvalidate($source);
        $cf->CFRunLoopRemoveSource($rl, $source, self::$mode);
        $cf->CFRelease($source);
        [, $got] = self::$pac;
        self::$pac = null;
        return $got;
    }
}
