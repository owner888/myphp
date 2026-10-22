<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Fetch;

use FFI;
use FFI\CData;
use Generator;
use MyPHP\{CallContext, Channel};
use MyPHP\Native\Curl;
use RuntimeException;

/**
 * The fetch plugin's service (mygo's fetch service), bound as
 * "plugin:fetch": fetch() makes the request with libcurl and streams the
 * response through the page's channel, its head first, then chunks of its
 * body, each at most 64 KiB, base64 as JSON carries Go's []byte.
 *
 * @internal
 */
final class Service
{
    /** The most body bytes a part holds. */
    public const CHUNK_SIZE = 64 << 10;
    /** How soon libcurl is driven again while data flows, and at most while it waits. */
    private const POLL_BUSY = 0.002, POLL_IDLE = 0.05;
    private const OPT_COPYPOSTFIELDS = 10165;

    public function __construct(private readonly Options $opts) {}

    /**
     * Makes the request and streams the response to the page. $req is
     * {url, method, headers: [[name, value]…], body: base64 or null,
     * redirect: "follow"|"manual"|"error"}.
     *
     * @param array{url?: string, method?: string, headers?: list<array{0: string, 1: string}>, body?: ?string, redirect?: string} $req
     * @param Channel $parts
     * @return void
     */
    public function fetch(CallContext $ctx, array $req, Channel $parts): Generator
    {
        yield from $this->run($ctx, $req);
    }

    /** The parts of the response, with waits between them while nothing came (Channel::wait). @param array<string, mixed> $req */
    private function run(CallContext $ctx, array $req): Generator
    {
        $url = (string)($req['url'] ?? '');
        $method = (string)($req['method'] ?? '');
        if ($method === '') { $method = 'GET'; }
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) {
            throw new RuntimeException("net/http: invalid method \"$method\"");
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException("unsupported URL scheme \"$scheme\"");
        }
        $headers = [];
        foreach ($req['headers'] ?? [] as $h) {
            $headers[] = [(string)$h[0], (string)$h[1]];
        }
        $body = null;
        if (isset($req['body'])) {
            $body = base64_decode((string)$req['body'], true);
            if ($body === false) { throw new RuntimeException('fetch: the body is not base64'); }
        }
        if ($this->opts->allow !== null && !($this->opts->allow)(new Request($method, $url, $headers))) {
            throw new RuntimeException('the app does not allow requests to ' . (new Request($method, $url, $headers))->redacted());
        }
        $redirect = (string)($req['redirect'] ?? 'follow');

        $f = Curl::ffi();
        $h = $f->curl_easy_init();
        $m = $f->curl_multi_init();
        $list = null;
        // What the callbacks gather as perform() runs: the headers of the response being read, and its body.
        $state = ['status' => 0, 'reason' => '', 'headers' => [], 'final' => null, 'body' => '', 'gzip' => false];
        $follow = $redirect === 'follow';
        $cbs = $f->new('myphp_curl_callbacks');
        $cbs->header = function ($ptr, $size, $n, $user) use (&$state, $follow): int {
            $len = $size * $n;
            $line = rtrim(FFI::string($ptr, $len), "\r\n");
            if (preg_match('~^HTTP/\S+\s+(\d{3})\s*(.*)$~', $line, $mm) === 1) {
                $state['status'] = (int)$mm[1]; $state['reason'] = trim($mm[2]); $state['headers'] = []; // a response's head starts
            } elseif ($line === '') {
                $s = $state['status'];
                $location = false;
                foreach ($state['headers'] as [$k]) { if ($k === 'location') { $location = true; } }
                $interim = $s >= 100 && $s < 200 || $follow && $location && in_array($s, [301, 302, 303, 307, 308], true);
                if (!$interim && $state['final'] === null) {
                    $state['final'] = [$s, $state['reason'], $state['headers']];
                }
            } elseif (($i = strpos($line, ':')) !== false) {
                $state['headers'][] = [strtolower(trim(substr($line, 0, $i))), trim(substr($line, $i + 1))];
            }
            return $len;
        };
        $cbs->write = function ($ptr, $size, $n, $user) use (&$state): int {
            $len = $size * $n;
            $state['body'] .= FFI::string($ptr, $len);
            return $len;
        };
        try {
            // A PHP int goes to a variadic C function as a 64-bit integer, libcurl's long and curl_off_t.
            $set = fn(int $opt, mixed $v) => $f->curl_easy_setopt($h, $opt, $v);
            $set(Curl::OPT_URL, $url);
            $set(Curl::OPT_NOSIGNAL, 1);
            $set(Curl::OPT_PROTOCOLS_STR, 'http,https');
            $set(Curl::OPT_REDIR_PROTOCOLS_STR, 'http,https');
            $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
            $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
            $set(Curl::OPT_FOLLOWLOCATION, $follow ? 1 : 0);
            $set(Curl::OPT_MAXREDIRS, 20); // as browsers, and Go's default
            if ($method === 'HEAD') {
                $set(Curl::OPT_NOBODY, 1);
            }
            if ($body !== null) {
                $set(Curl::OPT_POSTFIELDSIZE_LARGE, strlen($body));
                $set(self::OPT_COPYPOSTFIELDS, $body === '' ? "\0" : $body);
            }
            if ($method !== 'GET' && $method !== 'HEAD' || $body !== null) {
                $set(Curl::OPT_CUSTOMREQUEST, $method);
            }
            // As Go's client: no Accept or Expect of curl's own; gzip asked for and undone unless the page asks for an encoding itself.
            $has = fn(string $name): bool => array_filter($headers, fn($x) => strcasecmp($x[0], $name) === 0) !== [];
            $lines = [];
            foreach ($headers as [$k, $v]) {
                if (strcasecmp($k, 'content-length') === 0) { continue; } // the body's
                $lines[] = $v === '' ? "$k;" : "$k: $v";
            }
            if (!$has('accept')) { $lines[] = 'Accept:'; }
            if (!$has('expect')) { $lines[] = 'Expect:'; }
            if (!$has('accept-encoding')) {
                $set(Curl::OPT_ACCEPT_ENCODING, 'gzip');
                $state['gzip'] = true;
            }
            foreach ($lines as $l) { $list = $f->curl_slist_append($list, $l); }
            $set(Curl::OPT_HTTPHEADER, $list);
            $f->curl_multi_add_handle($m, $h);

            $running = $f->new('int');
            $left = $f->new('int');
            $sentHead = false;
            $result = null;
            $interval = self::POLL_BUSY;
            while (true) {
                if ($ctx->done()) {
                    return; // the page went away: the request stops
                }
                $f->curl_multi_perform($m, FFI::addr($running));
                while (($msg = $f->curl_multi_info_read($m, FFI::addr($left))) !== null && !FFI::isNull($msg)) {
                    if ($msg->msg === Curl::MSG_DONE) { $result = $msg->data->result; }
                }
                $progress = false;
                if (!$sentHead && ($state['final'] !== null || $result === 0)) {
                    [$status, $reason, $hs] = $state['final'] ?? [$state['status'], $state['reason'], $state['headers']];
                    if ($redirect === 'error' && in_array($status, [301, 302, 303, 307, 308], true) && array_filter($hs, fn($x) => $x[0] === 'location') !== []) {
                        throw new RuntimeException('redirected with redirect mode "error"');
                    }
                    $sentHead = true;
                    $progress = true;
                    yield $this->head($f, $h, $status, $reason, $hs, $state['gzip']);
                }
                if ($sentHead && $state['body'] !== '') {
                    $chunk = substr($state['body'], 0, self::CHUNK_SIZE);
                    $state['body'] = (string)substr($state['body'], strlen($chunk));
                    $progress = true;
                    yield ['chunk' => base64_encode($chunk)];
                }
                if ($result !== null && $state['body'] === '') {
                    if ($result !== 0) {
                        throw new RuntimeException($result === Curl::E_TOO_MANY_REDIRECTS ? 'stopped after 20 redirects' : (string)$f->curl_easy_strerror($result));
                    }
                    return;
                }
                if ($result !== null) {
                    continue; // the rest of the body, at once
                }
                $interval = $progress ? self::POLL_BUSY : min($interval * 2, self::POLL_IDLE);
                yield Channel::wait($interval);
            }
        } finally {
            $f->curl_multi_remove_handle($m, $h);
            $f->curl_easy_cleanup($h);
            $f->curl_multi_cleanup($m);
            if ($list !== null) { $f->curl_slist_free_all($list); }
        }
    }

    /** The response's head part. @param list<array{0: string, 1: string}> $headers @return array<string, mixed> */
    private function head(FFI $f, CData $h, int $status, string $reason, array $headers, bool $gzip): array
    {
        $count = $f->new('long');
        $f->curl_easy_getinfo($h, Curl::INFO_REDIRECT_COUNT, FFI::addr($count));
        $url = $f->new('char*');
        $f->curl_easy_getinfo($h, Curl::INFO_EFFECTIVE_URL, FFI::addr($url));
        if ($gzip && array_filter($headers, fn($x) => $x[0] === 'content-encoding' && strcasecmp($x[1], 'gzip') === 0) !== []) {
            // As Go's transport undoes the gzip it asked for: the body comes decoded, without its encoding and length.
            $headers = array_values(array_filter($headers, fn($x) => $x[0] !== 'content-encoding' && $x[0] !== 'content-length'));
        }
        return [
            'status' => $status,
            'statusText' => $reason !== '' ? $reason : self::statusText($status),
            'url' => FFI::isNull($url) ? '' : FFI::string($url),
            'redirected' => $count->cdata > 0,
            'headers' => $headers,
        ];
    }

    /** The text of a status, for HTTP/2's lines, which have none (Go's http.StatusText). */
    private static function statusText(int $code): string
    {
        return [
            100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content',
            206 => 'Partial Content', 207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used',
            300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy',
            307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
            411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request URI Too Long',
            415 => 'Unsupported Media Type', 416 => 'Requested Range Not Satisfiable', 417 => 'Expectation Failed', 418 => "I'm a teapot",
            421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early',
            426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected',
            510 => 'Not Extended', 511 => 'Network Authentication Required',
        ][$code] ?? '';
    }
}
