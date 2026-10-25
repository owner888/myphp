<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\{ImageIO, Mem, Zlib};
use RuntimeException;

/**
 * The app's icon, a square PNG, as the .icns macOS shows (mygo's icon.go):
 * the sizes it expects, each a PNG, scaled down by area averaging and up
 * by bilinear sampling, with premultiplied colors so that transparent
 * pixels do not darken edges. It leaves out icp4 and icp5, the 16 and 32
 * pixel entries: IconServices reads their payloads as the old ARGB format
 * even when they hold a PNG, and Finder, Spotlight and Get Info draw the
 * small icon as noise; macOS scales those sizes down from ic11 and ic12.
 *
 * @internal
 */
final class Icns
{
    public const ENTRIES = [['ic11', 32], ['ic12', 64], ['ic07', 128], ['ic13', 256], ['ic08', 256], ['ic14', 512], ['ic09', 512], ['ic10', 1024]];

    /** The .icns of a PNG (or any image ImageIO reads), decoded by ImageIO on macOS. */
    public static function fromPng(string $png): string
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('icon must be a PNG');
        }
        $w = unpack('N', $png, 16)[1]; $h = unpack('N', $png, 20)[1];
        if ($w !== $h) {
            throw new RuntimeException("icon must be square, got {$w}x$h");
        }
        $img = ImageIO::decode($png, $w); // premultiplied: CoreGraphics draws into nothing else
        try {
            return self::fromPixels($img['w'], $img['h'], \FFI::string($img['pix'], $img['w'] * $img['h'] * 4));
        } finally {
            Mem::free($img['pix']);
        }
    }

    /** The .icns of $w by $h pixels of premultiplied RGBA, top row first. */
    public static function fromPixels(int $w, int $h, string $rgba): string
    {
        $src = array_values(unpack('C*', $rgba));
        $levels = []; // by size: ic13 and ic08 share theirs, as ic14 and ic09
        $body = '';
        foreach (self::ENTRIES as [$kind, $size]) {
            $levels[$size] ??= self::pack(self::sums($src, $w, $h, $size), $size);
            $p = self::png($size, $levels[$size]);
            $body .= $kind . pack('N', strlen($p) + 8) . $p;
        }
        return 'icns' . pack('N', strlen($body) + 8) . $body;
    }

    /**
     * Premultiplied sums of the source pixels that each pixel of $size by
     * $size takes: [r·a, g·a, b·a, a, n] each, as mygo's resize adds them
     * (area averaging when shrinking, bilinear weights when enlarging); the
     * source is premultiplied by ImageIO, whose 8-bit values times 255 stand
     * for mygo's color times alpha.
     *
     * @param list<int> $src
     * @return list<float>
     */
    private static function sums(array $src, int $w, int $h, int $size): array
    {
        $out = array_fill(0, $size * $size * 5, 0.0);
        if ($w === 0 || $h === 0) {
            return $out;
        }
        $sx = $w / $size; $sy = $h / $size;
        $at = function (int $x, int $y) use ($src, $w, $h): int { return ((min(max($y, 0), $h - 1)) * $w + min(max($x, 0), $w - 1)) * 4; };
        for ($y = 0, $o = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++, $o += 5) {
                $r = $g = $b = $a = $n = 0.0;
                if ($sx >= 1) {
                    $x0 = (int)($x * $sx); $x1 = max((int)(($x + 1) * $sx), $x0 + 1);
                    $y0 = (int)($y * $sy); $y1 = max((int)(($y + 1) * $sy), $y0 + 1);
                    for ($yy = $y0; $yy < $y1; $yy++) {
                        $row = min($yy, $h - 1) * $w;
                        for ($xx = $x0; $xx < $x1; $xx++) {
                            $i = ($row + min($xx, $w - 1)) * 4;
                            $r += $src[$i]; $g += $src[$i + 1]; $b += $src[$i + 2]; $a += $src[$i + 3]; $n++;
                        }
                    }
                } else {
                    $fx = ($x + 0.5) * $sx - 0.5; $fy = ($y + 0.5) * $sy - 0.5;
                    $x0 = (int)$fx; $y0 = (int)$fy;
                    $dx = $fx - $x0; $dy = $fy - $y0;
                    foreach ([[$x0, $y0, (1 - $dx) * (1 - $dy)], [$x0 + 1, $y0, $dx * (1 - $dy)], [$x0, $y0 + 1, (1 - $dx) * $dy], [$x0 + 1, $y0 + 1, $dx * $dy]] as [$px, $py, $wt]) {
                        $i = $at($px, $py);
                        $r += $src[$i] * $wt; $g += $src[$i + 1] * $wt; $b += $src[$i + 2] * $wt; $a += $src[$i + 3] * $wt; $n += $wt;
                    }
                }
                $out[$o] = $r * 255; $out[$o + 1] = $g * 255; $out[$o + 2] = $b * 255; $out[$o + 3] = $a; $out[$o + 4] = $n;
            }
        }
        return $out;
    }

    /** Straight RGBA bytes of premultiplied sums, rounded as mygo's resize rounds them. @param list<float> $sums */
    private static function pack(array $sums, int $size): string
    {
        $px = [];
        for ($o = 0, $end = $size * $size * 5; $o < $end; $o += 5) {
            $a = $sums[$o + 3];
            if ($a == 0) {
                array_push($px, 0, 0, 0, 0);
                continue;
            }
            array_push($px, (int)($sums[$o] / $a + 0.5), (int)($sums[$o + 1] / $a + 0.5), (int)($sums[$o + 2] / $a + 0.5), (int)($a / $sums[$o + 4] + 0.5));
        }
        return pack('C*', ...$px);
    }

    /** $size by $size pixels of straight RGBA as a PNG: 8-bit RGBA, each row unfiltered. */
    public static function png(int $size, string $rgba): string
    {
        $raw = '';
        for ($y = 0, $stride = $size * 4; $y < $size; $y++) {
            $raw .= "\0" . substr($rgba, $y * $stride, $stride);
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0))
            . $chunk('IDAT', Zlib::deflate($raw, 15, 6)) // a zlib stream
            . $chunk('IEND', '');
    }

    /** A 1024 by 1024 app icon as a PNG, which myphp init gives a project: a rounded square with a diagonal gradient and a ring (mygo's defaultIcon). */
    public static function defaultIcon(): string
    {
        $size = 1024;
        $from = [59, 130, 246]; // blue
        $to = [168, 85, 247]; // violet
        $inset = 100.0; $radius = 185.0;
        $half = $size / 2; $edge = $half - $inset - $radius;
        $px = '';
        for ($y = 0; $y < $size; $y++) {
            $row = '';
            $fy = $y + 0.5;
            $qy = abs($fy - $half) - $edge;
            for ($x = 0; $x < $size; $x++) {
                $fx = $x + 0.5;
                // Signed distance to a rounded rectangle, for anti-aliasing.
                $qx = abs($fx - $half) - $edge;
                $d = hypot(max($qx, 0), max($qy, 0)) + min(max($qx, $qy), 0) - $radius;
                $alpha = min(max(0.5 - $d, 0), 1);
                if ($alpha == 0) {
                    $row .= "\0\0\0\0";
                    continue;
                }
                $t = ($fx + $fy) / (2 * $size);
                // A white ring in the middle.
                $ring = min(max(0.5 - abs(hypot($fx - $half, $fy - $half) - 230) + 48, 0), 1);
                $c = [];
                for ($i = 0; $i < 3; $i++) {
                    $c[] = (int)(($from[$i] + ($to[$i] - $from[$i]) * $t) * (1 - $ring) + 255 * $ring);
                }
                $row .= chr($c[0]) . chr($c[1]) . chr($c[2]) . chr((int)($alpha * 255));
            }
            $px .= $row;
        }
        return self::png($size, $px);
    }
}
