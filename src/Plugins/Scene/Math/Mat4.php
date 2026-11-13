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
        $out = array_fill(0, 16, 0.0);

        for ($i = 0; $i < 4; $i++) {
            $ai0 = $a[$i];
            $ai1 = $a[$i + 4];
            $ai2 = $a[$i + 8];
            $ai3 = $a[$i + 12];

            $out[$i]      = $ai0 * $bm[0]  + $ai1 * $bm[1]  + $ai2 * $bm[2]  + $ai3 * $bm[3];
            $out[$i + 4]  = $ai0 * $bm[4]  + $ai1 * $bm[5]  + $ai2 * $bm[6]  + $ai3 * $bm[7];
            $out[$i + 8]  = $ai0 * $bm[8]  + $ai1 * $bm[9]  + $ai2 * $bm[10] + $ai3 * $bm[11];
            $out[$i + 12] = $ai0 * $bm[12] + $ai1 * $bm[13] + $ai2 * $bm[14] + $ai3 * $bm[15];
        }

        return new self($out);
    }

    /**
     * Raw 64-byte binary float buffer ready for direct GPU Uniform binding.
     */
    public function toBinary(): string
    {
        return pack('f16', ...$this->m);
    }
}
