<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\Native\CurlLoop;
use RuntimeException;

/**
 * A media playlist of HLS (RFC 8216) and its segments, read one after the
 * other as FFmpeg's hls demuxer reads them: each segment downloaded ahead
 * of the one read while the cache has room (Hls::MAX, of all the streams),
 * decrypted (AES-128), demuxed (Media\Ts, or Media\Fragment of its init
 * segment's Mp4), its frames made the stream's tracks and timed on the
 * playlist's line (Hls::align). A live playlist (no EXT-X-ENDLIST) is
 * read again every target duration (half of it when nothing was added, as
 * FFmpeg does) and starts LIVE_START segments from its end.
 *
 * @internal Media\Hls
 */
final class HlsStream
{
    /** The segment of a live playlist from its end it starts at (FFmpeg's live_start_index, -3). */
    public const LIVE_START = 3;

    /**
     * @var array<int, array{uri: string, duration: float, start: float, offset: int, length: ?int, key: ?array{0: string, 1: ?string}, map: ?array{0: string, 1: int, 2: ?int}, dseq: int}>
     * the segments, by their sequence numbers: their URI, duration, start on the line, byte range, key (its URI and IV), init segment, discontinuity sequence
     */
    private array $segments = [];
    private bool $live = false;
    private float $target = 1.0;
    /** The segment read. */
    private int $seq = 0;
    private Ts|Fragment|PackedAudio|null $reader = null;
    /** @var array<int, Download> the segments downloaded and coming, from the one read on, by sequence number */
    private array $downloads = [];
    /** @var array<string, Download> the keys, by URI */
    private array $keys = [];
    /** @var array<string, array{0: Download, 1: ?Mp4}> the init segments and what they say, by URI and range */
    private array $inits = [];
    private ?Packet $head = null;
    /** @var array<int, float> the last frame's duration of each track, by the number of the segments' own */
    private array $durations = [];
    /** @var array<int, Track> the tracks the frames are of, by the number of the segments' own (a PID, an MP4 track id) */
    private array $tracks = [];
    private bool $active = false;
    private ?string $error = null;
    /** The reading again of a live playlist, while it comes. */
    private ?Download $reloading = null;

    /** The media playlist at $url ($text, read from it, $url the address it came from) of $hls. */
    public function __construct(private readonly Hls $hls, private string $url, string $text)
    {
        $this->merge($text, true);
        if ($this->segments === []) {
            throw new RuntimeException("myphp: $url: a playlist of no segments");
        }
        $this->seq = $this->live ? max(array_key_first($this->segments), array_key_last($this->segments) - self::LIVE_START + 1) : array_key_first($this->segments);
        if ($this->live) { // the line's 0 where it starts: what plays first
            $shift = $this->segments[$this->seq]['start'];
            foreach ($this->segments as &$s) { $s['start'] -= $shift; }
            unset($s);
            $this->reloadLater($this->target);
        }
    }

    /** $ref, relative to $base, as an absolute URL (RFC 3986 5.2). */
    public static function resolve(string $base, string $ref): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref) === 1) {
            return $ref;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'http') . '://' . (isset($b['user']) ? $b['user'] . (isset($b['pass']) ? ":{$b['pass']}" : '') . '@' : '') . ($b['host'] ?? '') . (isset($b['port']) ? ":{$b['port']}" : '');
        if (str_starts_with($ref, '//')) {
            return ($b['scheme'] ?? 'http') . ':' . $ref;
        }
        if ($ref === '') {
            return $base;
        }
        if ($ref[0] === '?') {
            return $origin . ($b['path'] ?? '/') . $ref;
        }
        $path = $ref[0] === '/' ? $ref : preg_replace('~[^/]*$~', '', $b['path'] ?? '/') . $ref;
        [$path, $rest] = array_pad(preg_split('~(?=[?#])~', $path, 2), 2, '');
        $out = [];
        foreach (explode('/', $path) as $i => $part) { // the dot segments taken out
            if ($part === '..') { if (count($out) > 1) { array_pop($out); } }
            elseif ($part !== '.') { $out[] = $part; }
        }
        if (in_array(basename($path), ['.', '..'], true)) { $out[] = ''; }
        return $origin . implode('/', $out) . $rest;
    }

    /** The attributes of a tag (NAME=VALUE, quoted or not, by commas). @return array<string, string> */
    public static function attributes(string $s): array
    {
        preg_match_all('~([A-Z0-9-]+)=("[^"]*"|[^,]*)~', $s, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $k, $v]) { $out[$k] = trim($v, '"'); }
        return $out;
    }

    /**
     * The segments of playlist $text: on reading it again, those after the
     * ones there are added, each starting where the one before ends, and
     * those that left it before the one read go.
     */
    private function merge(string $text, bool $first): void
    {
        if (!str_starts_with(ltrim($text, "\xEF\xBB\xBF \t\r\n"), '#EXTM3U')) {
            throw new RuntimeException("myphp: {$this->url}: no playlist (#EXTM3U)");
        }
        $seq = 0; $dseq = 0; $duration = null; $range = null; $key = null; $map = null;
        $ends = []; // where each URI's last range ended: a range of no offset starts there
        $parsed = [];
        $ended = false;
        foreach (preg_split('~\r?\n~', $text) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if ($line[0] !== '#') {
                $uri = self::resolve($this->url, $line);
                [$len, $off] = $range ?? [null, null];
                $off ??= $len === null ? 0 : ($ends[$uri] ?? 0);
                if ($len !== null) { $ends[$uri] = $off + $len; }
                $parsed[$seq] = ['uri' => $uri, 'duration' => $duration ?? $this->target, 'start' => 0.0, 'offset' => $off, 'length' => $len, 'dseq' => $dseq,
                    'key' => $key === null ? null : [$key[0], $key[1] ?? pack('N4', 0, 0, 0, $seq)], 'map' => $map];
                $seq++; $duration = null; $range = null;
                continue;
            }
            [$tag, $value] = array_pad(explode(':', $line, 2), 2, '');
            switch ($tag) {
                case '#EXT-X-TARGETDURATION': $this->target = max(0.1, (float)$value); break;
                case '#EXT-X-MEDIA-SEQUENCE': $seq = (int)$value; break;
                case '#EXT-X-DISCONTINUITY-SEQUENCE': $dseq = (int)$value; break;
                case '#EXT-X-DISCONTINUITY': $dseq++; break;
                case '#EXT-X-ENDLIST': $ended = true; break;
                case '#EXTINF': $duration = (float)$value; break;
                case '#EXT-X-BYTERANGE':
                    [$n, $o] = array_pad(explode('@', $value, 2), 2, null);
                    $range = [(int)$n, $o === null ? null : (int)$o];
                    break;
                case '#EXT-X-KEY':
                    $a = self::attributes($value);
                    $method = $a['METHOD'] ?? 'NONE';
                    if ($method === 'NONE') { $key = null; break; }
                    if ($method !== 'AES-128') {
                        throw new RuntimeException("myphp: {$this->url}: segments encrypted with $method (only AES-128 plays)");
                    }
                    $iv = isset($a['IV']) ? hex2bin(str_pad(substr(preg_replace('~^0x~i', '', $a['IV']), -32), 32, '0', STR_PAD_LEFT)) : null;
                    $key = [self::resolve($this->url, $a['URI'] ?? ''), $iv === false ? null : $iv];
                    break;
                case '#EXT-X-MAP':
                    $a = self::attributes($value);
                    [$n, $o] = isset($a['BYTERANGE']) ? array_pad(explode('@', $a['BYTERANGE'], 2), 2, '0') : [null, '0'];
                    $map = [self::resolve($this->url, $a['URI'] ?? ''), (int)$o, $n === null ? null : (int)$n];
                    break;
            }
        }
        $this->live = !$ended;
        if ($first) {
            $start = 0.0;
            foreach ($parsed as $s => $seg) { $seg['start'] = $start; $start += $seg['duration']; $this->segments[$s] = $seg; }
            return;
        }
        $last = array_key_last($this->segments);
        foreach ($parsed as $s => $seg) {
            if ($s <= $last) { continue; }
            $prev = $this->segments[$s - 1] ?? $this->segments[$last];
            $seg['start'] = $prev['start'] + $prev['duration'];
            $this->segments[$s] = $seg;
            $last = $s;
        }
        $oldest = array_key_first($parsed);
        foreach (array_keys($this->segments) as $s) {
            if ($oldest !== null && $s < $oldest && $s < $this->seq) { unset($this->segments[$s]); }
        }
    }

    /** Reads the live playlist again in $seconds. */
    private function reloadLater(float $seconds): void
    {
        $self = \WeakReference::create($this);
        CurlLoop::after($seconds, static function () use ($self): void { $self->get()?->reload(); });
    }

    /** Reads the live playlist again: what it added goes after what there is. */
    private function reload(): void
    {
        if (!$this->live || $this->reloading !== null) {
            return;
        }
        $self = \WeakReference::create($this);
        $this->reloading = new Download($this->url, finished: static function () use ($self): void { $self->get()?->reloaded(); });
    }

    /** The playlist read again came: merged, the next reading set (half the target duration when it added nothing). */
    private function reloaded(): void
    {
        $d = $this->reloading;
        $this->reloading = null;
        $before = array_key_last($this->segments);
        try {
            $this->merge($d->text(), false);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            return;
        }
        if ($this->live) {
            $this->reloadLater(array_key_last($this->segments) === $before ? $this->target / 2 : $this->target);
        }
        $this->pumpQuietly();
    }

    public function live(): bool
    {
        return $this->live;
    }

    /** The playlist's length: its segments'. */
    public function duration(): float
    {
        $d = 0.0;
        foreach ($this->segments as $s) { $d += $s['duration']; }
        return $d;
    }

    /** Starts reading: its first segment downloading (all the streams' at once, before any is probed). */
    public function start(): void
    {
        $this->active = true;
        $this->pump();
    }

    /**
     * The tracks of its segments, as they are, and where the first it reads
     * starts: its discontinuity sequence and what its earliest frame's time
     * of the stream's own is ahead of its start on the line (FFmpeg's
     * start_time); waited for, as far into the first segment as a frame of
     * each track (not all of it: a segment of 1080p is megabytes).
     * @return array{0: list<Track>, 1: int, 2: float}
     */
    public function probe(): array
    {
        $seg = $this->segments[$this->seq];
        CurlLoop::waitUntil(fn(): bool => isset($this->downloads[$this->seq]) || $this->error !== null); // its key first, when it has one
        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }
        $d = $this->downloads[$this->seq];
        $mp4 = $seg['map'] === null ? null : $this->init($seg['map'], true);
        $reader = null;
        CurlLoop::waitUntil(function () use (&$reader, $d, $seg): bool { return $d->over() || ($reader = $this->reader($d, $seg['map'])) !== null; });
        $reader ??= $this->reader($d, $seg['map']) ?? throw new RuntimeException("myphp: {$seg['uri']}: an empty segment");
        $tracks = $mp4?->tracks() ?? [];
        $first = [];
        // Until a frame of each track came (of a TS, each stream of its program's track made). Not an arrow function: it
        // would hold $first as it was, empty, and read the whole segment.
        while (match (true) {
            $mp4 !== null => count($first) < count($tracks),
            $reader instanceof Ts => !$reader->probed() || count($first) < count($reader->tracks()),
            default => $first === [],
        }) {
            // Until the next frame is here: what fails is thrown by ready() after, not in the loop's callback.
            CurlLoop::waitUntil(function () use ($reader, $d): bool { try { return $d->over() || $reader->ready(); } catch (RuntimeException) { return true; } });
            if (!$reader->ready() || ($p = $reader->next()) === null) {
                break; // the segment's end
            }
            if ($mp4 === null) { $tracks[$p->track->number] ??= $p->track; }
            $first[$p->track->number] = min($first[$p->track->number] ?? INF, $p->time);
        }
        if ($tracks === [] || $first === []) {
            throw new RuntimeException("myphp: {$seg['uri']}: no stream in it the player reads");
        }
        return [array_values($tracks), $seg['dseq'], min($first) - $seg['start']];
    }

    /** The tracks its frames are given as, by the number of the segments' own. @param array<int, Track> $tracks */
    public function own(array $tracks): void
    {
        $this->tracks = $tracks;
    }

    /** What init segment $map says, null while it comes ($wait: waited for). @param array{0: string, 1: int, 2: ?int} $map */
    private function init(array $map, bool $wait = false): ?Mp4
    {
        $id = "{$map[0]}@{$map[1]}";
        $this->inits[$id] ??= [new Download($map[0], $map[1], $map[2], null, null, $this->pumper()), null];
        if ($wait) { $this->inits[$id][0]->wait(); }
        if ($this->inits[$id][1] === null && $this->inits[$id][0]->done()) {
            $this->inits[$id][1] = new Mp4($this->inits[$id][0]);
        }
        return $this->inits[$id][1];
    }

    /**
     * The reader of segment $d: of fMP4 (its init segment $map), MPEG-TS,
     * or packed audio, as its first bytes say (FFmpeg probes them); null
     * while they, or the init segment, are not here.
     * @param ?array{0: string, 1: int, 2: ?int} $map
     */
    private function reader(Download $d, ?array $map): Ts|Fragment|PackedAudio|null
    {
        if ($map !== null) {
            return $this->init($map)?->fragment($d);
        }
        if (!$d->has(0, 3)) {
            return null;
        }
        return PackedAudio::is($d->read(0, 3)) ? new PackedAudio($d) : new Ts($d, true);
    }

    /** What tells it a download ended: on to the next. */
    private function pumper(): \Closure
    {
        $self = \WeakReference::create($this);
        return static function () use ($self): void { $self->get()?->pumpQuietly(); };
    }

    /** pump() from a transfer's end: what fails is told by peek(), not thrown in the loop's callback. */
    private function pumpQuietly(): void
    {
        try {
            $this->pump();
        } catch (RuntimeException $e) {
            $this->error ??= $e->getMessage();
        }
    }

    /** Whether it is read: those not read download nothing. */
    public function activate(bool $on): void
    {
        $this->active = $on;
        if (!$on) {
            $this->downloads = []; $this->reader = null; $this->head = null;
        }
    }

    /**
     * Downloads the next segment, one at a time, while the cache holds
     * less than 90% of Hls::MAX ahead of what is read (mpv's demuxer cache
     * reads ahead so): its key first, when it has one not here.
     */
    private function pump(): void
    {
        if (!$this->active) {
            return;
        }
        foreach ($this->downloads as $d) { if (!$d->over()) { return; } }
        $next = $this->downloads === [] ? $this->seq : array_key_last($this->downloads) + 1;
        if (!isset($this->segments[$next]) || ($this->downloads !== [] && $this->hls->ahead() >= Hls::MAX * 0.9)) {
            return;
        }
        $seg = $this->segments[$next];
        $key = null;
        if ($seg['key'] !== null) {
            $k = $this->keys[$seg['key'][0]] ??= new Download($seg['key'][0], finished: $this->pumper());
            if (!$k->done()) { return; }
            $key = [$k->text(), $seg['key'][1]];
        }
        if ($seg['map'] !== null) { $this->init($seg['map']); }
        $hls = $this->hls;
        $this->downloads[$next] = new Download($seg['uri'], $seg['offset'], $seg['length'], $key, static function (int $n) use ($hls): void { $hls->count($n); }, $this->pumper());
    }

    /** The bytes its segments hold from the one read on. */
    public function held(): int
    {
        $n = 0;
        foreach ($this->downloads as $d) { $n += $d->cached(); }
        return $n;
    }

    /** Goes to the segment $seconds is in (the first, before them; a live one's last, past them). */
    public function seek(float $seconds): void
    {
        $at = array_key_first($this->segments);
        foreach ($this->segments as $s => $seg) {
            if ($seg['start'] <= $seconds + 1e-6) { $at = $s; } else { break; }
        }
        $this->seq = $at;
        $this->reader = null; $this->head = null;
        foreach (array_keys($this->downloads) as $s) { if ($s !== $at) { unset($this->downloads[$s]); } }
        $this->pump();
    }

    /** Whether it read all there is (a live one never has). */
    public function ended(): bool
    {
        return !$this->live && $this->head === null && $this->reader === null && !isset($this->segments[$this->seq]);
    }

    /** The next frame, left to be taken (take()); null while it is not here, or at the end. */
    public function peek(): ?Packet
    {
        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }
        while ($this->head === null) {
            if ($this->reader === null) {
                if (!isset($this->segments[$this->seq])) {
                    return null; // the end, or a live one's next not listed yet
                }
                $this->pump();
                $d = $this->downloads[$this->seq] ?? null;
                if ($d === null) {
                    return null;
                }
                $this->reader = $this->reader($d, $this->segments[$this->seq]['map']);
                if ($this->reader === null) { return null; }
            }
            if (!$this->reader->ready()) {
                return null;
            }
            $p = $this->reader->next();
            if ($p === null) { // the segment's end: on to the next
                unset($this->downloads[$this->seq]);
                $this->seq++;
                $this->reader = null;
                $this->pump();
                continue;
            }
            $track = $this->tracks[$p->track->number] ?? null;
            if ($track === null) { continue; }
            $seg = $this->segments[$this->seq];
            // A segment's last frame lasts as the one before (a segment of one frame says nothing of it): of the segment before.
            $duration = $p->duration > 0 ? $p->duration : ($this->durations[$p->track->number] ?? 0.0);
            $this->durations[$p->track->number] = $duration;
            $time = $this->hls->align($seg['dseq'], $seg['start'], $p->time, $duration, $this->reader instanceof Fragment ? 0.0 : Ts::WRAP / Ts::CLOCK);
            $this->head = new Packet($track, $time, $duration, $p->keyframe, $p->data);
        }
        return $this->head;
    }

    /** The frame peek() gave, taken. */
    public function take(): Packet
    {
        $p = $this->head ?? throw new RuntimeException('myphp: nothing peeked');
        $this->head = null;
        return $p;
    }
}
