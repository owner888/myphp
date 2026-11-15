<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Math;

/**
 * 4x4 matrix for 3D transforms (Column-Major for Metal and Direct3D).
 * Provides ultra-fast matrix math optimized for zero-copy GPU Uniform uploads.
 */
final class Mat4
{
    /**
     * Column-major 16-element float array:
     * [0..3]: column 0, [4..7]: column 1, [8..11]: column 2, [12..15]: column 3.
     * @var array<int, float>
     */
    public array $m;

    /**
     * @param array<int, float>|null $elements
     */
    public function __construct(?array $elements = null)
    {
        $this->m = $elements ?? [
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ];
    }

    public static function identity(): self
    {
        return new self();
    }

    /**
     * Perspective projection matrix for Metal & D3D (depth [0, 1], right-handed).
     */
    public static function perspective(float $fovYRad, float $aspect, float $near, float $far): self
    {
        $f = 1.0 / tan($fovYRad * 0.5);
        $rangeInv = 1.0 / ($near - $far);

        return new self([
            $f / $aspect, 0.0, 0.0,                     0.0,
            0.0,          $f,  0.0,                     0.0,
            0.0,          0.0, $far * $rangeInv,       -1.0,
            0.0,          0.0, $near * $far * $rangeInv, 0.0,
        ]);
    }

    /**
     * Orthographic projection matrix for directional shadow mapping (depth [0, 1], right-handed).
     */
    public static function ortho(float $left, float $right, float $bottom, float $top, float $near, float $far): self
    {
        $rl = 1.0 / ($right - $left);
        $tb = 1.0 / ($top - $bottom);
        $nf = 1.0 / ($near - $far);

        return new self([
            2.0 * $rl,                 0.0,                       0.0,              0.0,
            0.0,                       2.0 * $tb,                 0.0,              0.0,
            0.0,                       0.0,                       $nf,              0.0,
            -($right + $left) * $rl,  -($top + $bottom) * $tb,    $near * $nf,      1.0,
        ]);
    }

    /**
     * LookAt view matrix (right-handed).
     */
    public static function lookAt(Vec3 $eye, Vec3 $target, Vec3 $up): self
    {
        $f = $target->sub($eye)->normalize();
        $s = $f->cross($up)->normalize();
        $u = $s->cross($f);

        return new self([
             $s->x,             $u->x,            -$f->x,            0.0,
             $s->y,             $u->y,            -$f->y,            0.0,
             $s->z,             $u->z,            -$f->z,            0.0,
            -$s->dot($eye),    -$u->dot($eye),     $f->dot($eye),    1.0,
        ]);
    }

    /**
     * Translation matrix.
     */
    public static function translation(float $x, float $y, float $z): self
    {
        return new self([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            $x,  $y,  $z,  1.0,
        ]);
    }

    /**
     * Scale matrix.
     */
    public static function scaling(float $x, float $y, float $z): self
    {
        return new self([
            $x,  0.0, 0.0, 0.0,
            0.0, $y,  0.0, 0.0,
            0.0, 0.0, $z,  0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    /**
     * Rotation around X axis in radians.
     */
    public static function rotationX(float $rad): self
    {
        $c = cos($rad);
        $s = sin($rad);
        return new self([
            1.0, 0.0, 0.0, 0.0,
            0.0,  $c,  $s, 0.0,
            0.0, -$s,  $c, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    /**
     * Rotation around Y axis in radians.
     */
    public static function rotationY(float $rad): self
    {
        $c = cos($rad);
        $s = sin($rad);
        return new self([
             $c, 0.0, -$s, 0.0,
            0.0, 1.0, 0.0, 0.0,
             $s, 0.0,  $c, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    /**
     * Rotation around Z axis in radians.
     */
    public static function rotationZ(float $rad): self
    {
        $c = cos($rad);
        $s = sin($rad);
        return new self([
             $c,  $s, 0.0, 0.0,
            -$s,  $c, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    /**
     * Multiplies this matrix by $b (this * b).
     */
    public function multiply(self $b): self
    {
        $a = $this->m;
        $bm = $b->m;

        $a0  = $a[0];  $a1  = $a[1];  $a2  = $a[2];  $a3  = $a[3];
        $a4  = $a[4];  $a5  = $a[5];  $a6  = $a[6];  $a7  = $a[7];
        $a8  = $a[8];  $a9  = $a[9];  $a10 = $a[10]; $a11 = $a[11];
        $a12 = $a[12]; $a13 = $a[13]; $a14 = $a[14]; $a15 = $a[15];

        $b0  = $bm[0];  $b1  = $bm[1];  $b2  = $bm[2];  $b3  = $bm[3];
        $b4  = $bm[4];  $b5  = $bm[5];  $b6  = $bm[6];  $b7  = $bm[7];
        $b8  = $bm[8];  $b9  = $bm[9];  $b10 = $bm[10]; $b11 = $bm[11];
        $b12 = $bm[12]; $b13 = $bm[13]; $b14 = $bm[14]; $b15 = $bm[15];

        return new self([
            $a0 * $b0  + $a4 * $b1  + $a8 * $b2  + $a12 * $b3,
            $a1 * $b0  + $a5 * $b1  + $a9 * $b2  + $a13 * $b3,
            $a2 * $b0  + $a6 * $b1  + $a10 * $b2 + $a14 * $b3,
            $a3 * $b0  + $a7 * $b1  + $a11 * $b2 + $a15 * $b3,

            $a0 * $b4  + $a4 * $b5  + $a8 * $b6  + $a12 * $b7,
            $a1 * $b4  + $a5 * $b5  + $a9 * $b6  + $a13 * $b7,
            $a2 * $b4  + $a6 * $b5  + $a10 * $b6 + $a14 * $b7,
            $a3 * $b4  + $a7 * $b5  + $a11 * $b6 + $a15 * $b7,

            $a0 * $b8  + $a4 * $b9  + $a8 * $b10 + $a12 * $b11,
            $a1 * $b8  + $a5 * $b9  + $a9 * $b10 + $a13 * $b11,
            $a2 * $b8  + $a6 * $b9  + $a10 * $b10 + $a14 * $b11,
            $a3 * $b8  + $a7 * $b9  + $a11 * $b10 + $a15 * $b11,

            $a0 * $b12 + $a4 * $b13 + $a8 * $b14 + $a12 * $b15,
            $a1 * $b12 + $a5 * $b13 + $a9 * $b14 + $a13 * $b15,
            $a2 * $b12 + $a6 * $b13 + $a10 * $b14 + $a14 * $b15,
            $a3 * $b12 + $a7 * $b13 + $a11 * $b14 + $a15 * $b15,
        ]);
    }

    /**
     * Raw 64-byte binary float buffer ready for direct GPU Uniform binding.
     */
    public function toBinary(): string
    {
        return pack('f16', ...$this->m);
    }
}
