<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Math;

/**
 * 3D Ray (origin + normalized direction) for raycasting and 3D picking.
 */
final class Ray
{
    public Vec3 $origin;
    public Vec3 $direction;

    public function __construct(Vec3 $origin, Vec3 $direction)
    {
        $this->origin = $origin;
        $this->direction = $direction->normalize();
    }

    /**
     * Returns the 3D point along the ray at distance $t.
     */
    public function at(float $t): Vec3
    {
        return $this->origin->add($this->direction->scale($t));
    }

    /**
     * Intersects this ray with a sphere at $center of $radius.
     * Returns distance $t >= 0 along the ray to the closest hit, or null if missed.
     */
    public function intersectSphere(Vec3 $center, float $radius): ?float
    {
        $oc = $this->origin->sub($center);
        $b = $oc->dot($this->direction);
        $c = $oc->dot($oc) - $radius * $radius;
        $disc = $b * $b - $c;

        if ($disc < 0.0) {
            return null;
        }

        $sqrtDisc = sqrt($disc);
        $t1 = -$b - $sqrtDisc;
        if ($t1 >= 0.0) {
            return $t1;
        }
        $t2 = -$b + $sqrtDisc;
        return $t2 >= 0.0 ? $t2 : null;
    }

    /**
     * Intersects this ray with an Axis-Aligned Bounding Box (AABB) defined by $min and $max.
     * Uses the fast slab method. Returns distance $t >= 0, or null if missed.
     */
    public function intersectAABB(Vec3 $min, Vec3 $max): ?float
    {
        $tMin = 0.0;
        $tMax = INF;

        $orig = [$this->origin->x, $this->origin->y, $this->origin->z];
        $dir  = [$this->direction->x, $this->direction->y, $this->direction->z];
        $bMin = [$min->x, $min->y, $min->z];
        $bMax = [$max->x, $max->y, $max->z];

        for ($i = 0; $i < 3; $i++) {
            if (abs($dir[$i]) < 1e-8) {
                if ($orig[$i] < $bMin[$i] || $orig[$i] > $bMax[$i]) {
                    return null;
                }
            } else {
                $invD = 1.0 / $dir[$i];
                $t0 = ($bMin[$i] - $orig[$i]) * $invD;
                $t1 = ($bMax[$i] - $orig[$i]) * $invD;
                if ($invD < 0.0) {
                    [$t0, $t1] = [$t1, $t0];
                }
                if ($t0 > $tMin) { $tMin = $t0; }
                if ($t1 < $tMax) { $tMax = $t1; }
                if ($tMax < $tMin) {
                    return null;
                }
            }
        }

        return $tMin >= 0.0 ? $tMin : ($tMax >= 0.0 ? $tMax : null);
    }
}
