<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * HTTP Live Streaming (RFC 8216) read in PHP, as FFmpeg's hls demuxer and
 * so mpv and IINA read it: a master playlist's variant of the highest
 * bandwidth (mpv's hls-bitrate=max), its media playlist, and the audio
 * renditions of its group that have playlists of their own, each a track
 * (EXT-X-MEDIA's language, name, default), only the one played downloaded
 * (choose()). The segments are MPEG-TS or fragmented MP4, AES-128 or not,
 * a live playlist read again as it grows (Media\HlsStream). The frames of
 * the streams are given in the order of their times; the times are each
 * stream's own lined up on the playlists' (align()).
 */
final class Hls implements Source, CacheMember
{

    /** @var list<HlsStream> the variant's playlist, then the audio renditions' */
    private array $streams = [];
    /** @var list<Track> */
    private array $tracks = [];
    /** @var array<int, int> the stream of each track, by the track's object id */
    private array $owner = [];
    /** @var array<int, true> the streams read, by their place */
    private array $active = [0 => true];
    /** @var array<int, float> what the streams' own times less are the line's, by discontinuity sequence */
    private array $bases = [];
    /** @var array<int, int> bytes that came each tenth of a second, the last second's (HttpBytes's speed) */
    private array $arrivals = [];
    private float $duration;
    /** Where the frames given end, on the line, since the last seek. */
    private float $end = -INF;

    /** Reads the playlist at $url: a master playlist, or a media one; each request with $headers too (name => value: yt-dlp's http_headers). @param array<string, string> $headers */
    public function __construct(public readonly string $url, public readonly array $headers = [])
    {
        $this->share(new CacheBudget());
        $d = Download::get($url, $headers);
        $text = $d->text();
        $renditions = [];
        $audio = []; // the renditions of playlists of their own: read with the variant's, at once
        if (str_contains($text, '#EXT-X-STREAM-INF')) {
            [$variant, $renditions] = self::master($text, $d->effectiveUrl());
            $d = new Download($variant, headers: $headers);
            foreach ($renditions as $r) {
                if (isset($r['URI'])) { $audio[] = [$r, new Download($r['URI'], headers: $headers)]; }
            }
            $text = $d->text();
        }
        $this->streams[] = new HlsStream($this, $d->effectiveUrl(), $text);
        foreach ($audio as [$r, $pd]) {
            $this->streams[] = new HlsStream($this, $pd->effectiveUrl(), $pd->text());
        }
        $muxed = array_values(array_filter($renditions, fn(array $r) => !isset($r['URI'])))[0] ?? null; // the variant's own sound, named
        $named = array_filter($renditions, fn(array $r) => isset($r['URI']) && strcasecmp($r['DEFAULT'] ?? '', 'YES') === 0) !== [];
        foreach ($this->streams as $stream) { $stream->start(); } // their first segments together
        foreach ($this->streams as $k => $stream) {
            $own = [];
            [$tracks, $dseq, $ahead] = $stream->probe();
            $this->bases[$dseq] = min($this->bases[$dseq] ?? INF, $ahead); // the earliest of the streams at 0, as FFmpeg's start_time
            foreach ($tracks as $t) {
                $r = $k === 0 ? ($t->type === Track::AUDIO ? $muxed : null) : $audio[$k - 1][0];
                $default = $r === null ? ($t->type !== Track::AUDIO || !$named) : strcasecmp($r['DEFAULT'] ?? '', 'YES') === 0;
                $own[$t->number] = $mine = new Track($k * 1000 + $t->number, $t->type, $t->codec, $t->private, $r['LANGUAGE'] ?? $t->language, $r['NAME'] ?? $t->name,
                    $default, $t->frameDuration, $t->width, $t->height, $t->sampleRate, $t->channels, colors: $t->colors);
                $this->tracks[] = $mine;
                $this->owner[spl_object_id($mine)] = $k;
            }
            $stream->own($own);
        }
        $this->duration = $this->streams[0]->live() ? 0.0 : $this->streams[0]->duration();
    }

    /**
     * A master playlist's variant of the highest bandwidth (the first of
     * them), and the audio renditions of its group: their attributes, their
     * URIs made absolute from the master playlist's.
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private static function master(string $text, string $url): array
    {
        $media = []; $best = null; $inf = null;
        foreach (preg_split('~\r?\n~', $text) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if (str_starts_with($line, '#EXT-X-MEDIA:')) {
                $a = HlsStream::attributes(substr($line, 13));
                if (isset($a['URI'])) { $a['URI'] = HlsStream::resolve($url, $a['URI']); } // the master's, not the variant's (Apple's are beside it, the variant in a folder)
                if (($a['TYPE'] ?? '') === 'AUDIO') { $media[$a['GROUP-ID'] ?? ''][] = $a; }
            } elseif (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $inf = HlsStream::attributes(substr($line, 18));
            } elseif ($line[0] !== '#' && $inf !== null) {
                if ($best === null || (int)($inf['BANDWIDTH'] ?? 0) > (int)($best[0]['BANDWIDTH'] ?? 0)) { $best = [$inf, HlsStream::resolve($url, $line)]; }
                $inf = null;
            }
        }
        if ($best === null) {
            throw new RuntimeException("myphp: $url: a master playlist of no variant");
        }
        return [$best[1], isset($best[0]['AUDIO']) ? $media[$best[0]['AUDIO']] ?? [] : []];
    }

    /**
     * $raw, the time of a frame of $duration of a stream's own, of a
     * segment of discontinuity sequence $dseq that starts at $start on the
     * line, on the line: less what the earliest frame of the sequence's
     * first segment read was ahead of its start (the streams of a sequence
     * share their clock); a sequence come to as it plays (past a
     * discontinuity) going on from where the frames given end, should the
     * segments before have been longer than their playlist said; near
     * $start by a wrap of $wrap seconds (MPEG-TS's 33 bits) when it has one.
     */
    public function align(int $dseq, float $start, float $raw, float $duration, float $wrap): float
    {
        $t = $raw - ($this->bases[$dseq] ??= $raw - max($start, $this->end));
        if ($wrap > 0) {
            while ($t < $start - $wrap / 2) { $t += $wrap; }
            while ($t > $start + $wrap / 2) { $t -= $wrap; }
        }
        $this->end = max($this->end, $t + $duration);
        return $t;
    }

    /** $n bytes came, of a segment: the speed's. @internal Media\HlsStream */
    public function count(int $n): void
    {
        $tick = (int)(microtime(true) * 10);
        $this->arrivals[$tick] = ($this->arrivals[$tick] ?? 0) + $n;
        if (count($this->arrivals) > 10) { $this->forget(); }
    }

    /** The tenths of a second before the last second, forgotten. */
    private function forget(): void
    {
        $since = (int)(microtime(true) * 10) - 10;
        foreach ($this->arrivals as $tick => $n) {
            if ($tick > $since) { break; }
            unset($this->arrivals[$tick]);
        }
    }

    /** The cache it is of: its own, or the film's of its readers (yt-dlp's video and audio, one an HLS's). */
    private CacheBudget $budget;

    public function share(CacheBudget $b): void
    {
        $this->budget = $b;
        $b->join($this);
    }

    /** The bytes the film's readers hold ahead in all: those its streams download to. @internal Media\HlsStream */
    public function shared(): int
    {
        return $this->budget->ahead();
    }

    /** A stream read on: the film's other readers read ahead again. @internal Media\HlsStream */
    public function released(): void
    {
        $this->budget->resume($this);
    }

    public function resume(): void
    {
        foreach ($this->streams as $s) { $s->resume(); }
    }

    /** Its segments go as they are read, not as the cache fills. */
    public function evictable(): array
    {
        return [];
    }

    /** The bytes the streams hold ahead of what they read. */
    public function ahead(): int
    {
        $n = 0;
        foreach ($this->streams as $s) { $n += $s->held(); }
        return $n;
    }

    public function cached(): int
    {
        return $this->ahead();
    }

    public function speed(): float
    {
        $this->forget();
        return (float)array_sum($this->arrivals);
    }

    /** Whether it is live: a playlist that grows, of no end yet. */
    public function live(): bool
    {
        return $this->streams[0]->live();
    }

    public function tracks(): array
    {
        return $this->tracks;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function chapters(): array
    {
        return [];
    }

    /** Reads the streams of $video and $audio (the variant's, without them), the others not. */
    public function choose(?Track $video, ?Track $audio): void
    {
        $this->active = [];
        foreach ([$video, $audio] as $t) {
            if ($t !== null && isset($this->owner[spl_object_id($t)])) { $this->active[$this->owner[spl_object_id($t)]] = true; }
        }
        if ($this->active === []) { $this->active = [0 => true]; }
        foreach ($this->streams as $k => $s) { $s->activate(isset($this->active[$k])); }
    }

    public function ready(): bool
    {
        foreach (array_keys($this->active) as $k) {
            if ($this->streams[$k]->peek() === null && !$this->streams[$k]->ended()) {
                return false;
            }
        }
        return true;
    }

    public function next(): ?Packet
    {
        $best = null; $at = INF;
        foreach (array_keys($this->active) as $k) {
            $p = $this->streams[$k]->peek();
            if ($p === null) {
                if (!$this->streams[$k]->ended()) { return null; } // not here yet: the others wait for it, in the order of the times
                continue;
            }
            if ($p->time < $at) { [$best, $at] = [$k, $p->time]; }
        }
        return $best === null ? null : $this->streams[$best]->take();
    }

    public function seek(float $seconds): void
    {
        $this->end = -INF;
        foreach (array_keys($this->active) as $k) { $this->streams[$k]->seek($seconds); }
    }

    public function keyframe(float $seconds): ?Packet
    {
        $this->seek($seconds);
        while ($this->ready() && ($p = $this->next()) !== null) {
            if ($p->track->type === Track::VIDEO && $p->keyframe) {
                return $p;
            }
        }
        return null;
    }
}
