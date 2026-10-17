<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A map from drawing coordinates to the window's (mygo's scene.Affine): an
 * affine map, or, when g or h is set, a projective one, which shows a plane
 * turned in 3D in perspective, x and y going to
 *
 *     ((a*x + c*y + x0) / w, (b*x + d*y + y0) / w), with w = g*x + h*y + 1.
 *
 * Affine::identity() is not set; a set map of zeros is singular, and shows
 * nothing.
 */
final class Affine
{
    private static ?self $identity = null;

    private function __construct(
        public readonly float $a = 1.0, public readonly float $b = 0.0, public readonly float $c = 0.0, public readonly float $d = 1.0,
        public readonly float $x = 0.0, public readonly float $y = 0.0, public readonly float $g = 0.0, public readonly float $h = 0.0,
        public readonly bool $set = false,
    ) {}

    public static function identity(): self
    {
        return self::$identity ??= new self();
    }

    /** A map without an inverse, which shows nothing. */
    public static function singular(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, true);
    }

    public static function matrix(float $a, float $b, float $c, float $d, float $x, float $y): self
    {
        return self::projective($a, $b, $c, $d, $x, $y, 0, 0);
    }

    /** The projective map of the matrix | a c x | b d y | g h 1 |. */
    public static function projective(float $a, float $b, float $c, float $d, float $x, float $y, float $g, float $h): self
    {
        if ($a == 1 && $b == 0 && $c == 0 && $d == 1 && $x == 0 && $y == 0 && $g == 0 && $h == 0) {
            return self::identity();
        }
        return new self($a, $b, $c, $d, $x, $y, $g, $h, true);
    }

    public static function translation(float $x, float $y): self { return self::matrix(1, 0, 0, 1, $x, $y); }

    public static function scaling(float $x, float $y): self { return self::matrix($x, 0, 0, $y, 0, 0); }

    public static function rotation(float $degrees): self
    {
        $angle = fmod($degrees, 360.0);
        if ($angle > 180) { $angle -= 360; } elseif ($angle < -180) { $angle += 360; } // as math.Remainder
        return match (true) {
            $angle == 0 => self::identity(),
            $angle == 90 => self::matrix(0, 1, -1, 0, 0, 0),
            $angle == -90 => self::matrix(0, -1, 1, 0, 0, 0),
            $angle == 180 || $angle == -180 => self::matrix(-1, 0, 0, -1, 0, 0),
            default => self::matrix(cos(deg2rad($angle)), sin(deg2rad($angle)), -sin(deg2rad($angle)), cos(deg2rad($angle)), 0, 0),
        };
    }

    /** Whether the map is projective, not affine. */
    public function perspective(): bool
    {
        return $this->set && ($this->g != 0 || $this->h != 0);
    }

    /** @return array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}} by rows */
    private function rows(): array
    {
        return [[$this->a, $this->c, $this->x], [$this->b, $this->d, $this->y], [$this->g, $this->h, 1.0]];
    }

    /** The map of matrix $p, scaled so that its last entry is 1; null when it has none. */
    private static function fromRows(array $p): ?self
    {
        $k = $p[2][2];
        if (abs($k) < 1e-12 || !is_finite($k)) {
            return null;
        }
        $v = [$p[0][0] / $k, $p[1][0] / $k, $p[0][1] / $k, $p[1][1] / $k, $p[0][2] / $k, $p[1][2] / $k, $p[2][0] / $k, $p[2][1] / $k];
        foreach ($v as $n) {
            if (!is_finite($n) || abs($n) > 3.4028234663852886e38) {
                return null;
            }
        }
        return self::projective(...$v);
    }

    /** $this * $n: $n acts first, then this. */
    public function mul(self $n): self
    {
        if (!$this->set) {
            return $n;
        }
        if (!$n->set) {
            return $this;
        }
        if (!$this->perspective() && !$n->perspective()) {
            return self::matrix($this->a * $n->a + $this->c * $n->b, $this->b * $n->a + $this->d * $n->b,
                $this->a * $n->c + $this->c * $n->d, $this->b * $n->c + $this->d * $n->d,
                $this->a * $n->x + $this->c * $n->y + $this->x, $this->b * $n->x + $this->d * $n->y + $this->y);
        }
        $a = $this->rows(); $b = $n->rows(); $p = [];
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $p[$i][$j] = $a[$i][0] * $b[0][$j] + $a[$i][1] * $b[1][$j] + $a[$i][2] * $b[2][$j];
            }
        }
        return self::fromRows($p) ?? self::singular();
    }

    /** The projective divisor of (x, y): positive in front of the viewer, as all of an affine map's plane is. */
    public function w(float $x, float $y): float
    {
        return $this->set ? $this->g * $x + $this->h * $y + 1 : 1.0;
    }

    /** Where the map takes (x, y); a point behind the viewer of a projective map goes far away. @return array{0: float, 1: float} */
    public function point(float $x, float $y): array
    {
        if (!$this->set) {
            return [$x, $y];
        }
        $px = $this->a * $x + $this->c * $y + $this->x; $py = $this->b * $x + $this->d * $y + $this->y;
        if ($this->g == 0 && $this->h == 0) {
            return [$px, $py];
        }
        $w = max($this->w($x, $y), 1e-6);
        return [$px / $w, $py / $w];
    }

    /** How far the map moves the point (x, y) moved by (dx, dy): the same for every point of an affine map. @return array{0: float, 1: float} */
    public function vectorAt(float $x, float $y, float $dx, float $dy): array
    {
        if (!$this->set) {
            return [$dx, $dy];
        }
        if ($this->g == 0 && $this->h == 0) {
            return [$this->a * $dx + $this->c * $dy, $this->b * $dx + $this->d * $dy];
        }
        [$x0, $y0] = $this->point($x, $y); [$x1, $y1] = $this->point($x + $dx, $y + $dy);
        return [$x1 - $x0, $y1 - $y0];
    }

    /** The inverse map, or null for a singular one. */
    public function inverse(): ?self
    {
        if (!$this->set) {
            return $this;
        }
        if ($this->g == 0 && $this->h == 0) {
            $det = $this->a * $this->d - $this->b * $this->c;
            if ($det == 0 || !is_finite($det)) {
                return null;
            }
            $a = $this->d / $det; $b = -$this->b / $det; $c = -$this->c / $det; $d = $this->a / $det;
            $v = [$a, $b, $c, $d, -$a * $this->x - $c * $this->y, -$b * $this->x - $d * $this->y];
            foreach ($v as $n) {
                if (!is_finite($n)) { return null; }
            }
            return self::matrix(...$v);
        }
        $q = $this->rows();
        $cof = fn(int $r0, int $r1, int $c0, int $c1) => $q[$r0][$c0] * $q[$r1][$c1] - $q[$r0][$c1] * $q[$r1][$c0];
        $adj = [
            [$cof(1, 2, 1, 2), -$cof(0, 2, 1, 2), $cof(0, 1, 1, 2)],
            [-$cof(1, 2, 0, 2), $cof(0, 2, 0, 2), -$cof(0, 1, 0, 2)],
            [$cof(1, 2, 0, 1), -$cof(0, 2, 0, 1), $cof(0, 1, 0, 1)],
        ];
        $det = $q[0][0] * $adj[0][0] + $q[0][1] * $adj[1][0] + $q[0][2] * $adj[2][0];
        if (abs($det) < 1e-12 || !is_finite($det)) {
            return null;
        }
        return self::fromRows($adj); // the inverse up to a factor, which fromRows drops
    }

    /** The bounding rect of what the map makes of the rect: all of the window when part of it is behind the viewer. @return array{0: float, 1: float, 2: float, 3: float} */
    public function bounds(float $x, float $y, float $w, float $h): array
    {
        if (!$this->set) {
            return [$x, $y, $w, $h];
        }
        $corners = [[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]];
        if ($this->perspective()) {
            foreach ($corners as [$cx, $cy]) {
                if ($this->w($cx, $cy) <= 1e-6) {
                    return [-1e6, -1e6, 2e6, 2e6]; // part of it is behind the viewer: it may show anywhere
                }
            }
        }
        [$x0, $y0] = $this->point($x, $y); $x1 = $x0; $y1 = $y0;
        for ($i = 1; $i < 4; $i++) {
            [$px, $py] = $this->point($corners[$i][0], $corners[$i][1]);
            $x0 = min($x0, $px); $y0 = min($y0, $py); $x1 = max($x1, $px); $y1 = max($y1, $py);
        }
        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }
}
