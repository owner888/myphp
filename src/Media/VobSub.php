<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * DVD's image subtitles (VobSub, Matroska's S_VOBSUB): the track's
 * CodecPrivate is the .idx's header (the plane's size, a palette of 16
 * RGB colors); a packet is a subpicture unit, its bitmap of two-bit pixels
 * in runs of nibbles, its two fields interlaced (even lines, then odd),
 * then its control sequences: when it shows and stops after the packet's
 * time, the four colors and alphas of the palette its pixels take, where
 * it is, where each field's runs start.
 */
final class VobSub
{
    private int $planeWidth = 720, $planeHeight = 480;
    /** @var list<array{0: int, 1: int, 2: int}> the 16 colors of the .idx */
    private array $palette = [];

    public function __construct(string $idx)
    {
        if (preg_match('/^size:\s*(\d+)\s*x\s*(\d+)/mi', $idx, $m) === 1) {
            [$this->planeWidth, $this->planeHeight] = [(int)$m[1], (int)$m[2]];
        }
        if (preg_match('/^palette:\s*(.+)$/mi', $idx, $m) !== 1) {
            throw new RuntimeException('myphp: a VobSub track of no palette');
        }
        foreach (array_map('trim', explode(',', $m[1])) as $hex) {
            $v = hexdec($hex);
            $this->palette[] = [($v >> 16) & 0xff, ($v >> 8) & 0xff, $v & 0xff];
        }
    }

    /**
     * Subpicture unit $spu: when it starts and stops showing after its
     * packet's time, in seconds (null for a stop it does not say), and its
     * picture; null for one that shows nothing.
     * @return ?array{0: float, 1: ?float, 2: SubtitlePicture}
     */
    public function packet(string $spu): ?array
    {
        $len = strlen($spu);
        if ($len < 4) {
            throw new RuntimeException('myphp: a VobSub packet too short');
        }
        $at = unpack('n', $spu, 2)[1]; // the first control sequence
        $start = 0.0; $stop = null; $colors = [0, 0, 0, 0]; $alphas = [0, 0, 0, 0]; $box = null; $fields = null;
        while ($at + 4 <= $len) {
            $delay = unpack('n', $spu, $at)[1] * 1024 / 90000.0; // in ticks of 1024 of the 90 kHz clock
            $next = unpack('n', $spu, $at + 2)[1];
            for ($p = $at + 4; $p < $len;) {
                $cmd = ord($spu[$p++]);
                if ($cmd === 0xff) { break; }
                switch ($cmd) {
                    case 0x00: case 0x01: $start = $delay; break; // forced or not, it shows
                    case 0x02: $stop = $delay; break;
                    case 0x03: $colors = self::nibbles($spu, $p); $p += 2; break;
                    case 0x04: $alphas = self::nibbles($spu, $p); $p += 2; break;
                    case 0x05:
                        $b = array_values(unpack('C6', $spu, $p)); $p += 6;
                        $box = [($b[0] << 4) | ($b[1] >> 4), (($b[1] & 0x0f) << 8) | $b[2], ($b[3] << 4) | ($b[4] >> 4), (($b[4] & 0x0f) << 8) | $b[5]]; // x1, x2, y1, y2
                        break;
                    case 0x06: $fields = [unpack('n', $spu, $p)[1], unpack('n', $spu, $p + 2)[1]]; $p += 4; break;
                    case 0x07: $p += unpack('n', $spu, $p)[1]; break; // a change of colors and contrast by lines, left out
                    default: throw new RuntimeException(sprintf('myphp: a VobSub control command 0x%02x', $cmd));
                }
            }
            if ($next === $at) { break; }
            $at = $next;
        }
        if ($box === null || $fields === null) {
            return null;
        }
        [$x1, $x2, $y1, $y2] = $box;
        $w = $x2 - $x1 + 1; $h = $y2 - $y1 + 1;
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $rgba = [];
        for ($i = 0; $i < 4; $i++) { // the four colors the pixels take, premultiplied by their alpha (of 0 to 15)
            [$r, $g, $b] = $this->palette[$colors[$i]] ?? [0, 0, 0];
            $a = $alphas[$i] * 17;
            $rgba[$i] = chr(intdiv($r * $a, 255)) . chr(intdiv($g * $a, 255)) . chr(intdiv($b * $a, 255)) . chr($a);
        }
        $pic = new SubtitlePicture($x1, $y1, $w, $h, $this->planeWidth, $this->planeHeight, fn(): string => self::decode($spu, $fields, $w, $h, $rgba));
        return [$start, $stop, $pic];
    }

    /** The four nibbles of the two bytes at $p, for pixel values 3, 2, 1, 0 in that order: by pixel value. @return list<int> */
    private static function nibbles(string $s, int $p): array
    {
        $a = ord($s[$p]); $b = ord($s[$p + 1]);
        return [$b & 0x0f, $b >> 4, $a & 0x0f, $a >> 4];
    }

    /**
     * The pixels of a bitmap $w by $h whose even lines are the runs at
     * $fields[0] and odd ones those at $fields[1], in colors $rgba by pixel
     * value: a run is a nibble 01-03 of 2 bits of count and 2 of value, or
     * 1, 2 or 3 more nibbles for the larger (4-15, 16-63, 64-255); a count
     * of 0 fills the line; a line ends on a byte.
     * @param array{0: int, 1: int} $fields
     * @param array<int, string> $rgba
     */
    public static function decode(string $spu, array $fields, int $w, int $h, array $rgba): string
    {
        $lines = [];
        foreach ([0, 1] as $f) {
            $nib = $fields[$f] * 2; // in nibbles
            for ($y = $f; $y < $h; $y += 2) {
                $line = ''; $x = 0;
                while ($x < $w) {
                    $get = function () use ($spu, &$nib): int { $b = ord($spu[$nib >> 1] ?? "\0"); return ($nib++ & 1) ? $b & 0x0f : $b >> 4; };
                    $v = $get();
                    if ($v < 0x4) { $v = ($v << 4) | $get(); if ($v < 0x10) { $v = ($v << 4) | $get(); if ($v < 0x40) { $v = ($v << 4) | $get(); } } }
                    $count = $v >> 2;
                    if ($count === 0) { $count = $w - $x; }
                    $count = min($count, $w - $x);
                    $line .= str_repeat($rgba[$v & 3], $count); $x += $count;
                }
                if ($nib & 1) { $nib++; }
                $lines[$y] = $line;
            }
        }
        ksort($lines);
        return implode('', $lines);
    }
}
