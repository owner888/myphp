<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * The system's libcurl through FFI, for the updater, the fetch and WebSocket plugins and a film read over HTTP (CurlLoop):
 * one easy handle a request, driven by a multi handle without blocking,
 * its callbacks run from perform() on the main thread. A WebSocket's
 * handle only connects (CONNECT_ONLY) and stays in its multi handle, which
 * curl_easy_send and curl_easy_recv need.
 *
 * @internal
 */
final class Curl
{
    private const CDEF = <<<'C'
        typedef void CURL;
        typedef void CURLM;
        struct curl_slist { char *data; struct curl_slist *next; };
        typedef struct { int msg; CURL *easy_handle; union { void *whatever; int result; } data; } CURLMsg;
        typedef size_t (*myphp_curl_data)(char *ptr, size_t size, size_t n, void *user);
        typedef struct { myphp_curl_data write; myphp_curl_data header; } myphp_curl_callbacks;
        CURL *curl_easy_init(void);
        void curl_easy_cleanup(CURL *h);
        int curl_easy_setopt(CURL *h, int option, ...);
        int curl_easy_getinfo(CURL *h, int info, ...);
        const char *curl_easy_strerror(int code);
        CURLM *curl_multi_init(void);
        int curl_multi_cleanup(CURLM *m);
        int curl_multi_add_handle(CURLM *m, CURL *h);
        int curl_multi_remove_handle(CURLM *m, CURL *h);
        int curl_multi_perform(CURLM *m, int *running);
        int curl_multi_setopt(CURLM *m, int option, ...);
        int curl_multi_socket_action(CURLM *m, int s, int ev_bitmask, int *running);
        int curl_multi_poll(CURLM *m, void *extra_fds, unsigned int extra_nfds, int timeout_ms, int *numfds);
        CURLMsg *curl_multi_info_read(CURLM *m, int *left);
        int curl_easy_send(CURL *h, const char *buf, size_t len, size_t *n);
        int curl_easy_recv(CURL *h, char *buf, size_t len, size_t *n);
        struct curl_slist *curl_slist_append(struct curl_slist *list, const char *s);
        void curl_slist_free_all(struct curl_slist *list);
        C;

    public const OPT_URL = 10002, OPT_WRITEFUNCTION = 20011, OPT_HEADERFUNCTION = 20079, OPT_HTTPHEADER = 10023,
        OPT_CUSTOMREQUEST = 10036, OPT_POSTFIELDS = 10015, OPT_POSTFIELDSIZE_LARGE = 30120, OPT_FOLLOWLOCATION = 52,
        OPT_MAXREDIRS = 68, OPT_NOBODY = 44, OPT_NOSIGNAL = 99, OPT_PROTOCOLS_STR = 10318, OPT_REDIR_PROTOCOLS_STR = 10319,
        OPT_ACCEPT_ENCODING = 10102, OPT_POST = 47, OPT_CONNECT_ONLY = 141, OPT_HTTPPROXYTUNNEL = 61, OPT_USERAGENT = 10018;
    public const INFO_RESPONSE_CODE = 0x200002, INFO_EFFECTIVE_URL = 0x100001, INFO_REDIRECT_COUNT = 0x200014;
    public const MSG_DONE = 1;
    /** CURLE_TOO_MANY_REDIRECTS. */
    public const E_TOO_MANY_REDIRECTS = 47;
    /** CURLE_AGAIN: nothing to read, or no room to write, now. */
    public const E_AGAIN = 81;

    private static ?FFI $ffi = null;

    /** The list of request headers $headers (name => value), owned (curl_slist_free_all it once its transfer ended); null for none. @param array<string, string> $headers */
    public static function headers(array $headers): ?CData
    {
        $list = null;
        foreach ($headers as $name => $value) { $list = self::ffi()->curl_slist_append($list, "$name: $value"); }
        return $list;
    }

    /** libcurl: the system's, /usr/lib/libcurl.4.dylib on macOS. */
    public static function ffi(): FFI
    {
        if (self::$ffi === null) {
            $lib = PHP_OS_FAMILY === 'Darwin' ? '/usr/lib/libcurl.4.dylib' : 'libcurl.so.4';
            try {
                self::$ffi = FFI::cdef(self::CDEF, $lib);
            } catch (\FFI\Exception $e) {
                throw new RuntimeException("cannot load $lib: {$e->getMessage()}");
            }
        }
        return self::$ffi;
    }

}
