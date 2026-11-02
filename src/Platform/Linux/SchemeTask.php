<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;

/**
 * The response to a WebKitURISchemeRequest (mygo's linux schemeTask): its
 * body goes through a pipe, whose read end WebKit reads on the main loop.
 * mygo's handler writes from a goroutine, blocking while the pipe is full;
 * PHP writes from the main loop, which WebKit reads on, so the write end
 * does not block: what the pipe does not take waits here and goes as
 * GLib tells the pipe takes more. A write the page no longer reads fails
 * (EPIPE): the task stopped.
 *
 * @internal
 */
final class SchemeTask implements \MyPHP\Native\SchemeTask
{
    private const EINTR = 4, EAGAIN = 11;
    private const IO_OUT = 4, IO_ERR = 8, IO_HUP = 16;
    private const FD_CLOEXEC = 1;
    private const MESSAGE_HEADERS_RESPONSE = 1;

    /** @var array<int, self> the tasks waiting for their pipe to take more, by its write end */
    private static array $writing = [];
    private static ?CData $onWritable = null, $onHeader = null;
    /** @var array<string, string> the headers soup_message_headers_foreach is giving */
    private static array $headers = [];

    private ?CData $req;
    private bool $responded = false, $done = false, $gone = false;
    /** The write end of the pipe, -1 before respond() and once closed; what it has yet to take; whether it closes once it took it; GLib's watch of it. */
    private int $fd = -1;
    private string $pending = '';
    private bool $closing = false;
    private int $watch = 0;

    public function __construct(CData $req)
    {
        $this->req = WebKit::ffi()->g_object_ref($req);
    }

    public function stopped(): bool
    {
        return $this->gone || $this->done;
    }

    public function respond(int $status, array $headers): void
    {
        if ($this->stopped() || $this->responded) {
            return;
        }
        $f = WebKit::ffi();
        $fds = $f->new('int[2]');
        $err = $f->new('void*[1]');
        if (!$f->g_unix_open_pipe($fds, self::FD_CLOEXEC, $err)) {
            $this->fail(WebKit::errorMessage($err[0]));
            return;
        }
        $f->g_unix_set_fd_nonblocking($fds[1], 1, null);
        $this->responded = true;
        $stream = $f->g_unix_input_stream_new($fds[0], 1); // closes the read end as it goes
        $length = -1;
        foreach ($headers as $k => $v) {
            if (strcasecmp((string)$k, 'Content-Length') === 0 && ctype_digit($v)) { $length = (int)$v; }
        }
        $resp = $f->webkit_uri_scheme_response_new($stream, $length);
        $f->webkit_uri_scheme_response_set_status($resp, $status, null);
        $h = $f->soup_message_headers_new(self::MESSAGE_HEADERS_RESPONSE);
        foreach ($headers as $k => $v) {
            if (strcasecmp((string)$k, 'Content-Type') === 0) { $f->webkit_uri_scheme_response_set_content_type($resp, $v); }
            $f->soup_message_headers_append($h, (string)$k, $v);
        }
        $f->webkit_uri_scheme_response_set_http_headers($resp, $h); // takes them
        $f->webkit_uri_scheme_request_finish_with_response($this->req, $resp);
        $f->g_object_unref($resp);
        $f->g_object_unref($stream);
        $this->release();
        $this->fd = $fds[1];
    }

    public function write(string $bytes): void
    {
        if ($this->stopped() || $bytes === '') {
            return;
        }
        if (!$this->responded) {
            $this->respond(200, []);
        }
        if ($this->fd < 0) {
            return;
        }
        $this->pending .= $bytes;
        $this->flush();
    }

    public function finish(): void
    {
        if ($this->stopped()) {
            return;
        }
        if (!$this->responded) {
            $this->respond(200, []);
        }
        $this->done = true;
        $this->closing = true;
        $this->flush();
    }

    public function fail(string $message): void
    {
        if ($this->stopped()) {
            return;
        }
        if ($this->responded) {
            $this->finish(); // the body ends where it is
            return;
        }
        $f = WebKit::ffi();
        $this->responded = true;
        $this->done = true;
        $e = $f->g_error_new_literal($f->g_quark_from_string('myphp'), 1, $message);
        $f->webkit_uri_scheme_request_finish_error($this->req, $e);
        $f->g_error_free($e);
        $this->release();
    }

    /** Writes what the pipe takes, waits for GLib to tell it takes more, and closes it once all went, if it is to. */
    private function flush(): void
    {
        $f = WebKit::ffi();
        while ($this->pending !== '' && $this->fd >= 0) {
            $n = $f->write($this->fd, $this->pending, strlen($this->pending));
            if ($n > 0) {
                $this->pending = (string)substr($this->pending, $n);
                continue;
            }
            $errno = $f->__errno_location()[0];
            if ($errno === self::EINTR) {
                continue;
            }
            if ($errno === self::EAGAIN) {
                $this->waitWritable();
                return;
            }
            $this->gone = true; // the page no longer reads it
            $this->pending = '';
            $this->closeFd();
            return;
        }
        if ($this->closing && $this->pending === '') {
            $this->closeFd();
        }
    }

    private function waitWritable(): void
    {
        if ($this->watch !== 0) {
            return;
        }
        $f = WebKit::ffi();
        if (self::$onWritable === null) {
            self::$onWritable = $f->new('HFd', false);
            self::$onWritable->fn = function (int $fd, int $condition, $data): int {
                $t = self::$writing[$fd] ?? null;
                if ($t === null) {
                    return 0;
                }
                $t->watch = 0;
                unset(self::$writing[$fd]);
                $t->flush(); // watches again if it still waits
                return 0; // G_SOURCE_REMOVE: this watch is done
            };
        }
        self::$writing[$this->fd] = $this;
        $this->watch = $f->g_unix_fd_add_full(0, $this->fd, self::IO_OUT | self::IO_ERR | self::IO_HUP, self::$onWritable->fn, null, null);
    }

    private function closeFd(): void
    {
        if ($this->fd < 0) {
            return;
        }
        $f = WebKit::ffi();
        if ($this->watch !== 0) {
            $f->g_source_remove($this->watch);
            $this->watch = 0;
        }
        unset(self::$writing[$this->fd]);
        $f->close($this->fd); // the end of the body
        $this->fd = -1;
    }

    private function release(): void
    {
        if ($this->req !== null) {
            WebKit::ffi()->g_object_unref($this->req);
            $this->req = null;
        }
    }

    /** The request of a WebKitURISchemeRequest: method, URL, headers and body (mygo's serveScheme). @return array{0: string, 1: string, 2: array<string, string>, 3: string} */
    public static function request(CData $req): array
    {
        $f = WebKit::ffi();
        $method = (string)$f->webkit_uri_scheme_request_get_http_method($req);
        $url = (string)$f->webkit_uri_scheme_request_get_uri($req);
        $headers = [];
        if (($h = $f->webkit_uri_scheme_request_get_http_headers($req)) !== null) {
            if (self::$onHeader === null) {
                self::$onHeader = $f->new('HHeader', false);
                self::$onHeader->fn = function ($name, $value, $data): void { self::$headers[(string)$name] = (string)$value; };
            }
            self::$headers = [];
            $f->soup_message_headers_foreach($h, self::$onHeader->fn, null);
            $headers = self::$headers;
            self::$headers = [];
        }
        $body = '';
        if (($stream = $f->webkit_uri_scheme_request_get_http_body($req)) !== null) {
            $buf = $f->new('char[65536]');
            $n = $f->new('size_t[1]');
            $err = $f->new('void*[1]');
            do {
                $ok = $f->g_input_stream_read_all($stream, $buf, 65536, $n, null, $err);
                $body .= \FFI::string($buf, $n[0]);
                if ($err[0] !== null) {
                    \MyPHP\Logger::warn("reading the body of $url: " . WebKit::errorMessage($err[0]), 'linux');
                    break;
                }
            } while ($ok && $n[0] === 65536);
            $f->g_object_unref($stream);
        }
        return [$method !== '' ? $method : 'GET', $url, $headers, $body];
    }
}
