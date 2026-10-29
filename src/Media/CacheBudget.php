<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The one cache of a film read over the network by several readers (a
 * video's and an audio's, yt-dlp's, played as one: Merged), as IINA's
 * 150 MiB is the whole film's (mpv's demuxer cache, of all its streams):
 * what they hold ahead counted together, a reader reading ahead while
 * all of it is under the limit, the least recently read of them all going
 * past it, and the others going on as one reads.
 *
 * @internal Media\HttpBytes, Media\Hls, Media\Merged
 */
final class CacheBudget
{
    /** @internal UI\VideoPlayerState: the bytes the cache holds, of the films opened next: IINA's defaultCacheSize (153600 KiB), mpv's demuxer-max-bytes. */
    public static int $max = 153600 << 10;
    /** @internal UI\VideoPlayerState: and the seconds it reads ahead at the most, of a stream of segments (HLS's): IINA's secPrefech, mpv's cache-secs. */
    public static int $seconds = 36000;

    /** @var array<int, \WeakReference<CacheMember>> */
    private array $members = [];
    /** When a block was read, of them all: the least recently read go first. */
    private int $clock = 0;

    public function join(CacheMember $m): void
    {
        $this->members[spl_object_id($m)] = \WeakReference::create($m);
    }

    /** @return list<CacheMember> */
    private function live(): array
    {
        $out = [];
        foreach ($this->members as $id => $ref) {
            $m = $ref->get();
            if ($m === null) { unset($this->members[$id]); continue; }
            $out[] = $m;
        }
        return $out;
    }

    /** The bytes all of them hold ahead of their readers. */
    public function ahead(): int
    {
        return array_sum(array_map(fn(CacheMember $m) => $m->ahead(), $this->live()));
    }

    /** A time later than every other: a block's, read or kept now. */
    public function tick(): int
    {
        return ++$this->clock;
    }

    /** Past MAX in all, the least recently read of them all go, to 90% of it. */
    public function trim(): void
    {
        $members = $this->live();
        $total = array_sum(array_map(fn(CacheMember $m) => $m->cached(), $members));
        if ($total <= self::$max) {
            return;
        }
        $all = [];
        foreach ($members as $m) {
            foreach ($m->evictable() as [$when, $drop]) { $all[] = [$when, $drop]; }
        }
        usort($all, fn(array $a, array $b) => $a[0] <=> $b[0]);
        foreach ($all as [, $drop]) {
            if ($total <= self::$max * 0.9) { break; }
            $total -= $drop();
        }
    }

    /** One of them read on ($from): the others read ahead again, as there may be room now. */
    public function resume(CacheMember $from): void
    {
        foreach ($this->live() as $m) {
            if ($m !== $from) { $m->resume(); }
        }
    }
}
