<?php
declare(strict_types=1);

namespace MyPHP\Media;

use FFI;
use FFI\CData;
use MyPHP\Native\{Curl, CurlLoop, Mem};
use RuntimeException;

/**
 * A film's bytes over HTTP(S), as mpv's stream and demuxer cache read
 * them: one transfer at a time reads on from where the reader is (a Range
 * request), into a cache of blocks of BLOCK bytes (what it brought of the
 * block it fills read as it comes), never waiting, driven
 * by the run loop (Native\CurlLoop). Its first request (from 0) says the
 * size and whether the server takes ranges. A read of what is not near
 * where the transfer is starts another there; the transfer stops once the
 * cache holds CacheBudget::$max bytes ahead of the reader, and goes on as it reads.
 * Past it in all, the blocks least recently read go (IINA's 150 MiB),
 * never those ahead of the reader not read yet; of a film of readers
 * sharing it (CacheBudget: a video's and an audio's), of them all.
 * The blocks are of native memory, as mpv's cache is: PHP's memory_limit
 * (128 MiB by default) counts none of it.
 */
final class HttpBytes implements Bytes, CacheMember
{
    /** A block of the cache; IINA's defaultCacheSize, 153600 KiB. */
    public const BLOCK = 256 << 10;
    /** @internal UI\MoviePlayerState: the User-Agent the films over the network are asked for with (IINA's userAgent, mpv's user-agent), '' for none; HLS's segments too (Download). */
    public static string $userAgent = '';
    /** How far ahead of the transfer a read may be and wait for it rather than start another. */
    private const NEAR = 2 << 20;
    /** CURLOPT_RANGE, CURLOPT_CONNECTTIMEOUT; CURLE_WRITE_ERROR, what a transfer stopped by its callback ends with. */
    private const OPT_RANGE = 10007, OPT_CONNECTTIMEOUT = 78, E_WRITE = 23;
    /** CURLOPT_LOW_SPEED_LIMIT and _TIME: a transfer of under a byte a second for STALL seconds ends (CURLE_OPERATION_TIMEDOUT), gone on from where it was. */
    private const OPT_LOW_SPEED_LIMIT = 19, OPT_LOW_SPEED_TIME = 20, STALL = 20;
    /**
     * What a connection that broke ends with, which a transfer goes on from
     * where it was, as ffmpeg's reconnect does (mpv's network streams):
     * CURLE_COULDNT_CONNECT, PARTIAL_FILE, OPERATION_TIMEDOUT, GOT_NOTHING,
     * SEND_ERROR, RECV_ERROR, HTTP2_STREAM; RETRIES times, waiting 0.5 s,
     * 1 s, 2 s, before what failed is told.
     */
    private const BROKEN = [7, 18, 28, 52, 55, 56, 92], RETRIES = 3;
    /** The times in a row the transfer went on from where a broken connection left it. */
    private int $retries = 0;

    private int $size = -1;
    private bool $ranges = false;
    /** @var array<int, array{0: CData, 1: int, 2: int}> the blocks here, by their index: their memory, its address and their length (the last of the file's may be short) */
    private array $blocks = [];
    /** @var array<int, int> when each block was last read, by its index (a counter) */
    private array $used = [];
    private int $cached = 0;
    /** The cache it is of: its own, or the film's of its readers. */
    private CacheBudget $budget;
    /** Where the reader was last: the cache reads ahead of it. */
    private int $reader = 0;
    /** The transfer: its handle, its callbacks, where its next byte goes, the block it fills, whether it is to stop. @var ?array{easy: CData, cbs: CData, at: int, part: string, stop: bool, list: ?CData} */
    private ?array $transfer = null;
    private ?string $error = null;
    /** @var array<int, int> bytes that came in each tenth of a second, by it (microtime × 10), the last second's: the speed */
    private array $arrivals = [];
    /** Bytes that came in all. */
    private int $received = 0;

    /** The bytes a request asks for at most (whole blocks), 0 for all from where it starts. */
    private readonly int $chunk;

    /**
     * Reads $url, each request with $headers too (name => value: yt-dlp's
     * http_headers, a User-Agent, a Referer, the cookies a site wants), and
     * of $chunk bytes at most, one after the other, when it is not 0
     * (yt-dlp's http_chunk_size: YouTube sends a request of a whole film at
     * the speed it plays, 10 MB ones as fast as they go).
     * @param array<string, string> $headers
     */
    public function __construct(public readonly string $url, private readonly array $headers = [], int $chunk = 0)
    {
        $this->chunk = $chunk <= 0 ? 0 : max(1, intdiv($chunk, self::BLOCK)) * self::BLOCK;
        $this->share(new CacheBudget());
        $this->fetch(0);
        CurlLoop::waitUntil(fn(): bool => $this->size >= 0 || $this->error !== null);
        $this->fail();
    }

    public function size(): int
    {
        return $this->size;
    }

    /** The bytes the cache holds. */
    public function cached(): int
    {
        return $this->cached;
    }

    /** How fast bytes came over the last second, a second. */
    public function speed(): float
    {
        $now = microtime(true);
        $this->forget($now);
        return (float)array_sum($this->arrivals);
    }

    /** Forgets the tenths of a second before the last second: the speed's are held to ten (a transfer's thousands of pieces a second kept none). */
    private function forget(float $now): void
    {
        $since = (int)($now * 10) - 10;
        foreach ($this->arrivals as $tick => $n) {
            if ($tick > $since) { break; }
            unset($this->arrivals[$tick]);
        }
    }

    public function read(int $pos, int $n): string
    {
        $n = max(0, min($n, $this->size - $pos));
        CurlLoop::waitUntil(fn(): bool => $this->has($pos, $n) || $this->error !== null);
        $this->fail();
        $out = '';
        for ($b = intdiv($pos, self::BLOCK), $end = $pos + $n; $b * self::BLOCK < $end; $b++) {
            $from = max($pos - $b * self::BLOCK, 0);
            $len = min(self::BLOCK, $end - $b * self::BLOCK) - $from;
            if (isset($this->blocks[$b])) {
                $this->used[$b] = $this->budget->tick();
                $out .= Mem::read($this->blocks[$b][1] + $from, $len);
            } else { // the block the transfer fills, as far as it came (has() said)
                $out .= substr($this->transfer['part'], $from, $len);
            }
        }
        $this->reader = $end;
        $this->readAhead();
        $this->budget->resume($this); // the film's other readers: room, maybe, as this read
        return $out;
    }

    public function has(int $pos, int $n): bool
    {
        $this->fail();
        $n = max(0, min($n, $this->size - $pos));
        for ($b = intdiv($pos, self::BLOCK), $end = $pos + $n; $b * self::BLOCK < $end; $b++) {
            if (isset($this->blocks[$b])) {
                continue;
            }
            $t = $this->transfer;
            if ($t !== null && $t['at'] === $b * self::BLOCK && $t['at'] + strlen($t['part']) >= min($end, ($b + 1) * self::BLOCK)) {
                continue; // in the block the transfer fills, as far as it came: a film starts as its first bytes come, as mpv's does
            }
            $this->ask($b * self::BLOCK);
            return false;
        }
        return true;
    }

    /** Makes sure the transfer will bring the block at $at: it goes there unless it is a little before it. */
    private function ask(int $at): void
    {
        $this->reader = $at; // where it reads next: what the cache reads ahead of, and keeps
        $t = $this->transfer;
        if ($t !== null && !$t['stop'] && $t['at'] <= $at && $at - $t['at'] < self::NEAR) {
            return;
        }
        if (!$this->ranges && $at !== 0) {
            throw new RuntimeException("myphp: {$this->url}: the server sends no part of the film alone (no ranges), and what was read of it is gone");
        }
        $this->fetch($at);
    }

    /** Goes on reading ahead of the reader while the cache has room: from the first block ahead not here. */
    private function readAhead(): void
    {
        if ($this->transfer !== null || $this->error !== null) {
            return;
        }
        $b = intdiv($this->reader, self::BLOCK);
        while (isset($this->blocks[$b])) { $b++; }
        if ($b * self::BLOCK < $this->size && $this->budget->ahead() < CacheBudget::$max / 2) {
            $this->fetch($b * self::BLOCK);
        }
    }

    public function share(CacheBudget $b): void
    {
        $this->budget = $b;
        $b->join($this);
    }

    public function resume(): void
    {
        $this->readAhead();
    }

    public function evictable(): array
    {
        $from = intdiv($this->reader, self::BLOCK); // never what lies ahead of the reader and is not read yet (the transfer stops before that is all there is)
        for ($to = $from; isset($this->blocks[$to]); $to++) {}
        $out = [];
        foreach ($this->used as $b => $when) {
            if ($b < $from || $b >= $to) {
                $out[] = [$when, function () use ($b): int { $n = $this->blocks[$b][2] ?? 0; $this->drop($b); return $n; }];
            }
        }
        return $out;
    }

    /** The bytes the cache holds from the reader on. */
    public function ahead(): int
    {
        $n = 0;
        for ($b = intdiv($this->reader, self::BLOCK); isset($this->blocks[$b]); $b++) { $n += $this->blocks[$b][2]; }
        return $n;
    }

    /** Starts the transfer from $at (a block's start), the one there was stopped. */
    private function fetch(int $at): void
    {
        $this->halt();
        $f = Curl::ffi();
        $easy = $f->curl_easy_init();
        $cbs = $f->new('myphp_curl_callbacks');
        $self = \WeakReference::create($this);
        $status = 0;
        $cbs->header = static function ($ptr, $size, $n, $user) use ($self, &$status, $at): int {
            $len = $size * $n;
            $line = rtrim(FFI::string($ptr, $len), "\r\n");
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int)$m[1];
            } elseif ($line === '' && $status >= 200 && $status < 300 && ($b = $self->get()) !== null) {
                $b->headersDone($status, $at);
            } elseif (($b = $self->get()) !== null && preg_match('~^content-(range|length):\s*(.+)$~i', $line, $m) === 1) {
                $b->header(strtolower($m[1]), trim($m[2]), $status);
            }
            return $len;
        };
        $cbs->write = static function ($ptr, $size, $n, $user) use ($self, &$status): int {
            $len = $size * $n;
            $b = $self->get();
            if ($b === null || $status < 200 || $status >= 300) { return $status >= 300 ? $len : 0; } // an error's page: read, not kept
            return $b->arrive(FFI::string($ptr, $len)) ? $len : 0; // 0 stops it
        };
        $set = fn(int $opt, mixed $v) => $f->curl_easy_setopt($easy, $opt, $v);
        $set(Curl::OPT_URL, $this->url);
        \MyPHP\Native\NetProxy::apply($easy, $this->url); // the system's proxy
        $set(Curl::OPT_NOSIGNAL, 1);
        $set(Curl::OPT_PROTOCOLS_STR, 'http,https');
        $set(Curl::OPT_REDIR_PROTOCOLS_STR, 'http,https');
        $set(Curl::OPT_FOLLOWLOCATION, 1);
        $set(Curl::OPT_MAXREDIRS, 20);
        $set(self::OPT_CONNECTTIMEOUT, 30);
        $set(self::OPT_LOW_SPEED_LIMIT, 1);
        $set(self::OPT_LOW_SPEED_TIME, self::STALL);
        $set(self::OPT_RANGE, $this->chunk > 0 ? "$at-" . ($at + $this->chunk - 1) : "$at-");
        $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
        $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
        $list = Curl::headers($this->headers);
        if ($list !== null) { $set(Curl::OPT_HTTPHEADER, $list); }
        if (self::$userAgent !== '') { $set(Curl::OPT_USERAGENT, self::$userAgent); } // a User-Agent of the headers (a page's, yt-dlp's) before it
        $this->transfer = ['easy' => $easy, 'cbs' => $cbs, 'at' => $at, 'part' => '', 'stop' => false, 'list' => $list];
        CurlLoop::start($easy, static function (int $result) use ($self, $easy, $list, &$status): void {
            $b = $self->get();
            $b?->ended($easy, $result, $status);
            Curl::ffi()->curl_easy_cleanup($easy);
            if ($list !== null) { Curl::ffi()->curl_slist_free_all($list); }
        });
    }

    /** A header of the response to the transfer from $at: its size, from a range's total or a whole film's length. @internal the transfer's callback */
    public function header(string $name, string $value, int $status): void
    {
        if ($name === 'range' && preg_match('~^bytes\s+\d+-\d+/(\d+)~i', $value, $m) === 1) {
            $this->size = (int)$m[1];
            $this->ranges = true;
        } elseif ($name === 'length' && $status === 200 && $this->size < 0) {
            $this->size = (int)$value;
        }
    }

    /** The response's head is read: a whole film (200) to a range asked from past 0 is no range, which reads from 0. @internal the transfer's callback */
    public function headersDone(int $status, int $at): void
    {
        if ($status === 200 && $this->transfer !== null) {
            $this->ranges = false;
            $this->transfer['at'] = 0;
            $this->transfer['part'] = '';
        }
        if ($this->size < 0) {
            $this->error = "myphp: {$this->url}: the server says no size of the film";
        }
    }

    /** Bytes of the transfer, kept as blocks; false stops it (the cache full ahead, or it was told to stop). @internal the transfer's callback */
    public function arrive(string $s): bool
    {
        $t = &$this->transfer;
        if ($t === null || $t['stop']) {
            return false;
        }
        $this->received += strlen($s);
        $this->retries = 0; // it comes again
        $now = microtime(true);
        $tick = (int)($now * 10);
        $this->arrivals[$tick] = ($this->arrivals[$tick] ?? 0) + strlen($s);
        if (count($this->arrivals) > 10) { $this->forget($now); }
        $t['part'] .= $s;
        $kept = false;
        while (strlen($t['part']) >= self::BLOCK) {
            $this->keep(intdiv($t['at'], self::BLOCK), substr($t['part'], 0, self::BLOCK));
            $t['part'] = substr($t['part'], self::BLOCK);
            $t['at'] += self::BLOCK;
            $kept = true;
        }
        if ($t['at'] + strlen($t['part']) >= $this->size && $t['part'] !== '') { // the film's last, short block
            $this->keep(intdiv($t['at'], self::BLOCK), $t['part']);
            $t['at'] += strlen($t['part']);
            $t['part'] = '';
            $kept = true;
        }
        if ($kept && $this->budget->ahead() >= CacheBudget::$max * 0.9) { // room for the reader: on once it read some (looked at as a block is kept, not at each piece)
            $t['stop'] = true;
            return false;
        }
        return true;
    }

    /** Keeps block $b, the least recently read going past MAX in all. */
    private function keep(int $b, string $block): void
    {
        $this->drop($b);
        $mem = Mem::alloc(self::BLOCK);
        $addr = Mem::addr($mem);
        Mem::write($addr, $block);
        $this->blocks[$b] = [$mem, $addr, strlen($block)];
        $this->cached += strlen($block);
        $this->used[$b] = $this->budget->tick();
        $this->budget->trim(); // past MAX of the film's readers in all, the least recently read go
    }

    /** Lets block $b go, its memory freed. */
    private function drop(int $b): void
    {
        if (!isset($this->blocks[$b])) {
            return;
        }
        $this->cached -= $this->blocks[$b][2];
        Mem::free($this->blocks[$b][0]);
        unset($this->blocks[$b], $this->used[$b]);
    }

    /** The transfer of $easy ended with $result (a CURLcode) and $status: stopped, done, or failed. @internal the loop's callback */
    public function ended(CData $easy, int $result, int $status): void
    {
        $mine = $this->transfer !== null && $this->transfer['easy'] === $easy;
        if ($mine) {
            $stopped = $this->transfer['stop'];
            $at = $this->transfer['at'];
            $this->transfer = null;
            if (in_array($result, self::BROKEN, true) && $status < 300 && $this->retries < self::RETRIES && $this->size >= 0) {
                $wait = 0.5 * 2 ** $this->retries++;
                $self = \WeakReference::create($this);
                CurlLoop::after($wait, static function () use ($self, $at): void { // on from the block it filled, unless another went elsewhere meanwhile
                    $b = $self->get();
                    if ($b !== null && $b->transfer === null && $b->error === null) { $b->fetch($at); }
                });
                return;
            }
            if ($result !== 0 && !($result === self::E_WRITE && $stopped)) {
                $this->error = "myphp: {$this->url}: " . ($status >= 300 ? "HTTP $status" : (string)Curl::ffi()->curl_easy_strerror($result));
            } elseif ($status >= 300) {
                $this->error = "myphp: {$this->url}: HTTP $status";
            } elseif ($result === 0 && $this->chunk > 0) {
                $this->readAhead(); // a chunk done: the next, while the cache has room
            }
        }
    }

    /** Stops the transfer there is. */
    private function halt(): void
    {
        if ($this->transfer === null) {
            return;
        }
        ['easy' => $easy, 'list' => $list] = $this->transfer;
        $this->transfer = null;
        CurlLoop::stop($easy);
        Curl::ffi()->curl_easy_cleanup($easy);
        if ($list !== null) { Curl::ffi()->curl_slist_free_all($list); }
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
        $this->halt();
        foreach (array_keys($this->blocks) as $b) { $this->drop($b); }
    }
}
