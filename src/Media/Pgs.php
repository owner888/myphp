<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * Blu-ray's image subtitles (PGS, Matroska's S_HDMV/PGS): a packet is a
 * display set, its segments one after the other (the type, a 16-bit size,
 * the data; Matroska leaves out the "PG" and times of a .sup file). Its
 * composition (PCS) places objects (ODS: the runs of a bitmap of indexes,
 * maybe in fragments) on the plane, in the colors of a palette (PDS: Y,
 * Cr, Cb and alpha by index); objects and palettes last through an epoch,
 * which later sets reuse, so one track's sets are read in order by one
 * decoder. A composition of no objects clears the plane.
 */
final class Pgs
{
    private const PDS = 0x14, ODS = 0x15, PCS = 0x16, WDS = 0x17, END = 0x80;

    /** @var array<int, array<int, string>> each palette's premultiplied RGBA by index */
    private array $palettes = [];
    /** @var array<int, array{0: int, 1: int, 2: string}> each object's width, height and runs */
    private array $objects = [];
    /** @var array<int, string> objects being read in fragments: their runs so far */
    private array $partial = [];
    private int $planeWidth = 0, $planeHeight = 0;
    /** @var list<array{0: int, 1: int, 2: int, 3: ?array{0: int, 1: int, 2: int, 3: int}}> the last composition's objects: id, x, y, crop */
    private array $shown = [];

    /** The pictures display set $data shows: none for one that clears. @return list<SubtitlePicture> */
    public function displaySet(string $data): array
    {
        $len = strlen($data);
        $composition = null; $palette = 0; $paletteOnly = false;
        for ($p = 0; $p + 3 <= $len;) {
            $type = ord($data[$p]);
            $size = unpack('n', $data, $p + 1)[1];
            $s = substr($data, $p + 3, $size);
            $p += 3 + $size;
            switch ($type) {
                case self::PCS:
                    [$composition, $palette, $paletteOnly] = $this->composition($s);
                    break;
                case self::PDS:
                    $this->palette($s);
                    break;
                case self::ODS:
                    $this->object($s);
                    break;
                case self::WDS:
                case self::END:
                    break;
                default:
                    throw new RuntimeException(sprintf('myphp: a PGS segment of type 0x%02x', $type));
            }
        }
        if ($composition === null) {
            throw new RuntimeException('myphp: a PGS display set of no composition');
        }
        $this->shown = $paletteOnly ? $this->shown : $composition; // a palette's update shows the objects shown, in its colors
        $colors = $this->palettes[$palette] ?? [];
        $out = [];
        foreach ($this->shown as [$id, $x, $y, $crop]) {
            if (!isset($this->objects[$id])) { // defined earlier in its epoch, before where a seek began reading: shown from the next set that has it
                continue;
            }
            [$w, $h, $runs] = $this->objects[$id];
            [$cx, $cy, $cw, $ch] = $crop ?? [0, 0, $w, $h];
            $out[] = new SubtitlePicture($x, $y, $cw, $ch, $this->planeWidth, $this->planeHeight,
                fn(): string => self::crop(self::decode($w, $h, $runs, $colors), $w, $cx, $cy, $cw, $ch));
        }
        return $out;
    }

    /** The composition segment $s: its objects, its palette, and whether it only updates the palette. @return array{0: list<array{0: int, 1: int, 2: int, 3: ?array{0: int, 1: int, 2: int, 3: int}}>, 1: int, 2: bool} */
    private function composition(string $s): array
    {
        [$this->planeWidth, $this->planeHeight] = [unpack('n', $s, 0)[1], unpack('n', $s, 2)[1]];
        $state = ord($s[7]);
        if ($state & 0x80) { // an epoch starts: what was before is gone
            $this->objects = []; $this->palettes = []; $this->partial = [];
        }
        $paletteOnly = ord($s[8]) === 0x80;
        $palette = ord($s[9]);
        $n = ord($s[10]);
        $objects = [];
        for ($i = 0, $p = 11; $i < $n; $i++) {
            $id = unpack('n', $s, $p)[1];
            $cropped = (ord($s[$p + 3]) & 0x40) !== 0;
            $x = unpack('n', $s, $p + 4)[1]; $y = unpack('n', $s, $p + 6)[1];
            $p += 8;
            $crop = null;
            if ($cropped) {
                $crop = [unpack('n', $s, $p)[1], unpack('n', $s, $p + 2)[1], unpack('n', $s, $p + 4)[1], unpack('n', $s, $p + 6)[1]];
                $p += 8;
            }
            $objects[] = [$id, $x, $y, $crop];
        }
        return [$objects, $palette, $paletteOnly];
    }

    /** The palette segment $s: its entries as premultiplied RGBA, of BT.709's matrix for a plane of high definition, else BT.601's, in limited range. */
    private function palette(string $s): void
    {
        $id = ord($s[0]);
        $hd = $this->planeHeight >= 720;
        [$kr, $kb] = $hd ? [0.2126, 0.0722] : [0.299, 0.114];
        $kg = 1 - $kr - $kb;
        for ($p = 2, $n = strlen($s); $p + 5 <= $n; $p += 5) {
            [$y, $cr, $cb, $a] = [ord($s[$p + 1]), ord($s[$p + 2]), ord($s[$p + 3]), ord($s[$p + 4])];
            $yy = ($y - 16) * 255 / 219; $pr = ($cr - 128) * 255 / 224; $pb = ($cb - 128) * 255 / 224;
            $r = $yy + 2 * (1 - $kr) * $pr;
            $b = $yy + 2 * (1 - $kb) * $pb;
            $g = $yy - (2 * $kb * (1 - $kb) * $pb + 2 * $kr * (1 - $kr) * $pr) / $kg;
            $pm = fn(float $c): int => (int)round(max(0, min(255, $c)) * $a / 255);
            $this->palettes[$id][ord($s[$p])] = chr($pm($r)) . chr($pm($g)) . chr($pm($b)) . chr($a);
        }
    }

    /** The object segment $s: its runs, whole or the fragment of them it carries (the first holds the size and the bitmap's width and height). */
    private function object(string $s): void
    {
        $id = unpack('n', $s, 0)[1];
        $flags = ord($s[3]);
        if ($flags & 0x80) { // the first fragment: the length of all, the width and height, then runs
            $this->partial[$id] = substr($s, 7);
        } else {
            $this->partial[$id] = ($this->partial[$id] ?? throw new RuntimeException("myphp: a PGS object $id continued, not begun")) . substr($s, 4);
        }
        if ($flags & 0x40) { // the last fragment
            $data = $this->partial[$id];
            unset($this->partial[$id]);
            $this->objects[$id] = [unpack('n', $data, 0)[1], unpack('n', $data, 2)[1], substr($data, 4)];
        }
    }

    /**
     * The pixels of a bitmap of $w by $h of runs $runs in $colors (by index;
     * one not given is clear): a byte of a color is one pixel of it; 0 then
     * 0 ends a line; 0 then 00LLLLLL is L clear, 01LLLLLL LLLLLLLL more, and
     * 10LLLLLL C or 11LLLLLL LLLLLLLL C is L of C.
     * @param array<int, string> $colors
     */
    public static function decode(int $w, int $h, string $runs, array $colors): string
    {
        $clear = "\0\0\0\0";
        $out = '';
        $line = ''; $x = 0; $rows = 0;
        for ($p = 0, $n = strlen($runs); $p < $n && $rows < $h;) {
            $b = ord($runs[$p++]);
            if ($b !== 0) {
                $line .= $colors[$b] ?? $clear; $x++;
                continue;
            }
            $f = ord($runs[$p++] ?? "\0");
            if ($f === 0) { // the line ends: as wide as the bitmap
                $out .= $x < $w ? $line . str_repeat($clear, $w - $x) : substr($line, 0, $w * 4);
                $line = ''; $x = 0; $rows++;
                continue;
            }
            $count = $f & 0x3f;
            if ($f & 0x40) { $count = ($count << 8) | ord($runs[$p++] ?? "\0"); }
            $color = $f & 0x80 ? ($colors[ord($runs[$p++] ?? "\0")] ?? $clear) : $clear;
            $line .= str_repeat($color, $count); $x += $count;
        }
        if ($rows < $h) { // runs that stop short: the rest clear
            $out .= substr($line . str_repeat($clear, $w), 0, $w * 4) . str_repeat($clear, $w * ($h - $rows - 1));
        }
        return $out;
    }

    /** The part ($cx, $cy, $cw, $ch) of pixels $rgba of a bitmap $w wide. */
    private static function crop(string $rgba, int $w, int $cx, int $cy, int $cw, int $ch): string
    {
        if ($cx === 0 && $cw === $w) {
            return substr($rgba, $cy * $w * 4, $ch * $w * 4);
        }
        $out = '';
        for ($y = $cy; $y < $cy + $ch; $y++) {
            $out .= substr($rgba, ($y * $w + $cx) * 4, $cw * 4);
        }
        return $out;
    }
}
