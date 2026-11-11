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
     * $w by $h premultiplied pixels (Png::decode's: colors times alpha over
     * 255, alpha) scaled to $size by $size, as a PNG (mygo's resize, then
     * png.Encode): the Linux icons.
     *
     * @param list<float|int> $src
     */
    public static function resize(int $w, int $h, array $src, int $size): string
    {
        return self::png($size, self::pack(self::sums($src, $w, $h, $size), $size));
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

    /** A 1024 by 1024 app icon as a PNG, which myphp init gives a project: MyPHP's rocket-riding ElePHPant mascot on a rounded square. */
    public static function defaultIcon(int $size = 1024): string
    {
        $svg = <<<'SVG'
<svg viewBox="0 0 440 440" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#1E1B4B"/>
      <stop offset="100%" stop-color="#312E81"/>
    </linearGradient>
  </defs>
  <rect x="28" y="28" width="384" height="384" rx="86" fill="url(#bg)"/>
  <circle cx="220" cy="220" r="158" fill="#3730A3" fill-opacity="0.35"/>
  <path d="M120 100 L122 108 L130 110 L122 112 L120 120 L118 112 L110 110 L118 108 Z" fill="#818CF8"/>
  <path d="M340 140 L341 145 L346 146 L341 147 L340 152 L339 147 L334 146 L339 145 Z" fill="#F472B6"/>
  <path d="M90 240 L91 245 L96 246 L91 247 L90 252 L89 247 L84 246 L89 245 Z" fill="#38BDF8"/>
  <circle cx="330" cy="280" r="4" fill="#FDE047"/>
  <circle cx="160" cy="75" r="3" fill="#FFFFFF"/>
  <g>
    <circle cx="125" cy="335" r="38" fill="#E2E8F0" opacity="0.92"/>
    <circle cx="102" cy="316" r="28" fill="#CBD5E1" opacity="0.88"/>
    <circle cx="152" cy="356" r="34" fill="#F1F5F9" opacity="0.92"/>
  </g>
  <path d="M145 285 L180 320 L135 345 Z" fill="#F97316"/>
  <path d="M152 292 L172 312 L142 328 Z" fill="#FDE047"/>
  <path d="M170 230 L135 300 L185 275 Z" fill="#EF4444" stroke="#991B1B" stroke-width="3" stroke-linejoin="round"/>
  <path d="M255 145 L325 180 L270 230 Z" fill="#EF4444" stroke="#991B1B" stroke-width="3" stroke-linejoin="round"/>
  <g transform="rotate(-38 230 210)">
    <rect x="180" y="140" width="100" height="150" rx="45" fill="#F8FAFC" stroke="#0F172A" stroke-width="4"/>
    <path d="M180 160 C180 90 280 90 280 160 Z" fill="#EF4444" stroke="#0F172A" stroke-width="4"/>
    <rect x="180" y="215" width="100" height="24" fill="#EF4444"/>
    <circle cx="230" cy="180" r="22" fill="#38BDF8" stroke="#0F172A" stroke-width="4"/>
    <circle cx="230" cy="180" r="16" fill="#0284C7"/>
    <path d="M220 172 A 12 12 0 0 1 238 172" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
  </g>
  <g transform="translate(195, 100) scale(0.95)">
    <ellipse cx="60" cy="65" rx="44" ry="38" fill="#818CF8" stroke="#1E1B4B" stroke-width="3.5"/>
    <path d="M22 68 C12 68 8 52 14 44 C18 38 26 42 24 50 C22 56 26 62 34 65" fill="#818CF8" stroke="#1E1B4B" stroke-width="3.5" stroke-linecap="round"/>
    <path d="M28 66 L20 72 L26 76 Z" fill="#FFFFFF" stroke="#1E1B4B" stroke-width="2"/>
    <rect x="36" y="80" width="18" height="26" rx="9" fill="#6366F1" stroke="#1E1B4B" stroke-width="3"/>
    <rect x="70" y="84" width="20" height="24" rx="10" fill="#6366F1" stroke="#1E1B4B" stroke-width="3"/>
    <ellipse cx="78" cy="54" rx="22" ry="26" fill="#A5B4FC" stroke="#1E1B4B" stroke-width="3.5"/>
    <ellipse cx="76" cy="54" rx="13" ry="16" fill="#C7D2FE"/>
    <circle cx="44" cy="50" r="8.5" fill="#FFFFFF" stroke="#1E1B4B" stroke-width="2.5"/>
    <circle cx="42" cy="50" r="4.5" fill="#0F172A"/>
    <circle cx="44" cy="48" r="1.8" fill="#FFFFFF"/>
    <ellipse cx="46" cy="62" rx="6" ry="4" fill="#F472B6" opacity="0.8"/>
  </g>
</svg>
SVG;
        $doc = new \MyPHP\UI\Svg\Doc($svg);
        $bytes = $doc->pixelBytes($size, $size, [0, 0, 0, 1], true);
        // Unpremultiply border pixels for straight RGBA PNG encoding
        $f = \MyPHP\UI\Svg\Layer::ffi();
        $n = strlen($bytes);
        $buf = $f->cast('uint8_t*', $f->calloc($n, 1));
        \FFI::memcpy($buf, $bytes, $n);
        for ($i = 0; $i < $n; $i += 4) {
            $a = $buf[$i + 3];
            if ($a > 0 && $a < 255) {
                $buf[$i]     = min(255, intdiv($buf[$i]     * 255 + ($a >> 1), $a));
                $buf[$i + 1] = min(255, intdiv($buf[$i + 1] * 255 + ($a >> 1), $a));
                $buf[$i + 2] = min(255, intdiv($buf[$i + 2] * 255 + ($a >> 1), $a));
            }
        }
        $straight = \FFI::string($buf, $n);
        $f->free($buf);
        return self::png($size, $straight);
    }
}
