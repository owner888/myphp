<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * Reads a Matroska or WebM file (EBML): its tracks, its duration, its
 * cues, and its frames one after the other in the order they are stored,
 * each a Packet of one track, and seeks to a time through the cues. It
 * decodes nothing: the system's decoders take the packets (Native\Video).
 * A cluster is read element by element, each block as its bytes come
 * (a film's over HTTP); one of unknown size ends at the next top-level
 * element.
 */
final class Matroska implements Source
{
    private const EBML = 0x1A45DFA3, SEGMENT = 0x18538067, SEEK_HEAD = 0x114D9B74, SEEK = 0x4DBB, SEEK_ID = 0x53AB, SEEK_POSITION = 0x53AC;
    private const INFO = 0x1549A966, TIMESTAMP_SCALE = 0x2AD7B1, DURATION = 0x4489;
    private const TRACKS = 0x1654AE6B, TRACK_ENTRY = 0xAE, TRACK_NUMBER = 0xD7, TRACK_TYPE = 0x83, CODEC_ID = 0x86, CODEC_PRIVATE = 0x63A2,
        LANGUAGE = 0x22B59C, NAME = 0x536E, DEFAULT_DURATION = 0x23E383, FLAG_DEFAULT = 0x88, VIDEO = 0xE0, PIXEL_WIDTH = 0xB0, PIXEL_HEIGHT = 0xBA,
        AUDIO = 0xE1, SAMPLING_FREQUENCY = 0xB5, CHANNELS = 0x9F, CONTENT_ENCODINGS = 0x6D80, CONTENT_ENCODING = 0x6240, CONTENT_COMPRESSION = 0x5034,
        CONTENT_COMP_ALGO = 0x4254, CONTENT_COMP_SETTINGS = 0x4255;
    private const CUES = 0x1C53BB6B, CUE_POINT = 0xBB, CUE_TIME = 0xB3, CUE_TRACK_POSITIONS = 0xB7, CUE_TRACK = 0xF7, CUE_CLUSTER_POSITION = 0xF1;
    private const CHAPTERS = 0x1043A770, EDITION_ENTRY = 0x45B9, EDITION_FLAG_DEFAULT = 0x45DB, CHAPTER_ATOM = 0xB6, CHAPTER_TIME_START = 0x91,
        CHAPTER_FLAG_HIDDEN = 0x98, CHAPTER_FLAG_ENABLED = 0x4598, CHAPTER_DISPLAY = 0x80, CHAP_STRING = 0x85;
    private const CLUSTER = 0x1F43B675, TIMESTAMP = 0xE7, SIMPLE_BLOCK = 0xA3, BLOCK_GROUP = 0xA0, BLOCK = 0xA1, BLOCK_DURATION = 0x9B, REFERENCE_BLOCK = 0xFB;
    /** The size of an element whose size is unknown (all its bits set), as a cluster being written live has. */
    private const UNKNOWN = -1;

    private Bytes $bytes;
    /** Where the segment's data starts, which positions in it count from, and where it ends. */
    private int $segment = 0, $segmentEnd = 0;
    /** Nanoseconds a timestamp unit. */
    private int $scale = 1000000;
    /** @var list<Track> */
    private array $tracks = [];
    private float $duration = 0;
    /** @var list<Chapter> */
    private array $chapters = [];
    /** @var list<array{0: float, 1: int}> */
    private array $cues = [];
    /** Where the next element of the segment is read from (a cluster, else what is skipped). */
    private int $at = 0;
    /** The cluster being read, element by element (its blocks as their bytes come, a film's over HTTP): where it ends, -1 for none; where its next element is; its timestamp. */
    private int $inCluster = -1, $clusterAt = 0, $base = 0;
    /** @var list<Packet> the frames of the cluster read, not yet taken */
    private array $pending = [];
    /** The time of the cue a seek went to: the frames of its cluster before it are left out, its keyframe the first of the video. */
    private float $from = -INF;

    /** Reads the file at $path, or $bytes (a film's over HTTP, HttpBytes). */
    public function __construct(string|Bytes $source)
    {
        $this->bytes = $source instanceof Bytes ? $source : new FileBytes($source);
        $path = $source instanceof HttpBytes ? $source->url : (is_string($source) ? $source : 'the film');
        [$id, $size, $data] = $this->header(0);
        if ($id !== self::EBML) {
            throw new RuntimeException("$path is no Matroska file");
        }
        [$id, $size, $this->segment] = $this->header($data + $size);
        if ($id !== self::SEGMENT) {
            throw new RuntimeException("$path has no segment");
        }
        $this->segmentEnd = $size === self::UNKNOWN ? $this->bytes->size() : min($this->segment + $size, $this->bytes->size());
        $seen = [];
        $first = 0; // the first cluster
        for ($p = $this->segment; $p < $this->segmentEnd;) {
            [$id, $size, $data] = $this->header($p);
            if ($id === self::CLUSTER) {
                $first = $p;
                break;
            }
            $this->top($id, $data, $size, $seen);
            if ($size === self::UNKNOWN) {
                throw new RuntimeException('an element of unknown size before the clusters');
            }
            $p = $data + $size;
        }
        foreach ($seen['seek'] ?? [] as [$id, $pos]) { // what the seek head points to past the first cluster: the cues, usually
            if (!isset($seen[$id])) {
                [$eid, $size, $data] = $this->header($pos);
                if ($eid === $id && $size !== self::UNKNOWN) {
                    $this->top($id, $data, $size, $seen);
                }
            }
        }
        if ($this->tracks === []) {
            throw new RuntimeException("$path has no tracks");
        }
        $this->at = $first;
    }

    /** Reads top-level element $id of $size bytes at $data, noting it in $seen. @param array<int|string, mixed> $seen */
    private function top(int $id, int $data, int $size, array &$seen): void
    {
        switch ($id) {
            case self::SEEK_HEAD:
                foreach (self::children($this->read($data, $size)) as [$cid, $c]) {
                    if ($cid === self::SEEK) {
                        $e = self::fields($c);
                        if (isset($e[self::SEEK_ID], $e[self::SEEK_POSITION])) {
                            $seen['seek'][] = [self::uint($e[self::SEEK_ID]), $this->segment + self::uint($e[self::SEEK_POSITION])];
                        }
                    }
                }
                break;
            case self::INFO:
                $e = self::fields($this->read($data, $size));
                $this->scale = isset($e[self::TIMESTAMP_SCALE]) ? self::uint($e[self::TIMESTAMP_SCALE]) : 1000000;
                $this->duration = isset($e[self::DURATION]) ? self::float($e[self::DURATION]) * $this->scale / 1e9 : 0.0;
                break;
            case self::TRACKS:
                foreach (self::children($this->read($data, $size)) as [$cid, $c]) {
                    if ($cid === self::TRACK_ENTRY) {
                        $this->tracks[] = self::track($c);
                    }
                }
                break;
            case self::CUES:
                $this->readCues($this->read($data, $size));
                break;
            case self::CHAPTERS:
                $this->chapters = self::readChapters($this->read($data, $size));
                break;
        }
        $seen[$id] = true;
    }

    private static function track(string $s): Track
    {
        $e = self::fields($s);
        $video = isset($e[self::VIDEO]) ? self::fields($e[self::VIDEO]) : [];
        $audio = isset($e[self::AUDIO]) ? self::fields($e[self::AUDIO]) : [];
        $strip = '';
        if (isset($e[self::CONTENT_ENCODINGS])) {
            foreach (self::children($e[self::CONTENT_ENCODINGS]) as [$cid, $enc]) {
                $comp = $cid === self::CONTENT_ENCODING ? (self::fields($enc)[self::CONTENT_COMPRESSION] ?? null) : null;
                if ($comp !== null) {
                    $c = self::fields($comp);
                    $algo = isset($c[self::CONTENT_COMP_ALGO]) ? self::uint($c[self::CONTENT_COMP_ALGO]) : 0;
                    if ($algo !== 3) { // header stripping is the only compression muxers write to frames now
                        throw new RuntimeException("a track compressed with algorithm $algo");
                    }
                    $strip = $c[self::CONTENT_COMP_SETTINGS] ?? '';
                }
            }
        }
        return new Track(
            number: self::uint($e[self::TRACK_NUMBER] ?? ''),
            type: match (self::uint($e[self::TRACK_TYPE] ?? '')) { 1 => Track::VIDEO, 2 => Track::AUDIO, 17 => Track::SUBTITLE, default => Track::OTHER },
            codec: $e[self::CODEC_ID] ?? '',
            private: $e[self::CODEC_PRIVATE] ?? '',
            language: rtrim($e[self::LANGUAGE] ?? 'eng', "\0"),
            name: $e[self::NAME] ?? '',
            isDefault: !isset($e[self::FLAG_DEFAULT]) || self::uint($e[self::FLAG_DEFAULT]) === 1,
            frameDuration: isset($e[self::DEFAULT_DURATION]) ? self::uint($e[self::DEFAULT_DURATION]) / 1e9 : 0.0,
            width: isset($video[self::PIXEL_WIDTH]) ? self::uint($video[self::PIXEL_WIDTH]) : 0,
            height: isset($video[self::PIXEL_HEIGHT]) ? self::uint($video[self::PIXEL_HEIGHT]) : 0,
            sampleRate: isset($audio[self::SAMPLING_FREQUENCY]) ? self::float($audio[self::SAMPLING_FREQUENCY]) : 0.0,
            channels: isset($audio[self::CHANNELS]) ? self::uint($audio[self::CHANNELS]) : 0,
            strip: $strip,
        );
    }

    /** The cues of the first video track, or of the first track cued when no video is. */
    private function readCues(string $s): void
    {
        $video = null;
        foreach ($this->tracks as $t) {
            if ($t->type === Track::VIDEO) { $video = $t->number; break; }
        }
        $by = [];
        foreach (self::children($s) as [$cid, $point]) {
            if ($cid !== self::CUE_POINT) {
                continue;
            }
            $time = 0; $positions = [];
            foreach (self::children($point) as [$pid, $p]) {
                if ($pid === self::CUE_TIME) {
                    $time = self::uint($p);
                } elseif ($pid === self::CUE_TRACK_POSITIONS) {
                    $e = self::fields($p);
                    $positions[self::uint($e[self::CUE_TRACK] ?? '')] = self::uint($e[self::CUE_CLUSTER_POSITION] ?? '');
                }
            }
            foreach ($positions as $track => $pos) {
                $by[$track][] = [$time * $this->scale / 1e9, $this->segment + $pos];
            }
        }
        $this->cues = $by[$video ?? array_key_first($by)] ?? [];
        usort($this->cues, fn(array $a, array $b): int => $a[0] <=> $b[0]);
    }

    /**
     * The chapters of the default edition (else the first), as mpv lists
     * them: its top-level atoms but the hidden and disabled ones, each
     * titled by its first display, by their start (ChapterTimeStart is in
     * nanoseconds, whatever the timestamp scale).
     * @return list<Chapter>
     */
    private static function readChapters(string $s): array
    {
        $editions = [];
        foreach (self::children($s) as [$id, $e]) {
            if ($id === self::EDITION_ENTRY) { $editions[] = $e; }
        }
        $chosen = $editions[0] ?? '';
        foreach ($editions as $e) {
            $f = self::fields($e);
            if (isset($f[self::EDITION_FLAG_DEFAULT]) && self::uint($f[self::EDITION_FLAG_DEFAULT]) === 1) { $chosen = $e; break; }
        }
        $out = [];
        foreach (self::children($chosen) as [$id, $atom]) {
            if ($id !== self::CHAPTER_ATOM) {
                continue;
            }
            $f = self::fields($atom);
            if ((isset($f[self::CHAPTER_FLAG_HIDDEN]) && self::uint($f[self::CHAPTER_FLAG_HIDDEN]) === 1)
                || (isset($f[self::CHAPTER_FLAG_ENABLED]) && self::uint($f[self::CHAPTER_FLAG_ENABLED]) === 0)) {
                continue;
            }
            $display = isset($f[self::CHAPTER_DISPLAY]) ? self::fields($f[self::CHAPTER_DISPLAY]) : [];
            $out[] = new Chapter(rtrim($display[self::CHAP_STRING] ?? '', "\0"), self::uint($f[self::CHAPTER_TIME_START] ?? '') / 1e9);
        }
        usort($out, fn(Chapter $a, Chapter $b): int => $a->start <=> $b->start);
        return $out;
    }

    public function tracks(): array
    {
        return $this->tracks;
    }

    public function chapters(): array
    {
        return $this->chapters;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    /** The cues of the first video track (or of any): a time in seconds and the position of its cluster in the file, by time. @return list<array{0: float, 1: int}> */
    public function cues(): array
    {
        return $this->cues;
    }

    /** The next frame in the file, of any track, or null at its end: blocks holding several frames (lacing) give one packet a frame. */
    public function next(): ?Packet
    {
        while ($this->pending === []) {
            if (!$this->ready() || !$this->cluster()) { // what is not here yet (ready() says which), or the end
                return null;
            }
        }
        return array_shift($this->pending);
    }

    /** Goes to the last cue at or before $seconds, a keyframe of the video, or the first for a time before it (a film's first frame a moment in, at 0.023 s): next() goes on from it, the frames before it in its cluster left out. */
    public function seek(float $seconds): void
    {
        $lo = 0; $hi = count($this->cues) - 1; $found = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->cues[$mid][0] <= $seconds) { $found = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        if ($this->cues === []) {
            throw new RuntimeException('no cues to seek by');
        }
        $found = max($found, 0);
        $this->at = $this->cues[$found][1];
        $this->from = $this->cues[$found][0];
        $this->pending = [];
        $this->inCluster = -1;
    }

    /**
     * The keyframe of the video at the cue at or before $seconds, read
     * alone: the cluster's elements' headers read one by one, the blocks of
     * other tracks skipped, only the video's first block read (1.5 ms less
     * than its cluster of megabytes, a thumbnail of 1080p).
     */
    /** All its tracks are read: they are of one file. */
    public function choose(?Track $video, ?Track $audio): void {}

    public function keyframe(float $seconds): ?Packet
    {
        $video = null;
        foreach ($this->tracks as $t) {
            if ($t->type === Track::VIDEO) { $video = $t; break; }
        }
        if ($video === null) {
            return null;
        }
        $this->seek($seconds);
        for ($at = $this->at; $at < $this->segmentEnd;) {
            [$id, $size, $data] = $this->header($at);
            $end = $size === self::UNKNOWN ? $this->segmentEnd : $data + $size;
            if ($id === self::CLUSTER) {
                $base = 0;
                for ($p = $data; $p < $end;) {
                    [$cid, $csize, $cdata] = $this->header($p);
                    if ($csize === self::UNKNOWN) {
                        throw new RuntimeException("an element of unknown size in the cluster at $at");
                    }
                    if ($cid === self::TIMESTAMP) {
                        $base = self::uint($this->read($cdata, $csize));
                    } elseif (($cid === self::SIMPLE_BLOCK || $cid === self::BLOCK_GROUP) && $this->blockTrack($cid, $cdata) === $video->number) {
                        $this->pending = [];
                        if ($cid === self::SIMPLE_BLOCK) {
                            $this->block($this->read($cdata, $csize), $base, null, null);
                        } else {
                            $e = self::fields($this->read($cdata, $csize));
                            $this->block($e[self::BLOCK], $base, isset($e[self::BLOCK_DURATION]) ? self::uint($e[self::BLOCK_DURATION]) : null, !isset($e[self::REFERENCE_BLOCK]));
                        }
                        foreach ($this->pending as $q) {
                            if ($q->keyframe) { $this->pending = []; return $q; }
                        }
                    }
                    $p = $cdata + $csize;
                }
            } elseif ($size === self::UNKNOWN) {
                break;
            }
            $at = $end;
        }
        $this->pending = [];
        return null;
    }

    /** The track of the block of element $id (a SimpleBlock, or a BlockGroup whose first child is its Block) whose data is at $pos; 0 for a group of none first. */
    private function blockTrack(int $id, int $pos): int
    {
        if ($id === self::BLOCK_GROUP) {
            [$cid, , $pos] = $this->header($pos);
            if ($cid !== self::BLOCK) {
                return 0;
            }
        }
        return self::vint($this->read($pos, 8), 0)[0];
    }

    /**
     * Reads the next element: a cluster's head, or one of its elements, its
     * blocks' frames into $pending, or a top-level one skipped; false at the
     * end of the segment. A cluster of unknown size (a live one's) ends at
     * the next top-level element.
     */
    private function cluster(): bool
    {
        if ($this->inCluster < 0) {
            if ($this->at >= $this->segmentEnd) {
                return false;
            }
            [$id, $size, $data] = $this->header($this->at);
            if ($id !== self::CLUSTER) { // what follows the clusters (cues, tags), or something between them
                if ($size === self::UNKNOWN) {
                    return false;
                }
                $this->at = $data + $size;
                return true;
            }
            $this->inCluster = $size === self::UNKNOWN ? $this->segmentEnd : min($data + $size, $this->segmentEnd);
            $this->clusterAt = $data;
            $this->base = 0;
            return true;
        }
        if ($this->clusterAt >= $this->inCluster) {
            $this->at = $this->inCluster;
            $this->inCluster = -1;
            return true;
        }
        [$cid, $csize, $cdata] = $this->header($this->clusterAt);
        if ($cid > 0xFFFFFF || $csize === self::UNKNOWN) { // a top-level element (its id of four bytes): the end of a cluster of unknown size
            $this->at = $this->clusterAt;
            $this->inCluster = -1;
            return true;
        }
        $this->clusterAt = $cdata + $csize;
        if ($cid === self::TIMESTAMP) {
            $this->base = self::uint($this->read($cdata, $csize));
        } elseif ($cid === self::SIMPLE_BLOCK) {
            $this->block($this->read($cdata, $csize), $this->base, null, null);
        } elseif ($cid === self::BLOCK_GROUP) {
            $e = self::fields($this->read($cdata, $csize));
            if (isset($e[self::BLOCK])) {
                $this->block($e[self::BLOCK], $this->base, isset($e[self::BLOCK_DURATION]) ? self::uint($e[self::BLOCK_DURATION]) : null, !isset($e[self::REFERENCE_BLOCK]));
            }
        }
        return true;
    }

    /** The frames of block $b of a cluster at $base, its duration in units if given, keyframe as a block group says (null: the flags', a SimpleBlock's). */
    private function block(string $b, int $base, ?int $duration, ?bool $key): void
    {
        [$track, $n] = self::vint($b, 0);
        $rel = unpack('n', $b, $n)[1];
        if ($rel >= 0x8000) { $rel -= 0x10000; }
        $flags = ord($b[$n + 2]);
        $p = $n + 3;
        $t = null;
        foreach ($this->tracks as $tr) {
            if ($tr->number === $track) { $t = $tr; break; }
        }
        if ($t === null) {
            return;
        }
        $frames = self::frames($b, $p, ($flags >> 1) & 3);
        $time = ($base + $rel) * $this->scale / 1e9;
        $each = $duration !== null ? $duration * $this->scale / 1e9 / count($frames) : $t->frameDuration;
        foreach ($frames as $k => $data) {
            if ($time + $k * $each < $this->from - 1e-6) { // before the cue a seek went to, in its cluster: a cluster holds several cues
                continue;
            }
            $this->pending[] = new Packet($t, $time + $k * $each, $each, $key ?? ($flags & 0x80) !== 0, $t->strip . $data);
        }
    }

    /** The frames of a block from $p on, by its lacing: none (0), Xiph (1), fixed (2) or EBML (3). @return list<string> */
    private static function frames(string $b, int $p, int $lacing): array
    {
        if ($lacing === 0) {
            return [substr($b, $p)];
        }
        $count = ord($b[$p++]) + 1;
        $sizes = [];
        if ($lacing === 1) {
            for ($k = 0; $k < $count - 1; $k++) {
                $s = 0;
                do { $byte = ord($b[$p++]); $s += $byte; } while ($byte === 255);
                $sizes[] = $s;
            }
        } elseif ($lacing === 3) {
            [$s, $n] = self::vint($b, $p); $p += $n; $sizes[] = $s;
            for ($k = 1; $k < $count - 1; $k++) {
                [$d, $n] = self::vint($b, $p); $p += $n;
                $s += $d - ((1 << (7 * $n - 1)) - 1); // a signed difference: the vint less half its range
                $sizes[] = $s;
            }
        }
        $left = strlen($b) - $p;
        if ($lacing === 2) {
            $sizes = array_fill(0, $count - 1, intdiv($left, $count));
        }
        $sizes[] = $left - array_sum($sizes);
        $out = [];
        foreach ($sizes as $s) {
            $out[] = substr($b, $p, $s);
            $p += $s;
        }
        return $out;
    }

    /** The id, size (UNKNOWN when unknown) and where the data starts of the element at $pos of the file. @return array{0: int, 1: int, 2: int} */
    private function header(int $pos): array
    {
        $h = $this->read($pos, 12);
        if (strlen($h) < 2) {
            throw new RuntimeException("no element at $pos");
        }
        [$id, $n] = self::vint($h, 0, true);
        [$size, $m] = self::vint($h, $n);
        return [$id, $size === (1 << (7 * $m)) - 1 ? self::UNKNOWN : $size, $pos + $n + $m];
    }

    private function read(int $pos, int $n): string
    {
        return $this->bytes->read($pos, $n);
    }

    /**
     * Whether next() reads only what is here: the frames read, or the next
     * element's head and, for one of a cluster's it reads (a block, its
     * timestamp), its bytes; false asks for them (a film over HTTP: a block
     * at a time, as it comes), true at the end.
     */
    public function ready(): bool
    {
        if ($this->pending !== []) {
            return true;
        }
        $at = $this->inCluster < 0 ? $this->at : $this->clusterAt;
        if ($at >= ($this->inCluster < 0 ? $this->segmentEnd : $this->inCluster)) {
            return true;
        }
        if (!$this->bytes->has($at, 12)) {
            return false;
        }
        [$id, $size, $data] = $this->header($at);
        $reads = $this->inCluster >= 0 && in_array($id, [self::TIMESTAMP, self::SIMPLE_BLOCK, self::BLOCK_GROUP], true);
        return !$reads || $size === self::UNKNOWN || $this->bytes->has($data, $size);
    }

    /**
     * The variable-length integer at $p of $s and its length: an id keeps
     * its length marker, a size or a number loses it.
     * @return array{0: int, 1: int}
     */
    private static function vint(string $s, int $p, bool $id = false): array
    {
        $b = ord($s[$p] ?? "\0");
        if ($b === 0) {
            throw new RuntimeException('an invalid EBML number');
        }
        $n = 8 - (int)floor(log($b, 2));
        $v = $id ? $b : $b & ((1 << (8 - $n)) - 1);
        for ($k = 1; $k < $n; $k++) {
            $v = ($v << 8) | ord($s[$p + $k]);
        }
        return [$v, $n];
    }

    /** The child elements of $s, each [id, data]. @return \Generator<int, array{0: int, 1: string}> */
    private static function children(string $s): \Generator
    {
        $len = strlen($s);
        for ($p = 0; $p < $len;) {
            [$id, $n] = self::vint($s, $p, true);
            [$size, $m] = self::vint($s, $p + $n);
            yield [$id, substr($s, $p + $n + $m, $size)];
            $p += $n + $m + $size;
        }
    }

    /** The child elements of $s by id, the first of each. @return array<int, string> */
    private static function fields(string $s): array
    {
        $out = [];
        foreach (self::children($s) as [$id, $c]) {
            $out[$id] ??= $c;
        }
        return $out;
    }

    private static function uint(string $s): int
    {
        $v = 0;
        for ($k = 0, $n = strlen($s); $k < $n; $k++) {
            $v = ($v << 8) | ord($s[$k]);
        }
        return $v;
    }

    private static function float(string $s): float
    {
        return match (strlen($s)) { 4 => unpack('G', $s)[1], 8 => unpack('E', $s)[1], default => 0.0 };
    }

}
