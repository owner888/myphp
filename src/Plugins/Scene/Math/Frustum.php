<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Math;

/**
 * 3D View Frustum extracted from a ViewProjection matrix (Gribb-Hartmann method).
 * Performs ultra-fast O(1) bounding-sphere culling on the CPU before GPU Draw Calls.
 */
final class Frustum
{
    /**
     * 6 frustum planes: [nx, ny, nz, d] where nx*x + ny*y + nz*z + d >= 0 is inside.
     * @var list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public array $planes = [];

    private function __construct(array $planes)
    {
        $this->planes = $planes;
    }

    /**
     * Extracts the 6 normalized frustum planes from a column-major ViewProjection matrix.
     */
    public static function fromViewProjection(Mat4 $vp): self
    {
        $m = $vp->m;

        // Rows of column-major matrix:
        // row 0: m[0], m[4], m[8],  m[12]
        // row 1: m[1], m[5], m[9],  m[13]
        // row 2: m[2], m[6], m[10], m[14]
        // row 3: m[3], m[7], m[11], m[15]
        $rawPlanes = [
            // Left:   row3 + row0
            [$m[3] + $m[0], $m[7] + $m[4], $m[11] + $m[8],  $m[15] + $m[12]],
            // Right:  row3 - row0
            [$m[3] - $m[0], $m[7] - $m[4], $m[11] - $m[8],  $m[15] - $m[12]],
            // Bottom: row3 + row1
            [$m[3] + $m[1], $m[7] + $m[5], $m[11] + $m[9],  $m[15] + $m[13]],
            // Top:    row3 - row1
            [$m[3] - $m[1], $m[7] - $m[5], $m[11] - $m[9],  $m[15] - $m[13]],
            // Near:   row2 (for [0, 1] depth convention)
            [$m[2],         $m[6],         $m[10],          $m[14]],
            // Far:    row3 - row2
            [$m[3] - $m[2], $m[7] - $m[6], $m[11] - $m[10], $m[15] - $m[14]],
        ];

        $planes = [];
        foreach ($rawPlanes as [$a, $b, $c, $d]) {
            $len = sqrt($a * $a + $b * $b + $c * $c);
            if ($len > 1e-6) {
                $inv = 1.0 / $len;
                $planes[] = [$a * $inv, $b * $inv, $c * $inv, $d * $inv];
            }
        }

        return new self($planes);
    }

    /**
     * Returns true if a sphere at $center with $radius is at least partially inside the frustum.
     */
    public function intersectsSphere(Vec3 $center, float $radius): bool
    {
        $x = $center->x;
        $y = $center->y;
        $z = $center->z;

        foreach ($this->planes as [$nx, $ny, $nz, $d]) {
            if ($nx * $x + $ny * $y + $nz * $z + $d < -$radius) {
                return false;
            }
        }
        return true;
    }
}
