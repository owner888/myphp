<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use FFI;
use FFI\CData;
use MyPHP\Plugins\Curl;
use RuntimeException;

/**
 * A connection libcurl makes, the system's, through FFI: it only connects
 * (CONNECT_ONLY), with TLS for https and through the proxy the system
 * names, and the bytes of the handshake and the frames go through
 * curl_easy_send and curl_easy_recv, without blocking. The handle stays in
 * its multi handle, which they need.
 *
 * @internal
 */
final class CurlTransport implements Transport
{
    private FFI $f;
    private ?CData $h;
    private CData $m;
    private CData $n;
    private CData $buf;
    private ?int $result = null;

    /** Starts connecting to $url, http or https. */
    public function __construct(string $url)
    {
        $f = $this->f = Curl::ffi();
        $this->h = $f->curl_easy_init();
        $this->m = $f->curl_multi_init();
        // A PHP int goes to the variadic setopt as a 64-bit integer, libcurl's long.
        $f->curl_easy_setopt($this->h, Curl::OPT_URL, $url);
        $f->curl_easy_setopt($this->h, Curl::OPT_CONNECT_ONLY, 1);
        $f->curl_easy_setopt($this->h, Curl::OPT_NOSIGNAL, 1);
        $f->curl_easy_setopt($this->h, Curl::OPT_PROTOCOLS_STR, 'http,https');
        $f->curl_easy_setopt($this->h, Curl::OPT_HTTPPROXYTUNNEL, 1); // a proxy tunnels the connection
        $f->curl_multi_add_handle($this->m, $this->h);
        $this->n = $f->new('size_t');
        $this->buf = $f->new('char[65536]');
    }

    /** Null while connecting, then libcurl's result: 0 once connected. */
    public function connected(): ?int
    {
        if ($this->result === null && $this->h !== null) {
            $running = $this->f->new('int');
            $left = $this->f->new('int');
            $this->f->curl_multi_perform($this->m, FFI::addr($running));
            while (($msg = $this->f->curl_multi_info_read($this->m, FFI::addr($left))) !== null && !FFI::isNull($msg)) {
                if ($msg->msg === Curl::MSG_DONE) {
                    $this->result = $msg->data->result;
                }
            }
        }
        return $this->result;
    }

    /** What a result of libcurl says. */
    public function error(int $code): string
    {
        return (string)$this->f->curl_easy_strerror($code);
    }

    public function read(): ?string
    {
        $this->open();
        $rc = $this->f->curl_easy_recv($this->h, $this->buf, 65536, FFI::addr($this->n));
        if ($rc === Curl::E_AGAIN) {
            return '';
        }
        if ($rc !== 0) {
            throw new RuntimeException($this->error($rc));
        }
        $n = $this->n->cdata;
        return $n === 0 ? null : FFI::string($this->buf, $n);
    }

    public function write(string $bytes): int
    {
        $this->open();
        $rc = $this->f->curl_easy_send($this->h, $bytes, strlen($bytes), FFI::addr($this->n));
        if ($rc === Curl::E_AGAIN) {
            return 0;
        }
        if ($rc !== 0) {
            throw new RuntimeException($this->error($rc));
        }
        return $this->n->cdata;
    }

    private function open(): void
    {
        if ($this->h === null) {
            throw new RuntimeException('websocket: connection closed');
        }
    }

    public function close(): void
    {
        if ($this->h !== null) {
            $this->f->curl_multi_remove_handle($this->m, $this->h);
            $this->f->curl_easy_cleanup($this->h);
            $this->f->curl_multi_cleanup($this->m);
            $this->h = null;
        }
    }
}
