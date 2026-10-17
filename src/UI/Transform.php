<?php
declare(strict_types=1);

namespace MyPHP\UI;

use InvalidArgumentException;

/**
 * A visual transform, in 2D, or in 3D seen in perspective (mygo's
 * Transform). Transform::identity() changes nothing. It changes drawing and
 * interaction, leaving the space the layout reserved as it is. Build one
 * with translation, scaling, rotation and skewing, and in 3D rotationX,
 * rotationY and perspective, and compose them with then(). Nodes inherit
 * their ancestors' transforms; each flattens what its 3D transform makes of
 * it into its parent's plane, as CSS's flat transform-style.
 */
final class Transform
{
    private static ?self $identity = null;

    /** @param ?list<float> $d3 a transform in 3D, by rows, of (x, y, z, 1), which $m is not */
    private function __construct(private readonly Affine $m, private readonly ?array $d3 = null) {}

    public static function identity(): self
    {
        return self::$identity ??= new self(Affine::identity());
    }

    /** Moves content by x and y points. */
    public static function translation(float $x, float $y): self
    {
        self::finite($x, $y);
        return new self(Affine::translation($x, $y));
    }

    /** Scales each axis; zero collapses it and negative values reflect it. */
    public static function scaling(float $x, float $y): self
    {
        self::finite($x, $y);
        return new self(Affine::scaling($x, $y));
    }

    /** Rotates clockwise by degrees in the window's coordinate system. */
    public static function rotation(float $degrees): self
    {
        self::finite($degrees);
        return new self(Affine::rotation($degrees));
    }

    /** Slants content by x degrees along the horizontal axis and y along the vertical, as CSS's skew(x, y). */
    public static function skewing(float $x, float $y): self
    {
        self::finite($x, $y);
        $tx = tan(deg2rad($x)); $ty = tan(deg2rad($y));
        self::finite($tx, $ty);
        return new self(Affine::matrix(1, $ty, $tx, 1, 0, 0));
    }

    /** Turns content in 3D around the horizontal axis through its origin, its top going away for positive degrees, as CSS's rotateX; without perspective after it, it only looks shorter. */
    public static function rotationX(float $degrees): self
    {
        self::finite($degrees);
        $s = sin(deg2rad($degrees)); $c = cos(deg2rad($degrees));
        $m = self::identity4();
        $m[5] = $c; $m[6] = -$s; $m[9] = $s; $m[10] = $c;
        return new self(Affine::identity(), $m);
    }

    /** Turns content in 3D around the vertical axis through its origin, its right side going away for positive degrees, as CSS's rotateY. */
    public static function rotationY(float $degrees): self
    {
        self::finite($degrees);
        $s = sin(deg2rad($degrees)); $c = cos(deg2rad($degrees));
        $m = self::identity4();
        $m[0] = $c; $m[2] = $s; $m[8] = -$s; $m[10] = $c;
        return new self(Affine::identity(), $m);
    }

    /** Sees what the transforms before it put in 3D from $distance points in front of the origin, as CSS's perspective(); 800 or so looks natural for a card turning over. */
    public static function perspective(float $distance): self
    {
        self::finite($distance);
        if ($distance <= 0) {
            throw new InvalidArgumentException("perspective's distance must be positive");
        }
        $m = self::identity4();
        $m[14] = -1 / $distance;
        return new self(Affine::identity(), $m);
    }

    /** Whether it changes anything. */
    public function set(): bool
    {
        return $this->m->set || $this->d3 !== null;
    }

    /** This first, then $next. */
    public function then(self $next): self
    {
        if ($this->d3 === null && $next->d3 === null) {
            $m = $next->m->mul($this->m);
            self::finite($m->a, $m->b, $m->c, $m->d, $m->x, $m->y, $m->g, $m->h);
            return new self($m);
        }
        $a = $next->mat4(); $b = $this->mat4(); $p = [];
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $p[4 * $i + $j] = $a[4 * $i] * $b[$j] + $a[4 * $i + 1] * $b[4 + $j] + $a[4 * $i + 2] * $b[8 + $j] + $a[4 * $i + 3] * $b[12 + $j];
            }
        }
        self::finite(...$p);
        return new self(Affine::identity(), $p);
    }

    /** What it makes of its plane, z = 0, seen from the front. */
    public function flat(): Affine
    {
        if ($this->d3 === null) {
            return $this->m;
        }
        $m = $this->d3; $k = $m[15];
        if ($k == 0 || is_nan($k)) {
            return Affine::singular(); // nothing shows
        }
        return Affine::projective($m[0] / $k, $m[4] / $k, $m[1] / $k, $m[5] / $k, $m[3] / $k, $m[7] / $k, $m[12] / $k, $m[13] / $k);
    }

    /** Whether it turns the front of its plane, which faces the viewer, away: whether its normal points away. */
    public function facesAway(): bool
    {
        if ($this->d3 === null) {
            return false;
        }
        $m = $this->d3;
        $det = $m[0] * ($m[5] * $m[10] - $m[6] * $m[9]) - $m[1] * ($m[4] * $m[10] - $m[6] * $m[8]) + $m[2] * ($m[4] * $m[9] - $m[5] * $m[8]);
        if ($det == 0) {
            return false;
        }
        return ($m[0] * $m[5] - $m[1] * $m[4]) / $det < 0; // the normal's z, by the inverse transpose of the linear part
    }

    /** @return list<float> */
    private static function identity4(): array
    {
        return [1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0];
    }

    /** It in 3D. @return list<float> */
    private function mat4(): array
    {
        if ($this->d3 !== null) {
            return $this->d3;
        }
        $m = self::identity4();
        if (($a = $this->m)->set) {
            $m[0] = $a->a; $m[1] = $a->c; $m[3] = $a->x;
            $m[4] = $a->b; $m[5] = $a->d; $m[7] = $a->y;
            $m[12] = $a->g; $m[13] = $a->h;
        }
        return $m;
    }

    private static function finite(float ...$v): void
    {
        foreach ($v as $n) {
            if (!is_finite($n)) {
                throw new InvalidArgumentException('transform values must be finite');
            }
        }
    }
}
