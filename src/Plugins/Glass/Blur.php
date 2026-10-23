<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Glass;

use MyPHP\UI\{LinearGradient, Material, Painter};

/**
 * A backdrop blur, a material (mygo's glass.Blur): what was painted under
 * the node shows through it blurred, with no tint, rim or shadow. With a
 * mask, the blur fades along a gradient, a progressive blur, as under a
 * bar that content scrolls under (ScrollEdge is macOS's own effect there):
 *
 *     $c->row(8, 12)->at(0, 0)->size($c->width, 56)->material(new Blur(12, new LinearGradient(0x000000, 1, 0x000000, 0, 180)));
 */
final class Blur implements Material
{
    /** The most the first level of a varying blur blurs, in pixels: it shows mixed with what is not blurred, which at 2 pixels looks as a blur between them. */
    public const FINEST_LEVEL = 2;

    public function __construct(
        /** How much what shows through blurs, in points: the standard deviation of a Gaussian, as CSS's blur() takes. */
        public float $radius = 0,
        /** Varies the blur along a gradient, by the alpha of its colors, as CSS's mask-image does: what shows through blurs by $radius where the gradient is opaque, by less where it is translucent, and not at all where it is transparent. Its colors do not show. */
        public ?LinearGradient $mask = null,
    ) {}

    public function paintMaterial(Painter $p, array $box, float $radius): void
    {
        $s = $p->scale();
        if ($box[2] <= 0 || $box[3] <= 0) {
            return;
        }
        // The rectangle of the node, in pixels, as the painter snaps it.
        $px = [round($box[0] * $s), round($box[1] * $s)];
        $px[] = round(($box[0] + $box[2]) * $s) - $px[0];
        $px[] = round(($box[1] + $box[3]) * $s) - $px[1];
        foreach (self::ops($px, $radius == 0, $this->radius * $s, $this->mask) as [$r, $sigma, $line, $band]) {
            $p->blurLevel([$r[0] / $s, $r[1] / $s, $r[2] / $s, $r[3] / $s], $radius, $sigma, $line, ...$band);
        }
    }

    /**
     * The rectangle, blur, mask line and band (at0, at1, lo, hi) of each
     * level (levels()) of a blur of $most pixels over $r, in pixels, along
     * $mask: a level after another, each where the blur is more than its
     * lo when $r is square, which it then reads only what is under.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @return list<array{0: array{0: float, 1: float, 2: float, 3: float}, 1: float, 2: array{0: float, 1: float, 2: float, 3: float}, 3: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function ops(array $r, bool $square, float $most, ?LinearGradient $mask): array
    {
        if ($r[2] <= 0 || $r[3] <= 0 || $most <= 0) {
            return [];
        }
        // The blur at the mask's start and end, along its line.
        $line = [0.0, 0.0, 0.0, 0.0];
        $at0 = $at1 = $most;
        if ($mask !== null) {
            $line = self::maskLine($r, $mask);
            [$at0, $at1] = [$most * $mask->fromAlpha, $most * $mask->toAlpha];
        }
        $ops = [];
        foreach (self::levels(min($at0, $at1), max($at0, $at1)) as [$blur, $lo, $hi]) {
            $lr = $r;
            if ($square && $lo >= 0) {
                $lr = self::wanted($r, $line, $at0, $at1, $lo);
                if ($lr === null) {
                    continue;
                }
            }
            $ops[] = [$lr, $blur, $line, [$at0, $at1, $lo, $hi]];
        }
        return $ops;
    }

    /**
     * The levels of a blur varying from $least to $most pixels: [blur, lo,
     * hi] each, what was painted under it, the levels before included,
     * blurred by blur pixels, shown where the blur wanted is more than lo
     * pixels, by how far it is toward hi, and wholly from hi. Blurs add up
     * as their variances do, so after the levels showing wholly at a pixel,
     * what shows there is blurred by hi pixels of the last of them: the his
     * double from one level to the next, from at most FINEST_LEVEL pixels
     * to the most, and between two, a pixel shows the blurs of both, mixed,
     * which the eye takes for a blur between them. A blur that does not
     * vary is one level, which shows everywhere.
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public static function levels(float $least, float $most): array
    {
        $least = max($least, 0);
        if ($most <= $least) {
            return $least <= 0 ? [] : [[$least, -1.0, 0.0]];
        }
        // The levels' his, from the most down, halving.
        $his = [$most];
        for ($h = $most; $h > self::FINEST_LEVEL && $h / 2 > $least;) {
            $h /= 2;
            $his[] = $h;
        }
        $levels = $least > 0 ? [[$least, -1.0, 0.0]] : [];
        $lo = $least;
        for ($i = count($his) - 1; $i >= 0; $i--) {
            $hi = $his[$i];
            $levels[] = [sqrt($hi * $hi - $lo * $lo), $lo, $hi];
            $lo = $hi;
        }
        return $levels;
    }

    /**
     * Where the mask's from and to are, in pixels, as a gradient is placed
     * across $r: along a line through its middle at the gradient's angle,
     * as long as the rectangle's corners are apart along it, as CSS's
     * linear-gradient.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function maskLine(array $r, LinearGradient $g): array
    {
        $a = deg2rad($g->angle);
        $dx = sin($a); $dy = -cos($a);
        $half = (abs($r[2] * $dx) + abs($r[3] * $dy)) / 2;
        $cx = $r[0] + $r[2] / 2; $cy = $r[1] + $r[3] / 2;
        [$x0, $y0, $x1, $y1] = [$cx - $dx * $half, $cy - $dy * $half, $cx + $dx * $half, $cy + $dy * $half];
        [$start, $end] = [$g->start, $g->end];
        if ($start == 0 && $end == 0) {
            $end = 1;
        }
        $sx = $x0 + ($x1 - $x0) * $start; $sy = $y0 + ($y1 - $y0) * $start;
        $ex = $x0 + ($x1 - $x0) * $end; $ey = $y0 + ($y1 - $y0) * $end;
        if ($end - $start < 0.5 / max(2 * $half, 1)) {
            // Stops at one place: a hard edge, half a pixel wide.
            [$ex, $ey] = [$sx + $dx * 0.5, $sy + $dy * 0.5];
        }
        return [$sx, $sy, $ex, $ey];
    }

    /**
     * The bounds, in whole pixels, of the part of $r where the blur along
     * $line, from $at0 at its start to $at1 at its end, is more than $lo,
     * or null when there is none.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param array{0: float, 1: float, 2: float, 3: float} $line
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    public static function wanted(array $r, array $line, float $at0, float $at1, float $lo): ?array
    {
        if ($at0 == $at1) {
            return $at0 > $lo ? $r : null;
        }
        // Along the line, the blur is lo at tc, and more on one side of it:
        // the side of the half-plane n·p > c.
        $dx = $line[2] - $line[0]; $dy = $line[3] - $line[1];
        $tc = ($lo - $at0) / ($at1 - $at0);
        [$nx, $ny] = [$dx, $dy];
        $c = $line[0] * $dx + $line[1] * $dy + $tc * ($dx * $dx + $dy * $dy);
        if ($at1 < $at0) {
            [$nx, $ny, $c] = [-$nx, -$ny, -$c];
        }
        $corners = [[$r[0], $r[1]], [$r[0] + $r[2], $r[1]], [$r[0] + $r[2], $r[1] + $r[3]], [$r[0], $r[1] + $r[3]]];
        $x0 = $y0 = INF; $x1 = $y1 = -INF;
        foreach ($corners as $i => $a) {
            $b = $corners[($i + 1) % 4];
            $fa = $nx * $a[0] + $ny * $a[1] - $c; $fb = $nx * $b[0] + $ny * $b[1] - $c;
            $add = [];
            if ($fa > 0) {
                $add[] = $a;
            }
            if (($fa > 0) !== ($fb > 0)) {
                $k = $fa / ($fa - $fb);
                $add[] = [$a[0] + ($b[0] - $a[0]) * $k, $a[1] + ($b[1] - $a[1]) * $k];
            }
            foreach ($add as [$x, $y]) {
                [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
            }
        }
        if ($x0 > $x1 || $y0 > $y1) {
            return null;
        }
        [$x0, $y0] = [max(floor($x0), $r[0]), max(floor($y0), $r[1])];
        [$x1, $y1] = [min(ceil($x1), $r[0] + $r[2]), min(ceil($y1), $r[1] + $r[3])];
        if ($x1 <= $x0 || $y1 <= $y0) {
            return null;
        }
        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }
}
