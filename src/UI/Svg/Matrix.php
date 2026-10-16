<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Affine transforms as arrays of six floats, mapping (x, y) to (m[0]x +
 * m[2]y + m[4], m[1]x + m[3]y + m[5]) as SVG's matrix(a, b, c, d, e, f)
 * does; points are [x, y] and boxes [x, y, w, h], in user units. mygo's
 * internal/svg geom.go, the matrix and box half.
 */
final class Matrix
{
    public const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** The transform that applies $n, then $m. @return array<float> */
    public static function mul(array $m, array $n): array
    {
        return [
            $m[0] * $n[0] + $m[2] * $n[1],
            $m[1] * $n[0] + $m[3] * $n[1],
            $m[0] * $n[2] + $m[2] * $n[3],
            $m[1] * $n[2] + $m[3] * $n[3],
            $m[0] * $n[4] + $m[2] * $n[5] + $m[4],
            $m[1] * $n[4] + $m[3] * $n[5] + $m[5],
        ];
    }

    /** @return array{0: float, 1: float} */
    public static function apply(array $m, float $x, float $y): array
    {
        return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
    }

    /** The inverse of $m, or null when it flattens the plane. */
    public static function invert(array $m): ?array
    {
        $d = $m[0] * $m[3] - $m[1] * $m[2];
        if ($d == 0 || is_nan($d) || is_infinite($d)) { return null; }
        return [$m[3] / $d, -$m[1] / $d, -$m[2] / $d, $m[0] / $d, ($m[2] * $m[5] - $m[3] * $m[4]) / $d, ($m[1] * $m[4] - $m[0] * $m[5]) / $d];
    }

    /** The most $m lengthens a line: its largest singular value. */
    public static function stretch(array $m): float
    {
        [$a, $b, $c, $d] = $m;
        $e = $a * $a + $b * $b - $c * $c - $d * $d;
        $f = $a * $c + $b * $d;
        return sqrt(($a * $a + $b * $b + $c * $c + $d * $d + sqrt($e * $e + 4 * $f * $f)) / 2);
    }

    public static function translate(float $x, float $y): array { return [1.0, 0.0, 0.0, 1.0, $x, $y]; }

    public static function scaling(float $x, float $y): array { return [$x, 0.0, 0.0, $y, 0.0, 0.0]; }

    public static function rotation(float $deg): array
    {
        $s = sin($deg * M_PI / 180); $c = cos($deg * M_PI / 180);
        return [$c, $s, -$s, $c, 0.0, 0.0];
    }

    /** Maps the unit square to box $b, for objectBoundingBox units. */
    public static function box(array $b): array { return [$b[2], 0.0, 0.0, $b[3], $b[0], $b[1]]; }

    /** The box around both. */
    public static function union(array $b, array $o): array
    {
        $x0 = min($b[0], $o[0]); $y0 = min($b[1], $o[1]);
        $x1 = max($b[0] + $b[2], $o[0] + $o[2]); $y1 = max($b[1] + $b[3], $o[1] + $o[3]);
        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }

    /** The box around box $b transformed by $m. */
    public static function transformBox(array $b, array $m): array
    {
        $out = null;
        foreach ([[$b[0], $b[1]], [$b[0] + $b[2], $b[1]], [$b[0], $b[1] + $b[3]], [$b[0] + $b[2], $b[1] + $b[3]]] as [$x, $y]) {
            [$qx, $qy] = self::apply($m, $x, $y);
            $out = $out === null ? [$qx, $qy, 0.0, 0.0] : self::union($out, [$qx, $qy, 0.0, 0.0]);
        }
        return $out;
    }

    /**
     * Parses a transform list, such as "translate(10 20) rotate(45)". A
     * list in error transforms nothing, as in browsers.
     */
    public static function parse(string $s): array
    {
        $m = self::IDENTITY;
        $p = new Scanner($s);
        while (true) {
            $p->skipSep();
            if ($p->done()) { return $m; }
            $name = $p->ident();
            $p->skipSpace();
            if (!$p->eat('(')) { return self::IDENTITY; }
            $a = [];
            while (true) {
                $p->skipSep();
                if ($p->eat(')')) { break; }
                $v = $p->number();
                if ($v === null || count($a) === 6) { return self::IDENTITY; }
                $a[] = $v;
            }
            $n = count($a);
            $t = match (true) {
                $name === 'matrix' && $n === 6 => $a,
                $name === 'translate' && ($n === 1 || $n === 2) => self::translate($a[0], $a[1] ?? 0.0),
                $name === 'scale' && $n === 1 => self::scaling($a[0], $a[0]),
                $name === 'scale' && $n === 2 => self::scaling($a[0], $a[1]),
                $name === 'rotate' && $n === 1 => self::rotation($a[0]),
                $name === 'rotate' && $n === 3 => self::mul(self::mul(self::translate($a[1], $a[2]), self::rotation($a[0])), self::translate(-$a[1], -$a[2])),
                $name === 'skewX' && $n === 1 => [1.0, 0.0, tan($a[0] * M_PI / 180), 1.0, 0.0, 0.0],
                $name === 'skewY' && $n === 1 => [1.0, tan($a[0] * M_PI / 180), 0.0, 1.0, 0.0, 0.0],
                default => null,
            };
            if ($t === null) { return self::IDENTITY; }
            $m = self::mul($m, $t);
        }
    }
}
