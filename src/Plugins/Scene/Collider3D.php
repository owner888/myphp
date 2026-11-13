<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use Closure;
use MyPHP\Plugins\Scene\Math\Vec3;

/**
 * 3D Collision volume (Sphere or Axis-Aligned Bounding Box) attached to an Entity.
 * Supports solid collision resolution (push-out / sliding) and trigger callbacks.
 */
final class Collider3D
{
    public const SPHERE = 'sphere';
    public const BOX    = 'box';

    public string $shape;
    public float $radius;
    public Vec3 $halfExtents;
    /** Static colliders do not move when pushed; dynamic colliders are pushed out */
    public bool $isStatic = false;
    /** Trigger colliders fire $onCollide without physical push-out */
    public bool $isTrigger = false;
    /** @var ?Closure(Entity $self, Entity $other, Vec3 $normal, float $penetration): void */
    public ?Closure $onCollide = null;

    private function __construct(string $shape, float $radius, Vec3 $halfExtents)
    {
        $this->shape = $shape;
        $this->radius = $radius;
        $this->halfExtents = $halfExtents;
    }

    public static function sphere(
        float $radius = 0.5,
        bool $isStatic = false,
        bool $isTrigger = false,
        ?Closure $onCollide = null
    ): self {
        $c = new self(self::SPHERE, max(0.001, $radius), new Vec3($radius, $radius, $radius));
        $c->isStatic = $isStatic;
        $c->isTrigger = $isTrigger;
        $c->onCollide = $onCollide;
        return $c;
    }

    public static function box(
        Vec3 $halfExtents,
        bool $isStatic = false,
        bool $isTrigger = false,
        ?Closure $onCollide = null
    ): self {
        $r = max($halfExtents->x, $halfExtents->y, $halfExtents->z);
        $c = new self(self::BOX, $r, $halfExtents);
        $c->isStatic = $isStatic;
        $c->isTrigger = $isTrigger;
        $c->onCollide = $onCollide;
        return $c;
    }

    /**
     * Tests intersection between two entities with colliders.
     * Returns [normal (pointing from B to A), penetrationDepth] or null if disjoint.
     *
     * @return ?array{0: Vec3, 1: float}
     */
    public static function intersect(Entity $a, Entity $b): ?array
    {
        $ca = $a->collider;
        $cb = $b->collider;
        if ($ca === null || $cb === null) {
            return null;
        }

        $pa = $a->worldPosition();
        $pb = $b->worldPosition();

        if ($ca->shape === self::SPHERE && $cb->shape === self::SPHERE) {
            $ra = $ca->radius * max($a->scale->x, $a->scale->y, $a->scale->z);
            $rb = $cb->radius * max($b->scale->x, $b->scale->y, $b->scale->z);
            $diff = $pa->sub($pb);
            $dist = $diff->length();
            $sumR = $ra + $rb;
            if ($dist >= $sumR) {
                return null;
            }
            $normal = $dist > 1e-5 ? $diff->scale(1.0 / $dist) : new Vec3(0.0, 1.0, 0.0);
            return [$normal, $sumR - $dist];
        }

        if ($ca->shape === self::BOX && $cb->shape === self::BOX) {
            $ha = new Vec3($ca->halfExtents->x * $a->scale->x, $ca->halfExtents->y * $a->scale->y, $ca->halfExtents->z * $a->scale->z);
            $hb = new Vec3($cb->halfExtents->x * $b->scale->x, $cb->halfExtents->y * $b->scale->y, $cb->halfExtents->z * $b->scale->z);

            $dx = $pa->x - $pb->x;
            $px = ($ha->x + $hb->x) - abs($dx);
            if ($px <= 0.0) { return null; }

            $dy = $pa->y - $pb->y;
            $py = ($ha->y + $hb->y) - abs($dy);
            if ($py <= 0.0) { return null; }

            $dz = $pa->z - $pb->z;
            $pz = ($ha->z + $hb->z) - abs($dz);
            if ($pz <= 0.0) { return null; }

            if ($px <= $py && $px <= $pz) {
                return [new Vec3($dx < 0.0 ? -1.0 : 1.0, 0.0, 0.0), $px];
            }
            if ($py <= $pz) {
                return [new Vec3(0.0, $dy < 0.0 ? -1.0 : 1.0, 0.0), $py];
            }
            return [new Vec3(0.0, 0.0, $dz < 0.0 ? -1.0 : 1.0), $pz];
        }

        // Sphere vs Box
        if ($ca->shape === self::SPHERE && $cb->shape === self::BOX) {
            return self::sphereVsBox($pa, $ca->radius * max($a->scale->x, $a->scale->y, $a->scale->z), $pb, new Vec3(
                $cb->halfExtents->x * $b->scale->x,
                $cb->halfExtents->y * $b->scale->y,
                $cb->halfExtents->z * $b->scale->z
            ));
        }

        $res = self::sphereVsBox($pb, $cb->radius * max($b->scale->x, $b->scale->y, $b->scale->z), $pa, new Vec3(
            $ca->halfExtents->x * $a->scale->x,
            $ca->halfExtents->y * $a->scale->y,
            $ca->halfExtents->z * $a->scale->z
        ));
        if ($res === null) {
            return null;
        }
        [$n, $pen] = $res;
        return [$n->scale(-1.0), $pen];
    }

    /**
     * @return ?array{0: Vec3, 1: float}
     */
    private static function sphereVsBox(Vec3 $sphereCenter, float $radius, Vec3 $boxCenter, Vec3 $boxHalf): ?array
    {
        $cx = max($boxCenter->x - $boxHalf->x, min($sphereCenter->x, $boxCenter->x + $boxHalf->x));
        $cy = max($boxCenter->y - $boxHalf->y, min($sphereCenter->y, $boxCenter->y + $boxHalf->y));
        $cz = max($boxCenter->z - $boxHalf->z, min($sphereCenter->z, $boxCenter->z + $boxHalf->z));

        $dx = $sphereCenter->x - $cx;
        $dy = $sphereCenter->y - $cy;
        $dz = $sphereCenter->z - $cz;
        $distSq = $dx * $dx + $dy * $dy + $dz * $dz;

        if ($distSq >= $radius * $radius) {
            return null;
        }

        $dist = sqrt($distSq);
        if ($dist > 1e-5) {
            return [new Vec3($dx / $dist, $dy / $dist, $dz / $dist), $radius - $dist];
        }

        // Sphere center is inside the box; push out along closest face
        $ox = $boxHalf->x - abs($sphereCenter->x - $boxCenter->x);
        $oy = $boxHalf->y - abs($sphereCenter->y - $boxCenter->y);
        $oz = $boxHalf->z - abs($sphereCenter->z - $boxCenter->z);
        if ($ox <= $oy && $ox <= $oz) {
            $sx = $sphereCenter->x < $boxCenter->x ? -1.0 : 1.0;
            return [new Vec3($sx, 0.0, 0.0), $ox + $radius];
        }
        if ($oy <= $oz) {
            $sy = $sphereCenter->y < $boxCenter->y ? -1.0 : 1.0;
            return [new Vec3(0.0, $sy, 0.0), $oy + $radius];
        }
        $sz = $sphereCenter->z < $boxCenter->z ? -1.0 : 1.0;
        return [new Vec3(0.0, 0.0, $sz), $oz + $radius];
    }
}
