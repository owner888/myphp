<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A film of sources apart played as one (yt-dlp's bestvideo+bestaudio: a
 * video's URL and an audio's, as mpv plays one with the other as its
 * audio-file): their tracks together (renumbered, the second's from 1000),
 * their frames in the order of their times, a seek of each, the caches of
 * both. Its duration the longest, its chapters the first's.
 */
final class Merged implements Source, Cache
{
    /** @var list<Track> */
    private array $tracks = [];
    /** @var array<int, array{0: int, 1: Track}> each track's source and the track as its source has it, by the track's object id */
    private array $of = [];
    /** @var array<int, ?Packet> each source's next frame, read and not yet given */
    private array $heads = [];
    /** @var array<int, true> the sources whose next() gave null at their end */
    private array $done = [];
    /** @var array<int, array<int, Track>> each source's tracks as this has them, by its own track's object id */
    private array $own = [];

    /** @param list<Source> $sources @param list<Cache> $caches what reads them over the network */
    public function __construct(private readonly array $sources, private readonly array $caches = [])
    {
        $this->share(new CacheBudget()); // one cache of the film, IINA's 150 MiB, not one each
        foreach ($sources as $k => $s) {
            foreach ($s->tracks() as $t) {
                $mine = new Track($k * 1000 + $t->number, $t->type, $t->codec, $t->private, $t->language, $t->name, $t->isDefault,
                    $t->frameDuration, $t->width, $t->height, $t->sampleRate, $t->channels, $t->strip);
                $this->tracks[] = $mine;
                $this->of[spl_object_id($mine)] = [$k, $t];
                $this->own[$k][spl_object_id($t)] = $mine;
            }
        }
    }

    public function tracks(): array
    {
        return $this->tracks;
    }

    public function duration(): float
    {
        return max(array_map(fn(Source $s) => $s->duration(), $this->sources));
    }

    public function chapters(): array
    {
        return $this->sources[0]->chapters();
    }

    /** Each source's own: the tracks of it as it has them. */
    public function choose(?Track $video, ?Track $audio): void
    {
        foreach ($this->sources as $k => $s) {
            $mine = fn(?Track $t) => $t !== null && ($this->of[spl_object_id($t)][0] ?? null) === $k ? $this->of[spl_object_id($t)][1] : null;
            $s->choose($mine($video), $mine($audio));
        }
    }

    /** Source $k's next frame, as this has its track; null while it is not here, or at its end. */
    private function head(int $k): ?Packet
    {
        if (!array_key_exists($k, $this->heads) || $this->heads[$k] === null) {
            if (isset($this->done[$k])) { return null; }
            $p = $this->sources[$k]->next();
            if ($p === null) {
                if ($this->sources[$k]->ready()) { $this->done[$k] = true; }
                return null;
            }
            $t = $this->own[$k][spl_object_id($p->track)] ?? null;
            $this->heads[$k] = $t === null ? null : new Packet($t, $p->time, $p->duration, $p->keyframe, $p->data, $p->sample);
            if ($t === null) { return $this->head($k); } // a track it did not list
        }
        return $this->heads[$k];
    }

    public function ready(): bool
    {
        foreach (array_keys($this->sources) as $k) {
            if ($this->head($k) === null && !isset($this->done[$k])) { return false; }
        }
        return true;
    }

    public function next(): ?Packet
    {
        $best = null;
        foreach (array_keys($this->sources) as $k) {
            $p = $this->head($k);
            if ($p === null) {
                if (!isset($this->done[$k])) { return null; } // not here yet: the other waits for it, in the order of the times
                continue;
            }
            if ($best === null || $p->time < $this->heads[$best]->time) { $best = $k; }
        }
        if ($best === null) {
            return null;
        }
        $p = $this->heads[$best];
        $this->heads[$best] = null;
        return $p;
    }

    public function seek(float $seconds): void
    {
        $this->heads = []; $this->done = [];
        foreach ($this->sources as $s) { $s->seek($seconds); }
    }

    public function keyframe(float $seconds): ?Packet
    {
        foreach ($this->sources as $k => $s) {
            foreach ($s->tracks() as $t) {
                if ($t->type === Track::VIDEO) {
                    $p = $s->keyframe($seconds);
                    $this->heads = []; $this->done = []; // next() goes on only after a seek()
                    return $p === null ? null : new Packet($this->own[$k][spl_object_id($p->track)], $p->time, $p->duration, $p->keyframe, $p->data, $p->sample);
                }
            }
        }
        return null;
    }

    public function cached(): int
    {
        return array_sum(array_map(fn(Cache $c) => $c->cached(), $this->caches));
    }

    public function speed(): float
    {
        return array_sum(array_map(fn(Cache $c) => $c->speed(), $this->caches));
    }

    public function share(CacheBudget $b): void
    {
        foreach ($this->caches as $c) { $c->share($b); }
    }
}
