<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\Native\CurlLoop;
use RuntimeException;

/**
 * MP4 and QuickTime files (ISO base media, ISO/IEC 14496-12) read in PHP
 * through their Bytes, as FFmpeg's mov demuxer reads them: the movie box
 * (moov, at the start or the end), each track's sample entry, its samples'
 * sizes, places, decode times, composition offsets and sync samples (the
 * sample table, stbl), the edit list's shift; the samples of all tracks
 * read in the order they are stored, each as its bytes come (a film's
 * over HTTP). Codecs are named as Matroska names them, their decoder
 * configuration (avcC, hvcC, av1C, an AudioSpecificConfig, an OpusHead,
 * FLAC's metadata) as Matroska's CodecPrivate holds it, so that the
 * player decodes them as it does Matroska's.
 */
final class Mp4 implements Source
{
    /** The sample entries of the codecs the player decodes, by their four characters: Matroska's codec ids. */
    private const VIDEO = ['avc1' => 'V_MPEG4/ISO/AVC', 'avc3' => 'V_MPEG4/ISO/AVC', 'hvc1' => 'V_MPEGH/ISO/HEVC', 'hev1' => 'V_MPEGH/ISO/HEVC', 'vp09' => 'V_VP9', 'av01' => 'V_AV1'];
    private const AUDIO = ['mp4a' => 'A_AAC', 'ac-3' => 'A_AC3', 'ec-3' => 'A_EAC3', '.mp3' => 'A_MPEG/L3', 'Opus' => 'A_OPUS', 'fLaC' => 'A_FLAC'];
    /** The boxes holding boxes that the tables are found in. */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'udta', 'tref', 'dinf'];

    private Bytes $bytes;
    private float $duration = 0.0;
    /** @var list<Track> */
    private array $tracks = [];
    /** @var list<Chapter> */
    private array $chapters = [];
    /**
     * Each track's samples, by the track's place in $tracks: its media
     * timescale; offsets ('P*'), sizes ('V*'), presentation times in the
     * timescale ('q*'), packed; its sync samples (null: all are), by index.
     * @var list<array{scale: int, offsets: string, sizes: string, times: string, durations: string, sync: ?array<int, true>, keys: ?list<int>, count: int}>
     */
    private array $samples = [];
    /** The samples of all tracks in the order stored: each a track's place and a sample's index, packed ('CV' each, 5 bytes). */
    private string $order = '';
    private int $count = 0;
    /** The next sample read, of $order. */
    private int $at = 0;
    /**
     * What a fragment's samples are read by (fragment()), by the track's
     * id: its track, timescale, edit list's shift, and the defaults of
     * its samples the movie extends (trex: duration, size, flags).
     * @var array<int, array{track: Track, scale: int, shift: int, duration: int, size: int, flags: int}>
     */
    private array $fragmentTracks = [];
    /** A fragmented film's (its movie extended: mvex): its samples in the fragments after the movie box, read by $fragment from $first on. */
    private bool $fragmented = false;
    private int $first = 0;
    private ?Fragment $fragment = null;
    /**
     * Where a fragmented film's fragments start and when (seconds, the
     * reference track's presentation): its segment index's (sidx), else
     * the movie fragments found as a seek looked for them.
     * @var list<array{0: int, 1: float, 2: bool}> offset, time, whether it starts with a keyframe (SAP)
     */
    private array $index = [];
    private bool $indexed = false;

    /** Reads the file at $path, or $bytes (a film's over HTTP). */
    public function __construct(string|Bytes $source)
    {
        $this->bytes = $source instanceof Bytes ? $source : new FileBytes($source);
        $name = $source instanceof HttpBytes ? $source->url : (is_string($source) ? $source : 'the film');
        $moov = null;
        for ($p = 0, $end = $this->bytes->size(); $p + 8 <= $end;) { // the top-level boxes: the movie's, wherever it is
            [$type, $size, $data] = $this->box($p, $end);
            if ($type === 'moov') { $moov = $this->bytes->read($data, $p + $size - $data); $p += $size; break; }
            if ($size <= 0) { break; }
            $p += $size;
        }
        if ($moov === null) {
            throw new RuntimeException("$name has no movie box (moov): no MP4 file, or one cut short");
        }
        $this->movie($moov);
        if ($this->tracks === []) {
            throw new RuntimeException("$name has no track the player reads");
        }
        if ($this->fragmented) {
            $this->fragments($p, $end);
        }
        $this->interleave();
    }

    /**
     * A fragmented film's (DASH's, YouTube's): past the movie box, its
     * segment index (sidx: each subsegment's offset, duration, whether it
     * starts at a keyframe), its first movie fragment; its duration the
     * index's, when the movie says none.
     */
    private function fragments(int $p, int $end): void
    {
        while ($p + 8 <= $end) {
            [$type, $size, $data] = $this->box($p, $end);
            if ($type === 'moof' || $size <= 0) { break; }
            if ($type === 'sidx' && !$this->indexed) { $this->sidx($this->bytes->read($data, $p + $size - $data), $p + $size); }
            $p += $size;
        }
        $this->first = $p;
        $this->fragment = new Fragment($this->bytes, $this->fragmentTracks, $p);
        if ($this->duration <= 0 && $this->index !== []) {
            $this->duration = $this->sidxEnd;
        } elseif ($this->duration <= 0) { // neither the movie nor an index says: the fragments walked, the last one's end (FFmpeg reads them all as it opens)
            CurlLoop::waitUntil(fn(): bool => $this->fragmentAt(INF) !== null);
            $last = $this->index === [] ? null : (new Fragment($this->bytes, $this->fragmentTracks, $this->index[count($this->index) - 1][0]))->end();
            $this->duration = $last ?? 0.0;
        }
    }

    /**
     * Where the fragment a seek to $seconds reads from starts: the last of
     * the index starting at a keyframe at or before it (FFmpeg's mov seeks
     * by its sidx so), its own first video sample's flags looked at (a
     * muxer's sidx marks every subsegment SAP, FFmpeg's of fragments cut by
     * duration too); without an index, the movie fragments walked from the
     * first, their video's tfdt read (each kept), as far as $seconds. Null
     * while what it reads is not here (asked for): never waited for, the
     * player's seek going on at once, as it does over the network (a seek
     * that waited, its slider dragged, waited in each of its frames).
     */
    private function fragmentAt(float $seconds): ?int
    {
        $video = null;
        foreach ($this->fragmentTracks as $id => $t) { if ($t['track']->type === Track::VIDEO) { $video = $id; break; } }
        if (!$this->indexed) {
            $id = $video ?? array_key_first($this->fragmentTracks);
            $t = $this->fragmentTracks[$id];
            $this->walked ??= $this->first;
            for ($end = $this->bytes->size(); $this->walked + 8 <= $end && ($this->index === [] || $this->index[count($this->index) - 1][1] <= $seconds);) {
                if (!$this->bytes->has($this->walked, 16)) { return null; }
                [$type, $size] = $this->box($this->walked, $end);
                if ($size <= 0) { break; }
                if ($type === 'moof') {
                    $first = Fragment::first($this->bytes, $this->walked, $id, $t['scale'], $t['flags']);
                    if ($first === false) { return null; }
                    if ($first === null) { break; }
                    $this->index[] = [$this->walked, $first[0] - $t['shift'] / $t['scale'], $first[1] || $t['track']->type !== Track::VIDEO];
                    $this->checked[count($this->index) - 1] = true;
                }
                $this->walked += $size;
            }
        }
        for ($i = count($this->index) - 1; $i >= 0; $i--) {
            [$offset, $time, $key] = $this->index[$i];
            if ($time > $seconds + 1e-6 || !$key) { continue; }
            if ($video !== null && !isset($this->checked[$i])) {
                $t = $this->fragmentTracks[$video];
                $first = Fragment::first($this->bytes, $offset, $video, $t['scale'], $t['flags']);
                if ($first === false) { return null; }
                $this->checked[$i] = true;
                $this->index[$i][2] = $first === null || $first[1];
                if (!$this->index[$i][2]) { continue; }
            }
            return $offset;
        }
        return $this->first;
    }

    /** Where walking the fragments goes on (a film of no index), null before it began; the time a seek goes to, until its fragment is found. */
    private ?int $walked = null;
    private ?float $seekTo = null;

    /** The fragment of the seek asked found, reading from it: whether it is (false: what it reads is not here yet). */
    private function sought(): bool
    {
        if ($this->seekTo === null) {
            return true;
        }
        $at = $this->fragmentAt($this->seekTo);
        if ($at === null) {
            return false;
        }
        $this->fragment = new Fragment($this->bytes, $this->fragmentTracks, $at);
        $this->seekTo = null;
        return true;
    }

    /** @var array<int, true> the entries of $index whose video's first sample was looked at, by their place */
    private array $checked = [];

    /** Where the segment index ends, in seconds. */
    private float $sidxEnd = 0.0;

    /** A segment index (ISO/IEC 14496-12 8.16.3) ending at $anchor: its subsegments, of the track it references, as $index. */
    private function sidx(string $d, int $anchor): void
    {
        $v1 = ord($d[0]) === 1;
        $id = unpack('N', $d, 4)[1];
        $scale = max(1, unpack('N', $d, 8)[1]);
        [$time, $offset, $p] = $v1 ? [unpack('J', $d, 12)[1], unpack('J', $d, 20)[1], 28] : [unpack('N', $d, 12)[1], unpack('N', $d, 16)[1], 20];
        $n = unpack('n', $d, $p + 2)[1];
        $p += 4;
        $t = $this->fragmentTracks[$id] ?? null;
        $shift = $t === null ? 0.0 : $t['shift'] / $t['scale'];
        $at = $anchor + $offset;
        for ($i = 0; $i < $n && $p + 12 <= strlen($d); $i++, $p += 12) {
            [$ref, $dur, $sap] = array_values(unpack('N3', $d, $p));
            if ($ref & 0x80000000) { return; } // an index of indexes: found as a seek looks for fragments instead
            $this->index[] = [$at, $time / $scale - $shift, ($sap & 0x80000000) !== 0];
            $at += $ref & 0x7fffffff;
            $time += $dur;
        }
        $this->sidxEnd = $time / $scale - $shift;
        $this->indexed = true;
    }

    /** The box at $p (before $end): its type, its whole size (to $end for a size of 0) and where its data starts. @return array{0: string, 1: int, 2: int} */
    private function box(int $p, int $end): array
    {
        $h = $this->bytes->read($p, 16);
        if (strlen($h) < 8) {
            throw new RuntimeException("myphp: a box cut short at $p");
        }
        $size = unpack('N', $h)[1];
        $type = substr($h, 4, 4);
        $data = $p + 8;
        if ($size === 1) { $size = unpack('J', $h, 8)[1]; $data += 8; }
        elseif ($size === 0) { $size = $end - $p; }
        return [$type, $size, $data];
    }

    /** The boxes of $s, from $p: each its type and its data. @return \Generator<array{0: string, 1: string}> @internal Media\Fragment */
    public static function boxes(string $s, int $p = 0): \Generator
    {
        for ($n = strlen($s); $p + 8 <= $n;) {
            $size = unpack('N', $s, $p)[1];
            $head = 8;
            if ($size === 1) { $size = unpack('J', $s, $p + 8)[1]; $head = 16; }
            elseif ($size === 0) { $size = $n - $p; }
            if ($size < $head) { return; }
            yield [substr($s, $p + 4, 4), substr($s, $p + $head, $size - $head)];
            $p += $size;
        }
    }

    /** The first box of $type in $s (from $p), its data; null for none. @internal Media\Fragment */
    public static function find(string $s, string $type, int $p = 0): ?string
    {
        foreach (self::boxes($s, $p) as [$t, $d]) {
            if ($t === $type) { return $d; }
        }
        return null;
    }

    /** The movie box: its duration, tracks, chapters. */
    private function movie(string $moov): void
    {
        $chapterTracks = [];
        $traks = [];
        $udta = null;
        $trex = [];
        foreach (self::boxes($moov) as [$type, $d]) {
            if ($type === 'mvhd') {
                [$scale, $dur] = ord($d[0]) === 1 ? [unpack('N', $d, 20)[1], unpack('J', $d, 24)[1]] : [unpack('N', $d, 12)[1], unpack('N', $d, 16)[1]];
                $this->duration = $scale > 0 ? $dur / $scale : 0.0;
                $movieScale = $scale;
            } elseif ($type === 'trak') {
                $traks[] = $d;
                $tref = self::find($d, 'tref');
                $chap = $tref === null ? null : self::find($tref, 'chap');
                if ($chap !== null && strlen($chap) >= 4) { $chapterTracks[unpack('N', $chap)[1]] = true; }
            } elseif ($type === 'udta') {
                $udta = $d;
            } elseif ($type === 'mvex') { // a fragmented film's: its samples in fragments, their defaults these
                $this->fragmented = true;
                foreach (self::boxes($d) as [$t, $x]) {
                    if ($t === 'trex' && strlen($x) >= 24) { $trex[unpack('N', $x, 4)[1]] = array_values(unpack('N3', $x, 12)); }
                    if ($t === 'mehd' && strlen($x) >= 8 && isset($movieScale)) { $this->duration = (ord($x[0]) === 1 ? unpack('J', $x, 4)[1] : unpack('N', $x, 4)[1]) / max(1, $movieScale); }
                }
            }
        }
        foreach ($traks as $d) {
            $this->track($d, $movieScale ?? 1000, $chapterTracks, $trex);
        }
        if ($this->chapters === [] && $udta !== null && ($chpl = self::find($udta, 'chpl')) !== null) {
            $this->chapters = self::nero($chpl);
        }
    }

    /**
     * Track $trak: kept when it is of a codec the player reads, else (a
     * chapter track, which chap names) its samples the chapters.
     * @param array<int, true> $chapterTracks
     * @param array<int, list<int>> $trex the fragments' defaults by track id
     */
    private function track(string $trak, int $movieScale, array $chapterTracks, array $trex): void
    {
        $tkhd = self::find($trak, 'tkhd') ?? '';
        $id = strlen($tkhd) >= 16 ? unpack('N', $tkhd, ord($tkhd[0]) === 1 ? 20 : 12)[1] : 0;
        $enabled = strlen($tkhd) >= 4 && (ord($tkhd[3]) & 1) === 1;
        $mdia = self::find($trak, 'mdia') ?? '';
        $mdhd = self::find($mdia, 'mdhd') ?? '';
        if ($mdhd === '') { return; }
        $v1 = ord($mdhd[0]) === 1;
        $scale = unpack('N', $mdhd, $v1 ? 20 : 12)[1];
        $lang = unpack('n', $mdhd, $v1 ? 32 : 20)[1];
        $language = $lang === 0 || $lang === 0x7fff ? 'und' : chr((($lang >> 10) & 31) + 0x60) . chr((($lang >> 5) & 31) + 0x60) . chr(($lang & 31) + 0x60);
        $hdlr = self::find($mdia, 'hdlr') ?? '';
        $handler = strlen($hdlr) >= 12 ? substr($hdlr, 8, 4) : '';
        $stbl = self::find(self::find($mdia, 'minf') ?? '', 'stbl') ?? '';
        $stsd = self::find($stbl, 'stsd') ?? '';
        if ($scale <= 0 || strlen($stsd) < 16) { return; }
        $entry = substr($stsd, 16, unpack('N', $stsd, 8)[1] - 8); // the first sample entry, past its size and type
        $fourcc = substr($stsd, 12, 4);
        $shift = self::shift(self::find($trak, 'edts'), $scale, $movieScale);
        $table = $this->table($stbl, $shift, $scale);
        if (isset($chapterTracks[$id])) {
            $this->chapters = $this->chapterTrack($table);
            return;
        }
        $t = match (true) {
            $handler === 'vide' && isset(self::VIDEO[$fourcc]) => $this->video($id, $fourcc, $entry, $language, $enabled, $table),
            $handler === 'soun' && isset(self::AUDIO[$fourcc]) => $this->audio($id, $fourcc, $entry, $language, $enabled, $scale),
            in_array($handler, ['sbtl', 'text', 'subt'], true) && $fourcc === 'tx3g' => new Track($id, Track::SUBTITLE, 'S_TEXT/TX3G', '', $language, '', $enabled, 0.0, 0, 0, 0.0, 0),
            default => null,
        };
        if ($t === null) {
            return;
        }
        $this->tracks[] = $t;
        $this->samples[] = $table;
        [$duration, $size, $flags] = $trex[$id] ?? [0, 0, 0];
        $this->fragmentTracks[$id] = ['track' => $t, 'scale' => $scale, 'shift' => $shift, 'duration' => $duration, 'size' => $size, 'flags' => $flags];
    }

    /** The samples of a fragmented film's segment $segment (its moof and mdat boxes: an HLS segment of fMP4, of this init segment), read as they come. */
    public function fragment(Bytes $segment): Fragment
    {
        return new Fragment($segment, $this->fragmentTracks);
    }

    /** A video track of sample entry $e (VisualSampleEntry): its size, its decoder configuration box. @param array{scale: int, times: string, count: int, durations: string} $table */
    private function video(int $id, string $fourcc, string $e, string $lang, bool $enabled, array $table): Track
    {
        $atom = ['avc1' => 'avcC', 'avc3' => 'avcC', 'hvc1' => 'hvcC', 'hev1' => 'hvcC', 'vp09' => 'vpcC', 'av01' => 'av1C'][$fourcc];
        $config = self::find($e, $atom, 78) ?? '';
        if ($fourcc === 'vp09') { $config = ''; } // the player makes VP9's from its first frame, as of Matroska's
        // A frame's duration: the decode times' mean delta (a muxer's of whole milliseconds, 0.042 and 0.041, are 1/24 s on the whole).
        $deltas = unpack('V*', $table['durations']) ?: [];
        $frame = $deltas === [] ? 0.0 : array_sum($deltas) / count($deltas) / $table['scale'];
        return new Track($id, Track::VIDEO, self::VIDEO[$fourcc], $config, $lang, '', $enabled, $frame, unpack('n', $e, 24)[1], unpack('n', $e, 26)[1], 0.0, 0,
            colors: self::colr(self::find($e, 'colr', 78) ?? ''));
    }

    /** A colr box's colors (ISO/IEC 14496-12 12.1.5): an nclx's codes and range, a QuickTime nclc's codes; none of an ICC profile's. @return array{primaries?: int, transfer?: int, matrix?: int, full?: bool} */
    private static function colr(string $b): array
    {
        $type = substr($b, 0, 4);
        if (($type !== 'nclx' && $type !== 'nclc') || strlen($b) < 10) {
            return [];
        }
        $v = unpack('n3', $b, 4);
        $out = ['primaries' => $v[1], 'transfer' => $v[2], 'matrix' => $v[3]];
        if ($type === 'nclx' && strlen($b) >= 11) { $out['full'] = (ord($b[10]) & 0x80) !== 0; }
        return $out;
    }

    /** An audio track of sample entry $e (AudioSampleEntry, QuickTime's of versions 1 and 2 too): its channels and rate, its decoder configuration. */
    private function audio(int $id, string $fourcc, string $e, string $lang, bool $enabled, int $scale): Track
    {
        $version = unpack('n', $e, 8)[1];
        $channels = unpack('n', $e, 16)[1];
        $rate = unpack('N', $e, 24)[1] / 65536;
        $children = 28;
        if ($version === 1) { $children += 16; }
        if ($version === 2) { $rate = unpack('E', $e, 32)[1]; $channels = unpack('N', $e, 40)[1]; $children += 36; }
        if ($rate <= 0) { $rate = (float)$scale; }
        $codec = self::AUDIO[$fourcc];
        $private = '';
        if ($fourcc === 'mp4a') {
            $esds = self::find($e, 'esds', $children) ?? self::find(self::find($e, 'wave', $children) ?? '', 'esds') ?? '';
            [$object, $private] = self::esds($esds);
            if ($object === 0x6B || $object === 0x69) { [$codec, $private] = ['A_MPEG/L3', '']; } // MP3 in an MP4
        } elseif ($fourcc === 'Opus') {
            $private = self::opusHead(self::find($e, 'dOps', $children) ?? '');
        } elseif ($fourcc === 'fLaC') {
            $dfla = self::find($e, 'dfLa', $children) ?? '';
            $private = strlen($dfla) > 4 ? 'fLaC' . substr($dfla, 4) : ''; // its metadata blocks, past version and flags
        }
        return new Track($id, Track::AUDIO, $codec, $private, $lang, '', $enabled, 0.0, 0, 0, (float)$rate, $channels);
    }

    /** The object type and the DecoderSpecificInfo (AAC's AudioSpecificConfig) of an esds box. @return array{0: int, 1: string} */
    private static function esds(string $esds): array
    {
        $p = 4; // version and flags
        $object = 0; $info = '';
        $descriptor = function () use ($esds, &$p): array {
            $tag = ord($esds[$p++] ?? "\0");
            $len = 0;
            for ($i = 0; $i < 4; $i++) { $b = ord($esds[$p++] ?? "\0"); $len = ($len << 7) | ($b & 0x7f); if ($b < 0x80) { break; } }
            return [$tag, $len];
        };
        while ($p < strlen($esds)) {
            [$tag, $len] = $descriptor();
            if ($tag === 3) { // ES_Descriptor: its id, its flags, and what they say follows
                $flags = ord($esds[$p + 2] ?? "\0");
                $p += 3;
                if ($flags & 0x80) { $p += 2; }
                if ($flags & 0x40) { $p += 1 + ord($esds[$p] ?? "\0"); }
                if ($flags & 0x20) { $p += 2; }
            } elseif ($tag === 4) { // DecoderConfigDescriptor
                $object = ord($esds[$p] ?? "\0");
                $p += 13;
            } elseif ($tag === 5) {
                $info = substr($esds, $p, $len);
                break;
            } else {
                $p += $len;
            }
        }
        return [$object, $info];
    }

    /** The OpusHead (RFC 7845, little-endian) of a dOps box (big-endian, of the Opus in ISOBMFF binding). */
    private static function opusHead(string $d): string
    {
        if (strlen($d) < 11) {
            return '';
        }
        $head = 'OpusHead' . chr(1) . $d[1] . pack('v', unpack('n', $d, 2)[1]) . pack('V', unpack('N', $d, 4)[1]) . pack('v', unpack('n', $d, 8)[1]) . $d[10];
        return $head . substr($d, 11); // the channel mapping table, for a family past 0
    }

    /** What the edit list shifts the media by, in its timescale: an empty edit first delays it, a first edit's media time starts it. */
    private static function shift(?string $edts, int $scale, int $movieScale): int
    {
        $elst = $edts === null ? null : self::find($edts, 'elst');
        if ($elst === null || strlen($elst) < 8) {
            return 0;
        }
        $v1 = ord($elst[0]) === 1;
        $n = unpack('N', $elst, 4)[1];
        $shift = 0;
        for ($i = 0, $p = 8; $i < $n; $i++, $p += $v1 ? 20 : 12) {
            $duration = $v1 ? unpack('J', $elst, $p)[1] : unpack('N', $elst, $p)[1];
            $media = $v1 ? unpack('q', strrev(substr($elst, $p + 8, 8)))[1] : unpack('l', strrev(substr($elst, $p + 4, 4)))[1];
            if ($media === -1) { // an empty edit: the media starts after it
                $shift -= (int)round($duration * $scale / max(1, $movieScale));
                continue;
            }
            return $shift + $media;
        }
        return $shift;
    }

    /**
     * The sample table: each sample's offset (stco, co64 by stsc's chunks),
     * size (stsz, stz2), decode time (stts) and composition offset (ctts),
     * its presentation time those less the edit list's $shift; the sync
     * samples (stss).
     * @return array{scale: int, offsets: string, sizes: string, times: string, durations: string, sync: ?array<int, true>, count: int}
     */
    private function table(string $stbl, int $shift, int $scale): array
    {
        $box = fn(string $t) => self::find($stbl, $t);
        // Sizes.
        $sizes = '';
        if (($stsz = $box('stsz')) !== null) {
            $uniform = unpack('N', $stsz, 4)[1]; $count = unpack('N', $stsz, 8)[1];
            $sizes = $uniform > 0 ? str_repeat(pack('V', $uniform), $count) : self::swap32(substr($stsz, 12, 4 * $count));
        } elseif (($stz2 = $box('stz2')) !== null) {
            $bits = ord($stz2[7]); $count = unpack('N', $stz2, 8)[1];
            $vals = [];
            for ($i = 0; $i < $count; $i++) {
                $vals[] = match ($bits) { 8 => ord($stz2[12 + $i]), 16 => unpack('n', $stz2, 12 + 2 * $i)[1], default => (ord($stz2[12 + ($i >> 1)]) >> ($i & 1 ? 0 : 4)) & 15 };
            }
            $sizes = pack('V*', ...$vals);
        } else {
            $count = 0;
        }
        // Offsets, decode times and composition offsets, a sample at a time (cursors over stsc's chunks, stts's and ctts's
        // runs), packed as they come: the tables of a film of hours are of hundreds of thousands of samples.
        $chunks = '';
        if (($stco = $box('stco')) !== null) {
            $chunks = self::swap32(substr($stco, 8, 4 * unpack('N', $stco, 4)[1])); // as 'V'
            $chunkSize = 4;
        } elseif (($co64 = $box('co64')) !== null) {
            $chunks = substr($co64, 8, 8 * unpack('N', $co64, 4)[1]); // as 'J'
            $chunkSize = 8;
        }
        $chunkCount = isset($chunkSize) ? intdiv(strlen($chunks), $chunkSize) : 0;
        $chunkAt = fn(int $c): int => $chunkSize === 8 ? unpack('J', $chunks, 8 * $c)[1] : unpack('V', $chunks, 4 * $c)[1];
        $stsc = $box('stsc') ?? '';
        $runs = strlen($stsc) >= 8 ? unpack('N', $stsc, 4)[1] : 0;
        $stts = $box('stts') ?? '';
        $ttsRuns = strlen($stts) >= 8 ? unpack('N', $stts, 4)[1] : 0;
        $ctts = $box('ctts');
        $ctsRuns = $ctts === null ? 0 : unpack('N', $ctts, 4)[1];
        $offsets = ''; $times = ''; $durations = '';
        [$chunk, $left, $off, $run] = [-1, 0, 0, -1]; // the chunk (0-based), its samples left, the next offset, stsc's run
        [$tts, $ttsLeft, $delta, $t] = [-1, 0, 0, 0];
        [$cts, $ctsLeft, $comp] = [-1, 0, 0];
        $made = 0;
        for ($s = 0; $s < $count; $s++, $made++) {
            while ($left === 0) { // the next chunk: its samples as stsc's run of it says
                $chunk++;
                if ($chunk >= $chunkCount) { break 2; }
                while ($run + 1 < $runs && unpack('N', $stsc, 8 + 12 * ($run + 1))[1] - 1 <= $chunk) { $run++; }
                $left = $run < 0 ? 0 : unpack('N', $stsc, 12 + 12 * $run)[1];
                $off = $chunkAt($chunk);
            }
            while ($ttsLeft === 0 && $tts + 1 < $ttsRuns) { $tts++; [$ttsLeft, $delta] = array_values(unpack('N2', $stts, 8 + 8 * $tts)); }
            while ($ctts !== null && $ctsLeft === 0 && $cts + 1 < $ctsRuns) {
                $cts++;
                $ctsLeft = unpack('N', $ctts, 8 + 8 * $cts)[1];
                $comp = unpack('N', $ctts, 12 + 8 * $cts)[1];
                if ($comp >= 0x80000000) { $comp -= 0x100000000; } // version 1's are signed (and version 0's written as such)
            }
            $offsets .= pack('P', $off);
            $times .= pack('q', $t + $comp - $shift);
            $durations .= pack('V', $delta);
            $off += unpack('V', $sizes, 4 * $s)[1];
            $left--; $ttsLeft--; $ctsLeft--;
            $t += $delta;
        }
        // Sync samples.
        $sync = null;
        if (($stss = $box('stss')) !== null) {
            $sync = [];
            foreach (array_values(unpack('N*', substr($stss, 8, 4 * unpack('N', $stss, 4)[1])) ?: []) as $i) { $sync[$i - 1] = true; }
        }
        $keys = $sync === null ? null : array_keys($sync);
        if ($keys !== null) { sort($keys); }
        return ['scale' => $scale, 'offsets' => $offsets, 'sizes' => substr($sizes, 0, 4 * $made), 'times' => $times,
            'durations' => $durations, 'sync' => $sync, 'keys' => $keys, 'count' => $made];
    }

    /** 32-bit big-endian integers as little-endian ones (stsz's sizes, as 'V*' reads them). */
    private static function swap32(string $s): string
    {
        $v = unpack('N*', $s);
        return $v === false || $v === [] ? '' : pack('V*', ...$v);
    }

    /** The order the samples of all tracks are stored in: by their offsets. */
    private function interleave(): void
    {
        $heads = [];
        foreach ($this->samples as $k => $tab) { if ($tab['count'] > 0) { $heads[$k] = 0; } }
        $order = ''; $count = 0;
        while ($heads !== []) {
            $best = null; $bestOff = PHP_INT_MAX;
            foreach ($heads as $k => $i) {
                $off = unpack('P', $this->samples[$k]['offsets'], 8 * $i)[1];
                if ($off < $bestOff) { $best = $k; $bestOff = $off; }
            }
            $i = $heads[$best];
            // The run of this track's samples before another's next: taken at once.
            $next = PHP_INT_MAX;
            foreach ($heads as $k => $j) { if ($k !== $best) { $next = min($next, unpack('P', $this->samples[$k]['offsets'], 8 * $j)[1]); } }
            $n = $this->samples[$best]['count'];
            do {
                $order .= pack('CV', $best, $i);
                $i++; $count++;
            } while ($i < $n && unpack('P', $this->samples[$best]['offsets'], 8 * $i)[1] < $next);
            if ($i >= $n) { unset($heads[$best]); } else { $heads[$best] = $i; }
        }
        $this->order = $order;
        $this->count = $count;
    }

    /** The chapters a chapter track's samples are (QuickTime's): each a 16-bit length and its title, at its time. @param array{scale: int, offsets: string, sizes: string, times: string, count: int} $tab @return list<Chapter> */
    private function chapterTrack(array $tab): array
    {
        $out = [];
        for ($i = 0; $i < $tab['count']; $i++) {
            $s = $this->bytes->read(unpack('P', $tab['offsets'], 8 * $i)[1], unpack('V', $tab['sizes'], 4 * $i)[1]);
            $title = strlen($s) >= 2 ? substr($s, 2, unpack('n', $s)[1]) : '';
            if (str_starts_with($title, "\xFE\xFF")) { $title = (string)mb_convert_encoding(substr($title, 2), 'UTF-8', 'UTF-16BE'); }
            $out[] = new Chapter($title, max(0.0, unpack('q', $tab['times'], 8 * $i)[1] / $tab['scale']));
        }
        return $out;
    }

    /** Nero's chapters (udta's chpl): their starts in 100 ns and titles. @return list<Chapter> */
    private static function nero(string $d): array
    {
        $p = ord($d[0]) === 1 ? 9 : 5; // version and flags, and a version 1's reserved bytes
        $n = ord($d[$p - 1]);
        $out = [];
        for ($i = 0; $i < $n && $p + 9 <= strlen($d); $i++) {
            $start = unpack('J', $d, $p)[1] / 1e7;
            $len = ord($d[$p + 8]);
            $out[] = new Chapter(substr($d, $p + 9, $len), $start);
            $p += 9 + $len;
        }
        return $out;
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
        return $this->chapters;
    }

    /** The sample at $i of $order: its track's place, its index, offset and size. @return array{0: int, 1: int, 2: int, 3: int} */
    private function entry(int $i): array
    {
        ['t' => $t, 'i' => $s] = unpack('Ct/Vi', $this->order, 5 * $i);
        $tab = $this->samples[$t];
        return [$t, $s, unpack('P', $tab['offsets'], 8 * $s)[1], unpack('V', $tab['sizes'], 4 * $s)[1]];
    }

    public function ready(): bool
    {
        if ($this->fragmented) {
            return $this->sought() && $this->fragment->ready();
        }
        if ($this->at >= $this->count) {
            return true;
        }
        [, , $off, $size] = $this->entry($this->at);
        return $this->bytes->has($off, $size);
    }

    public function next(): ?Packet
    {
        if ($this->fragmented) {
            return $this->sought() ? $this->fragment->next() : null;
        }
        if ($this->at >= $this->count || !$this->ready()) {
            return null;
        }
        [$t, $s, $off, $size] = $this->entry($this->at++);
        $tab = $this->samples[$t];
        $track = $this->tracks[$t];
        return new Packet($track, unpack('q', $tab['times'], 8 * $s)[1] / $tab['scale'], unpack('V', $tab['durations'], 4 * $s)[1] / $tab['scale'],
            $tab['sync'] === null || isset($tab['sync'][$s]), $this->bytes->read($off, $size));
    }

    /**
     * Goes to where decoding $seconds starts: the video's last sync sample
     * shown at or before it, from the first sample stored of any track that
     * ends past its time (the sound beside it), as FFmpeg's mov seeks; by
     * binary searches, the samples' times rising as they are stored (the
     * sync samples' and the sound's).
     */
    public function seek(float $seconds): void
    {
        if ($this->fragmented) {
            $this->seekTo = $seconds; // found as it reads on: sought()
            return;
        }
        $time = fn(array $tab, int $s): float => unpack('q', $tab['times'], 8 * $s)[1] / $tab['scale'];
        $offset = fn(array $tab, int $s): int => unpack('P', $tab['offsets'], 8 * $s)[1];
        $from = $seconds;
        foreach ($this->tracks as $k => $t) {
            if ($t->type !== Track::VIDEO) { continue; }
            $tab = $this->samples[$k];
            $keys = $tab['keys'] ?? range(0, $tab['count'] - 1);
            [$lo, $hi, $at] = [0, count($keys) - 1, 0];
            while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($time($tab, $keys[$mid]) <= $seconds + 1e-6) { $at = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; } }
            $from = $keys === [] ? 0.0 : $time($tab, $keys[$at]);
            $start = $keys === [] ? 0 : $offset($tab, $keys[$at]);
            break;
        }
        $start ??= PHP_INT_MAX;
        foreach ($this->tracks as $k => $t) { // each other track's first sample ending past it
            if ($t->type === Track::VIDEO) { continue; }
            $tab = $this->samples[$k];
            [$lo, $hi, $first] = [0, $tab['count'] - 1, $tab['count']];
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($time($tab, $mid) + unpack('V', $tab['durations'], 4 * $mid)[1] / $tab['scale'] > $from) { $first = $mid; $hi = $mid - 1; } else { $lo = $mid + 1; }
            }
            if ($first < $tab['count']) { $start = min($start, $offset($tab, $first)); }
        }
        // The first entry of the order (by offset) at or after it.
        [$lo, $hi, $this->at] = [0, $this->count - 1, $this->count];
        while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($this->entry($mid)[2] >= $start) { $this->at = $mid; $hi = $mid - 1; } else { $lo = $mid + 1; } }
    }

    /** All its tracks are read: they are of one file. */
    public function choose(?Track $video, ?Track $audio): void {}

    public function keyframe(float $seconds): ?Packet
    {
        $this->seek($seconds);
        if ($this->fragmented) {
            while (($p = $this->next()) !== null) {
                if ($p->track->type === Track::VIDEO && $p->keyframe) { return $p; }
            }
            return null; // the end, or not here yet
        }
        while ($this->at < $this->count) {
            [$t] = $this->entry($this->at);
            if ($this->tracks[$t]->type !== Track::VIDEO) { $this->at++; continue; }
            $p = $this->next();
            if ($p === null) { return null; } // not here yet
            if ($p->keyframe) { return $p; }
        }
        return null;
    }
}
