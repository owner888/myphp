<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The samples of a fragmented MP4's segment (ISO/IEC 14496-12 8.8): its
 * movie fragments (moof: each track's tfhd, tfdt and truns) and the media
 * data they point into, read as they come, as FFmpeg's mov demuxer reads
 * an HLS segment of fMP4. The tracks, their timescales and defaults are
 * the init segment's (Mp4::fragment()).
 *
 * @internal Media\Mp4, Media\Hls
 */
final class Fragment
{
    private bool $end = false;
    /** @var list<array{0: Track, 1: float, 2: float, 3: bool, 4: int, 5: int}> the samples of the fragment read: track, time, duration, sync, offset, size; by offset */
    private array $samples = [];
    private int $at = 0;

    /** @var array<int, int> where each track's samples go on, decode time (its timescale's), for a fragment of no tfdt (one of old muxers'), by track id */
    private array $dts = [];

    /** The fragments of $bytes from $pos on (a segment's from its start, a file's past its movie box). @param array<int, array{track: Track, scale: int, shift: int, duration: int, size: int, flags: int}> $tracks by id */
    public function __construct(private readonly Bytes $bytes, private readonly array $tracks, private int $pos = 0) {}

    /**
     * Of the movie fragment at $pos of $bytes, track $id's first sample:
     * its decode time (its tfdt, of timescale $scale) and whether it is a
     * sync sample (the trun's first sample flags, its own, the tfhd's
     * default, else $default, the trex's); null when it has none; false
     * while its bytes are not here (asked for: never waited for, as a seek
     * of the player's goes on at once).
     * @return array{0: float, 1: bool}|null|false
     * @internal Media\Mp4
     */
    public static function first(Bytes $bytes, int $pos, int $id, int $scale, int $default): array|null|false
    {
        if (!$bytes->has($pos, 8)) { return false; }
        $h = $bytes->read($pos, 8);
        if (strlen($h) < 8 || substr($h, 4, 4) !== 'moof') { return null; }
        if (!$bytes->has($pos, unpack('N', $h)[1])) { return false; }
        $moof = $bytes->read($pos + 8, unpack('N', $h)[1] - 8);
        foreach (Mp4::boxes($moof) as [$type, $traf]) {
            $tfhd = $type === 'traf' ? Mp4::find($traf, 'tfhd') ?? '' : '';
            $tfdt = Mp4::find($traf, 'tfdt');
            $trun = Mp4::find($traf, 'trun');
            if (strlen($tfhd) < 8 || unpack('N', $tfhd, 4)[1] !== $id || $tfdt === null || $trun === null) { continue; }
            $flags = $default;
            $hf = unpack('N', $tfhd)[1] & 0xffffff;
            if ($hf & 0x20) { $flags = unpack('N', $tfhd, 8 + (($hf & 1) ? 8 : 0) + (($hf & 2) ? 4 : 0) + (($hf & 8) ? 4 : 0) + (($hf & 0x10) ? 4 : 0))[1]; }
            $tf = unpack('N', $trun)[1] & 0xffffff;
            $q = 8 + (($tf & 1) ? 4 : 0);
            if ($tf & 0x04) { $flags = unpack('N', $trun, $q)[1]; }
            elseif ($tf & 0x400) { $flags = unpack('N', $trun, $q + (($tf & 0x100) ? 4 : 0) + (($tf & 0x200) ? 4 : 0))[1]; }
            return [(ord($tfdt[0]) === 1 ? unpack('J', $tfdt, 4)[1] : unpack('N', $tfdt, 4)[1]) / $scale, ($flags & 0x10000) === 0];
        }
        return null;
    }

    /** Where the samples of the movie fragment it starts at end (seconds: the latest's time and duration), waited for; null for no fragment there. @internal Media\Mp4 */
    public function end(): ?float
    {
        $h = $this->bytes->read($this->pos, 8);
        if (strlen($h) < 8 || substr($h, 4, 4) !== 'moof') {
            return null;
        }
        $this->moof($this->pos, $this->bytes->read($this->pos + 8, unpack('N', $h)[1] - 8));
        $end = null;
        foreach ($this->samples as [, $time, $duration]) { $end = max($end ?? $time + $duration, $time + $duration); }
        return $end;
    }

    /** Whether next() has what it reads, without waiting; true at the end. */
    public function ready(): bool
    {
        while ($this->at >= count($this->samples)) {
            if ($this->end) {
                return true;
            }
            if (!$this->bytes->has($this->pos, 16)) {
                return false;
            }
            $h = $this->bytes->read($this->pos, 16);
            if (strlen($h) < 8) {
                $this->end = true;
                continue;
            }
            $size = unpack('N', $h)[1];
            $head = 8;
            if ($size === 1) { $size = unpack('J', $h, 8)[1]; $head = 16; }
            if ($size < $head) { // to the end (a size of 0), or broken: nothing past it
                $this->end = true;
                continue;
            }
            if (substr($h, 4, 4) === 'moof') {
                if (!$this->bytes->has($this->pos, $size)) {
                    return false;
                }
                $this->moof($this->pos, $this->bytes->read($this->pos + $head, $size - $head));
            }
            $this->pos += $size;
        }
        [, , , , $off, $size] = $this->samples[$this->at];
        return $this->bytes->has($off, $size);
    }

    /** The next sample; null at the end, or when it is not here yet. */
    public function next(): ?Packet
    {
        if (!$this->ready() || $this->at >= count($this->samples)) {
            return null;
        }
        [$track, $time, $duration, $sync, $off, $size] = $this->samples[$this->at++];
        return new Packet($track, $time, $duration, $sync, $this->bytes->read($off, $size));
    }

    /** A movie fragment at $start: each track fragment's samples, in the order their data is stored. */
    private function moof(int $start, string $moof): void
    {
        $samples = [];
        foreach (Mp4::boxes($moof) as [$type, $traf]) {
            if ($type !== 'traf') { continue; }
            $tfhd = Mp4::find($traf, 'tfhd') ?? '';
            if (strlen($tfhd) < 8 || !isset($this->tracks[$id = unpack('N', $tfhd, 4)[1]])) { continue; }
            $t = $this->tracks[$id];
            $flags = unpack('N', $tfhd)[1] & 0xffffff;
            $p = 8;
            $base = $start; // the moof's start, as default-base-is-moof says and FFmpeg takes it without one
            if ($flags & 0x01) { $base = unpack('J', $tfhd, $p)[1]; $p += 8; }
            if ($flags & 0x02) { $p += 4; }
            $dDur = $t['duration']; $dSize = $t['size']; $dFlags = $t['flags'];
            if ($flags & 0x08) { $dDur = unpack('N', $tfhd, $p)[1]; $p += 4; }
            if ($flags & 0x10) { $dSize = unpack('N', $tfhd, $p)[1]; $p += 4; }
            if ($flags & 0x20) { $dFlags = unpack('N', $tfhd, $p)[1]; }
            $tfdt = Mp4::find($traf, 'tfdt');
            $dts = $tfdt === null ? ($this->dts[$id] ?? 0) : (ord($tfdt[0]) === 1 ? unpack('J', $tfdt, 4)[1] : unpack('N', $tfdt, 4)[1]);
            $off = $base;
            foreach (Mp4::boxes($traf) as [$bt, $trun]) {
                if ($bt !== 'trun' || strlen($trun) < 8) { continue; }
                $tf = unpack('N', $trun)[1] & 0xffffff;
                $signed = ord($trun[0]) === 1;
                $n = unpack('N', $trun, 4)[1];
                $q = 8;
                if ($tf & 0x01) { $off = $base + unpack('l', strrev(substr($trun, $q, 4)))[1]; $q += 4; }
                $first = null;
                if ($tf & 0x04) { $first = unpack('N', $trun, $q)[1]; $q += 4; }
                for ($i = 0; $i < $n; $i++) {
                    $dur = $dDur; $size = $dSize; $sflags = $i === 0 && $first !== null ? $first : $dFlags; $cto = 0;
                    if ($tf & 0x100) { $dur = unpack('N', $trun, $q)[1]; $q += 4; }
                    if ($tf & 0x200) { $size = unpack('N', $trun, $q)[1]; $q += 4; }
                    if ($tf & 0x400) { $f = unpack('N', $trun, $q)[1]; $q += 4; if ($i > 0 || $first === null) { $sflags = $f; } }
                    if ($tf & 0x800) { $cto = $signed ? unpack('l', strrev(substr($trun, $q, 4)))[1] : unpack('N', $trun, $q)[1]; $q += 4; if (!$signed && $cto >= 0x80000000) { $cto -= 0x100000000; } }
                    $samples[] = [$t['track'], ($dts + $cto - $t['shift']) / $t['scale'], $dur / $t['scale'], ($sflags & 0x10000) === 0, $off, $size];
                    $dts += $dur;
                    $off += $size;
                }
            }
            $this->dts[$id] = $dts;
        }
        usort($samples, fn(array $a, array $b) => $a[4] <=> $b[4]);
        $this->samples = $samples;
        $this->at = 0;
    }
}
