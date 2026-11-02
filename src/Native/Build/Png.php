<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * PNG files read in plain PHP over the system's zlib, for packaging that
 * runs without ImageIO (the Linux icons, Go's image/png in mygo's
 * linuxIcons): grayscale, RGB, palette and their alpha forms, 1 to 16 bits
 * a sample, not interlaced.
 *
 * @internal
 */
final class Png
{
    /**
     * The pixels of $png, top row first, premultiplied: [width, height,
     * r·a/255, g·a/255, b·a/255, a for each pixel], the colors as floats, as
     * Icns::resize takes them.
     *
     * @return array{0: int, 1: int, 2: list<float|int>}
     */
    public static function decode(string $png): array
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('not a PNG');
        }
        $at = 8; $idat = ''; $palette = ''; $trns = ''; $ihdr = null;
        while ($at + 8 <= strlen($png)) {
            $len = unpack('N', $png, $at)[1];
            $type = substr($png, $at + 4, 4);
            $data = substr($png, $at + 8, $len);
            $at += 12 + $len;
            match ($type) {
                'IHDR' => $ihdr = unpack('Nw/Nh/Cdepth/Ccolor/Ccomp/Cfilter/Cinterlace', $data),
                'PLTE' => $palette = $data,
                'tRNS' => $trns = $data,
                'IDAT' => $idat .= $data,
                default => null,
            };
            if ($type === 'IEND') {
                break;
            }
        }
        if ($ihdr === null || $idat === '') {
            throw new RuntimeException('a PNG without IHDR or IDAT');
        }
        ['w' => $w, 'h' => $h, 'depth' => $depth, 'color' => $color] = $ihdr;
        if ($ihdr['interlace'] !== 0) {
            throw new RuntimeException('an interlaced PNG: save the icon without interlacing');
        }
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] ?? throw new RuntimeException("a PNG of color type $color");
        if (!in_array($depth, $color === 0 || $color === 3 ? [1, 2, 4, 8, 16] : [8, 16], true) || $color === 3 && $depth === 16) {
            throw new RuntimeException("a PNG of color type $color and $depth bits");
        }
        $raw = Zlib::inflate($idat, 15);
        $bpp = max(1, intdiv($channels * $depth, 8)); // the bytes a filter looks back
        $stride = intdiv($w * $channels * $depth + 7, 8);
        if (strlen($raw) < $h * ($stride + 1)) {
            throw new RuntimeException('a truncated PNG');
        }
        $px = [];
        $prev = array_fill(0, $stride, 0);
        $max = (1 << $depth) - 1;
        for ($y = 0; $y < $h; $y++) {
            $off = $y * ($stride + 1);
            $filter = ord($raw[$off]);
            $row = array_values(unpack('C*', substr($raw, $off + 1, $stride)));
            if ($filter > 4) {
                throw new RuntimeException("a PNG row of filter $filter");
            }
            for ($i = 0; $filter !== 0 && $i < $stride; $i++) {
                $a = $i >= $bpp ? $row[$i - $bpp] : 0;
                $b = $prev[$i];
                if ($filter === 1) {
                    $pred = $a;
                } elseif ($filter === 2) {
                    $pred = $b;
                } elseif ($filter === 3) {
                    $pred = ($a + $b) >> 1;
                } else { // Paeth
                    $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                    $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                    $pred = $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
                }
                $row[$i] = ($row[$i] + $pred) & 0xff;
            }
            $prev = $row;
            // The samples of the row, scaled to 0..255.
            $samples = [];
            if ($depth === 8) {
                $samples = $row;
            } elseif ($depth === 16) {
                for ($i = 0; $i < $stride; $i += 2) {
                    $samples[] = (($row[$i] << 8) | $row[$i + 1]) / 257;
                }
            } else {
                for ($i = 0, $n = $w * $channels; $i < $n; $i++) {
                    $bit = $i * $depth;
                    $v = ($row[$bit >> 3] >> (8 - $depth - ($bit & 7))) & $max;
                    $samples[] = $color === 3 ? $v : $v * 255 / $max;
                }
            }
            for ($x = 0; $x < $w; $x++) {
                $s = $x * $channels;
                switch ($color) {
                    case 0:
                        $r = $g = $b = $samples[$s];
                        $alpha = $trns !== '' && (int)round($samples[$s] * $max / 255) === unpack('n', $trns)[1] ? 0 : 255;
                        break;
                    case 2:
                        [$r, $g, $b] = [$samples[$s], $samples[$s + 1], $samples[$s + 2]];
                        $alpha = 255;
                        if (strlen($trns) === 6) {
                            $k = unpack('n3', $trns);
                            $same = array_map(fn($v) => (int)round($v * $max / 255), [$r, $g, $b]) === [$k[1], $k[2], $k[3]];
                            $alpha = $same ? 0 : 255;
                        }
                        break;
                    case 3:
                        $i = $samples[$s];
                        if (3 * $i + 2 >= strlen($palette)) {
                            throw new RuntimeException('a PNG pixel out of its palette');
                        }
                        [$r, $g, $b] = [ord($palette[3 * $i]), ord($palette[3 * $i + 1]), ord($palette[3 * $i + 2])];
                        $alpha = $i < strlen($trns) ? ord($trns[$i]) : 255;
                        break;
                    case 4:
                        $r = $g = $b = $samples[$s];
                        $alpha = $samples[$s + 1];
                        break;
                    default:
                        [$r, $g, $b, $alpha] = [$samples[$s], $samples[$s + 1], $samples[$s + 2], $samples[$s + 3]];
                }
                array_push($px, $r * $alpha / 255, $g * $alpha / 255, $b * $alpha / 255, $alpha);
            }
        }
        return [$w, $h, $px];
    }
}
