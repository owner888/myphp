<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\Native\{Aes, Curl, CurlLoop, Mem};
use RuntimeException;

/**
 * A resource read whole over HTTP(S), or a range of it, from the start on
 * without stopping (HLS's playlists, keys and segments, as FFmpeg's hls
 * demuxer reads them): started at once and never waited for, driven by
 * the run loop (Native\CurlLoop), what came read as it comes (has(),
 * read()), into blocks of native memory as HttpBytes keeps a film's. An
 * AES-128 segment decrypts as it comes, its last block kept back for its
 * padding until the end. A connection that breaks goes on from where it
 * was, as HttpBytes does.
 */
final class Download implements Bytes
{
    private const BLOCK = 256 << 10;
    /** CURLOPT_RANGE, CURLOPT_CONNECTTIMEOUT. */
    private const OPT_RANGE = 10007, OPT_CONNECTTIMEOUT = 78;
    /** What a broken connection ends with (HttpBytes::BROKEN), gone on from RETRIES times. */
    private const BROKEN = [7, 18, 28, 52, 55, 56, 92], RETRIES = 3;

    /** @var list<array{0: CData, 1: int}> the blocks, full but the last: their memory and its address */
    private array $blocks = [];
    /** The bytes here (decrypted), and all there are once known (-1 before). */
    private int $length = 0, $total = -1;
    /** The bytes the server sent, from the range's start: where a broken connection goes on from. */
    private int $got = 0;
    private bool $done = false;
    private ?string $error = null;
    /** What came of the encrypted not yet decrypted: under a block, and the last block, kept for the padding. */
    private string $pending = '';
    private ?Aes $aes;
    private ?CData $easy = null;
    /** The transfer's list of request headers, freed with it. */
    private ?CData $list = null;
    private int $retries = 0;
    /** Where it was read from, redirects followed (relative URIs go from it), once done. */
    private string $effective;

    /**
     * Starts reading $url, from $offset for $length bytes (null: all of it),
     * decrypting with AES-128 key and IV $key ([key, iv]); $counted is told
     * each piece's bytes as they come (the speed of what reads them),
     * $finished that it is done, or failed; each request with $headers too
     * (name => value).
     * @param ?array{0: string, 1: string} $key
     * @param ?Closure(int): void $counted
     * @param ?Closure(): void $finished
     * @param array<string, string> $headers
     */
    public function __construct(public readonly string $url, private readonly int $offset = 0, private readonly ?int $range = null, ?array $key = null, private readonly ?Closure $counted = null, private readonly ?Closure $finished = null, private readonly array $headers = [])
    {
        $this->aes = $key === null ? null : new Aes($key[0], $key[1]);
        $this->effective = $url;
        if ($range !== null && $key === null) { $this->total = $range; }
        $this->fetch();
    }

    /** The whole of $url, waited for: a playlist's, a key's. @param array<string, string> $headers */
    public static function get(string $url, array $headers = []): self
    {
        $d = new self($url, headers: $headers);
        $d->wait();
        return $d;
    }

    /** Waits until all of it is here. */
    public function wait(): void
    {
        CurlLoop::waitUntil(fn(): bool => $this->done || $this->error !== null);
        $this->fail();
    }

    /** All of it, as a string (waited for). */
    public function text(): string
    {
        $this->wait();
        return $this->read(0, $this->length);
    }

    /** Where it was read from, redirects followed (waited for). */
    public function effectiveUrl(): string
    {
        $this->wait();
        return $this->effective;
    }

    /** Whether all of it is here (what failed thrown). */
    public function done(): bool
    {
        $this->fail();
        return $this->done;
    }

    /** Whether it failed or is done: nothing more comes. */
    public function over(): bool
    {
        return $this->done || $this->error !== null;
    }

    /** The bytes here. */
    public function cached(): int
    {
        return $this->length;
    }

    public function size(): int
    {
        if ($this->total < 0) {
            $this->wait();
        }
        return $this->total;
    }

    public function has(int $pos, int $n): bool
    {
        $this->fail();
        return $this->done || $pos + $n <= $this->length;
    }

    public function read(int $pos, int $n): string
    {
        CurlLoop::waitUntil(fn(): bool => $this->done || $this->error !== null || $pos + $n <= $this->length);
        $this->fail();
        $n = max(0, min($n, $this->length - $pos));
        $out = '';
        for ($b = intdiv($pos, self::BLOCK), $end = $pos + $n; $b * self::BLOCK < $end; $b++) {
            $from = max($pos - $b * self::BLOCK, 0);
            $out .= Mem::read($this->blocks[$b][1] + $from, min(self::BLOCK, $end - $b * self::BLOCK) - $from);
        }
        return $out;
    }

    /** Starts the transfer, from where the last left off. */
    private function fetch(): void
    {
        $f = Curl::ffi();
        $easy = $this->easy = $f->curl_easy_init();
        $cbs = $f->new('myphp_curl_callbacks');
        $self = \WeakReference::create($this);
        $status = 0;
        $cbs->header = static function ($ptr, $size, $n, $user) use ($self, &$status): int {
            $len = $size * $n;
            $line = rtrim(FFI::string($ptr, $len), "\r\n");
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int)$m[1];
            } elseif ($status >= 200 && $status < 300 && preg_match('~^content-length:\s*(\d+)~i', $line, $m) === 1 && ($d = $self->get()) !== null) {
                $d->sized((int)$m[1]);
            }
            return $len;
        };
        $cbs->write = static function ($ptr, $size, $n, $user) use ($self, &$status): int {
            $len = $size * $n;
            if ($status >= 300) { return $len; } // an error's page: read, not kept
            $d = $self->get();
            if ($d === null) { return 0; }
            $d->arrive(FFI::string($ptr, $len));
            return $len;
        };
        $set = fn(int $opt, mixed $v) => $f->curl_easy_setopt($easy, $opt, $v);
        $set(Curl::OPT_URL, $this->url);
        $set(Curl::OPT_NOSIGNAL, 1);
        $set(Curl::OPT_PROTOCOLS_STR, 'http,https');
        $set(Curl::OPT_REDIR_PROTOCOLS_STR, 'http,https');
        $set(Curl::OPT_FOLLOWLOCATION, 1);
        $set(Curl::OPT_MAXREDIRS, 20);
        $set(self::OPT_CONNECTTIMEOUT, 30);
        $set(19, 1); // CURLOPT_LOW_SPEED_LIMIT and _TIME: stalled 20 s, the transfer ends (a timeout) and goes on from where it was
        $set(20, 20);
        $from = $this->offset + $this->got;
        if ($from > 0 || $this->range !== null) {
            $set(self::OPT_RANGE, $from . '-' . ($this->range === null ? '' : $this->offset + $this->range - 1));
        }
        $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
        $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
        $list = $this->list = Curl::headers($this->headers);
        if ($list !== null) { $set(Curl::OPT_HTTPHEADER, $list); }
        CurlLoop::start($easy, static function (int $result) use ($self, $easy, $cbs, $list, &$status): void {
            if ($list !== null) { Curl::ffi()->curl_slist_free_all($list); }
            $d = $self->get();
            if ($d !== null && $d->easy === $easy) {
                $url = Curl::ffi()->new('char*');
                Curl::ffi()->curl_easy_getinfo($easy, Curl::INFO_EFFECTIVE_URL, FFI::addr($url));
                $d->ended($result, $status, FFI::isNull($url) ? $d->url : FFI::string($url));
            }
            Curl::ffi()->curl_easy_cleanup($easy);
        });
    }

    /** The response's length: what there is in all, when nothing decrypts (an encrypted one's padding comes off). @internal the transfer's callback */
    public function sized(int $n): void
    {
        if ($this->aes === null && $this->total < 0 && $this->got === 0) {
            $this->total = $n;
        }
    }

    /** Bytes of the transfer: decrypted (but the last block), kept. @internal the transfer's callback */
    public function arrive(string $s): void
    {
        $this->got += strlen($s);
        $this->retries = 0;
        if ($this->counted !== null) { ($this->counted)(strlen($s)); }
        if ($this->aes !== null) {
            $this->pending .= $s;
            $whole = (intdiv(strlen($this->pending), 16) - 1) * 16; // the last whole block kept back: it may be the padded one
            if ($whole <= 0) { return; }
            $s = $this->aes->update(substr($this->pending, 0, $whole));
            $this->pending = substr($this->pending, $whole);
        }
        $this->keep($s);
    }

    /** Appends $s to the blocks. */
    private function keep(string $s): void
    {
        for ($p = 0, $n = strlen($s); $p < $n;) {
            $b = intdiv($this->length, self::BLOCK);
            if (!isset($this->blocks[$b])) {
                $mem = Mem::alloc(self::BLOCK);
                $this->blocks[$b] = [$mem, Mem::addr($mem)];
            }
            $at = $this->length - $b * self::BLOCK;
            $len = min(self::BLOCK - $at, $n - $p);
            Mem::write($this->blocks[$b][1] + $at, substr($s, $p, $len));
            $this->length += $len;
            $p += $len;
        }
    }

    /** The transfer ended with $result (a CURLcode) and $status: done, gone on from where it broke, or failed. @internal the loop's callback */
    public function ended(int $result, int $status, string $url): void
    {
        $this->easy = null;
        $this->end($result, $status, $url);
        if (($this->done || $this->error !== null) && $this->finished !== null) { ($this->finished)(); }
    }

    /** Done, gone on from where it broke, or failed. */
    private function end(int $result, int $status, string $url): void
    {
        if (in_array($result, self::BROKEN, true) && $status < 300 && $this->retries < self::RETRIES) {
            $wait = 0.5 * 2 ** $this->retries++;
            $self = \WeakReference::create($this);
            CurlLoop::after($wait, static function () use ($self): void { $self->get()?->fetch(); });
            return;
        }
        if ($result !== 0 || $status >= 300) {
            $this->error = "myphp: {$this->url}: " . ($status >= 300 ? "HTTP $status" : (string)Curl::ffi()->curl_easy_strerror($result));
            return;
        }
        if ($this->aes !== null) {
            if (strlen($this->pending) % 16 !== 0 || $this->pending === '') {
                $this->error = "myphp: {$this->url}: an encrypted segment of no whole blocks";
                return;
            }
            try {
                $this->keep(Aes::unpad($this->aes->update($this->pending)));
            } catch (RuntimeException $e) {
                $this->error = "{$e->getMessage()} ({$this->url})";
                return;
            }
            $this->pending = '';
        }
        $this->effective = $url;
        $this->total = $this->length;
        $this->done = true;
    }

    /** Throws what failed. */
    private function fail(): void
    {
        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }
    }

    public function __destruct()
    {
        if ($this->easy !== null) {
            CurlLoop::stop($this->easy);
            Curl::ffi()->curl_easy_cleanup($this->easy);
            if ($this->list !== null) { Curl::ffi()->curl_slist_free_all($this->list); }
        }
        foreach ($this->blocks as [$mem]) { Mem::free($mem); }
    }
}
