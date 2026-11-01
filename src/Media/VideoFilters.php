<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * What a list of video filters (mpv's vf, IINA's Video Filters) does to a
 * picture of $w by $h, as MyPHP does it without mpv: the filters of IINA's
 * presets, by mpv's or FFmpeg's names, in their order.
 * - crop (mpv's crop, FFmpeg's crop) of w:h:x:y, in pixels, centered where
 *   x or y is none; expand (mpv's expand, FFmpeg's pad) of w:h:x:y, aspect
 *   and round, black around: the part of the picture shown ($part);
 * - vflip and hflip (mpv's flip and mirror too): the picture reversed;
 * - unsharp (IINA's Sharpen and Blur), lutrgb of negval (IINA's Negative)
 *   or negate, lut3d of a .cube file, delogo of x:y:w:h: Core Image's
 *   filters of the video's layer ($effects, Renderers::setPictureFilters).
 * A filter of another name, or of what it cannot do, throws: IINA's
 * "Error occurred when setting filters".
 *
 * @internal Player
 */
final class VideoFilters
{
    /** The part of the picture shown, [x, y, w, h] over its size (crop within it, expand past it), null for the whole. @var ?array{0: float, 1: float, 2: float, 3: float} */
    public ?array $part = null;
    /** Whether the picture shows upside down and left for right. */
    public bool $flip = false, $mirror = false;
    /**
     * The layer's filters, in order: ['unsharp', size, amount], ['negative'],
     * ['lut3d', file, interp], ['delogo', x, y, w, h] (over the picture's
     * size, from its top left, as it is decoded).
     * @var list<array<int, mixed>>
     */
    public array $effects = [];

    /** @param list<Filter> $filters */
    public function __construct(array $filters, int $w, int $h)
    {
        $w = max(1, $w); $h = max(1, $h);
        $r = [0.0, 0.0, (float)$w, (float)$h]; // what shows, in the decoded picture's pixels
        foreach ($filters as $f) {
            $what = $f->what() ?? throw self::wrong($f, 'its graph is not one filter');
            [$name, $p] = $what;
            switch ($name) {
                case 'crop':
                    $cw = self::num($p, ['w', 'out_w', '0'], $r[2], $f);
                    $ch = self::num($p, ['h', 'out_h', '1'], $r[3], $f);
                    $x = self::num($p, ['x', '2'], ($r[2] - $cw) / 2, $f);
                    $y = self::num($p, ['y', '3'], ($r[3] - $ch) / 2, $f);
                    if ($cw <= 0 || $ch <= 0 || $x < 0 || $y < 0 || $x + $cw > $r[2] + 0.5 || $y + $ch > $r[3] + 0.5) {
                        throw self::wrong($f, 'the crop is not within the picture');
                    }
                    $r = [$r[0] + ($this->mirror ? $r[2] - $x - $cw : $x), $r[1] + ($this->flip ? $r[3] - $y - $ch : $y), $cw, $ch];
                    break;
                case 'expand':
                case 'pad':
                    $ew = self::num($p, ['w', 'width', '0'], $r[2], $f);
                    $eh = self::num($p, ['h', 'height', '1'], $r[3], $f);
                    $aspect = self::ratio($p['aspect'] ?? ($name === 'expand' ? $p['4'] ?? '0' : '0'), $f); // FFmpeg's pad has its color fifth
                    if ($aspect > 0) { [$ew, $eh] = $ew / $eh < $aspect ? [$eh * $aspect, $eh] : [$ew, $ew / $aspect]; } // grown to the aspect
                    $round = $name === 'expand' ? (int)self::num($p, ['round', '5'], 1, $f) : 1;
                    if ($round > 1) { [$ew, $eh] = [ceil($ew / $round) * $round, ceil($eh / $round) * $round]; }
                    $x = self::num($p, ['x', '2'], ($ew - $r[2]) / 2, $f);
                    $y = self::num($p, ['y', '3'], ($eh - $r[3]) / 2, $f);
                    if ($ew < $r[2] || $eh < $r[3] || $x < 0 || $y < 0 || $x + $r[2] > $ew + 0.5 || $y + $r[3] > $eh + 0.5) {
                        throw self::wrong($f, 'the picture is not within the expansion');
                    }
                    $r = [$r[0] - ($this->mirror ? $ew - $x - $r[2] : $x), $r[1] - ($this->flip ? $eh - $y - $r[3] : $y), $ew, $eh];
                    break;
                case 'vflip':
                case 'flip':
                    self::none($p, $f);
                    $this->flip = !$this->flip;
                    break;
                case 'hflip':
                case 'mirror':
                    self::none($p, $f);
                    $this->mirror = !$this->mirror;
                    break;
                case 'unsharp':
                    $size = (int)self::num($p, ['lx', 'luma_msize_x', '0'], 5, $f);
                    $amount = self::num($p, ['la', 'luma_amount', '2'], 1, $f);
                    if ($size < 3 || $size > 23 || $size % 2 === 0 || abs($amount) > 2) {
                        throw self::wrong($f, 'its matrix is an odd size of 3 to 23, its amount -2 to 2');
                    }
                    if ($amount != 0.0) { $this->effects[] = ['unsharp', $size, $amount]; }
                    break;
                case 'lutrgb':
                    if (array_diff_key($p, ['r' => 1, 'g' => 1, 'b' => 1]) !== [] || count($p) !== 3 || array_unique(array_values($p)) !== ['negval']) {
                        throw self::wrong($f, 'MyPHP\'s lutrgb is r=negval:g=negval:b=negval (IINA\'s Negative)');
                    }
                    $this->effects[] = ['negative'];
                    break;
                case 'negate':
                    self::none($p, $f);
                    $this->effects[] = ['negative'];
                    break;
                case 'lut3d':
                    $file = $p['file'] ?? $p['0'] ?? '';
                    $interp = $p['interp'] ?? $p['1'] ?? 'tetrahedral';
                    if (!in_array($interp, ['nearest', 'trilinear', 'tetrahedral', 'pyramid', 'prism'], true)) { throw self::wrong($f, "no interpolation \"$interp\""); }
                    $this->effects[] = ['lut3d', Lut3d::read($file), $interp];
                    break;
                case 'delogo':
                    $dx = self::num($p, ['x', '0'], null, $f);
                    $dy = self::num($p, ['y', '1'], null, $f);
                    $dw = self::num($p, ['w', '2'], null, $f);
                    $dh = self::num($p, ['h', '3'], null, $f);
                    if ($dw <= 0 || $dh <= 0 || $dx < 0 || $dy < 0 || $dx + $dw > $r[2] + 0.5 || $dy + $dh > $r[3] + 0.5) {
                        throw self::wrong($f, 'the logo is not within the picture');
                    }
                    $ax = $r[0] + ($this->mirror ? $r[2] - $dx - $dw : $dx);
                    $ay = $r[1] + ($this->flip ? $r[3] - $dy - $dh : $dy);
                    if ($ax < 0 || $ay < 0 || $ax + $dw > $w + 0.5 || $ay + $dh > $h + 0.5) {
                        throw self::wrong($f, 'the logo is past the decoded picture');
                    }
                    $this->effects[] = ['delogo', $ax / $w, $ay / $h, $dw / $w, $dh / $h];
                    break;
                default:
                    throw self::wrong($f, 'MyPHP has no filter of this name');
            }
        }
        if ($r !== [0.0, 0.0, (float)$w, (float)$h]) {
            $this->part = [$r[0] / $w, $r[1] / $h, $r[2] / $w, $r[3] / $h];
        }
    }

    /** Whether the filters do anything. */
    public function any(): bool
    {
        return $this->part !== null || $this->flip || $this->mirror || $this->effects !== [];
    }

    /** The number of the first of $keys $p has, else $default (an error of none). @param array<string, string> $p @param list<string> $keys */
    private static function num(array $p, array $keys, ?float $default, Filter $f): float
    {
        foreach ($keys as $k) {
            if (isset($p[$k]) && $p[$k] !== '') {
                if (!is_numeric($p[$k])) {
                    throw self::wrong($f, "\"{$p[$k]}\" is no number (MyPHP takes no expressions)");
                }
                return (float)$p[$k];
            }
        }
        return $default ?? throw self::wrong($f, $keys[0] . ' is needed');
    }

    /** An aspect written as 4/3, 4:3 or 1.333; 0 for none. */
    private static function ratio(string $s, Filter $f): float
    {
        if (preg_match('~^\s*(\d+(?:\.\d+)?)\s*[/:]\s*(\d+(?:\.\d+)?)\s*$~', $s, $m) === 1) {
            return (float)$m[2] > 0 ? (float)$m[1] / (float)$m[2] : 0.0;
        }
        return is_numeric($s) ? (float)$s : throw self::wrong($f, "\"$s\" is no aspect");
    }

    /** @param array<string, string> $p */
    private static function none(array $p, Filter $f): void
    {
        if ($p !== []) { throw self::wrong($f, 'it takes no parameters'); }
    }

    private static function wrong(Filter $f, string $why): RuntimeException
    {
        return new RuntimeException("{$f->string()}: $why");
    }
}
