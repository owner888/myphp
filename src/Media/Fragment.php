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
    /** Where the next box is. */
    private int $pos = 0;
    private bool $end = false;
    /** @var list<array{0: Track, 1: float, 2: float, 3: bool, 4: int, 5: int}> the samples of the fragment read: track, time, duration, sync, offset, size; by offset */
    private array $samples = [];
    private int $at = 0;

    /** @param array<int, array{track: Track, scale: int, shift: int, duration: int, size: int, flags: int}> $tracks by id */
    public function __construct(private readonly Bytes $bytes, private readonly array $tracks) {}

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
            $dts = $tfdt === null ? 0 : (ord($tfdt[0]) === 1 ? unpack('J', $tfdt, 4)[1] : unpack('N', $tfdt, 4)[1]);
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
        }
        usort($samples, fn(array $a, array $b) => $a[4] <=> $b[4]);
        $this->samples = $samples;
        $this->at = 0;
    }
}
