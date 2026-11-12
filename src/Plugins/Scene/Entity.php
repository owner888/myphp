<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};

/**
 * 3D Scene Entity with Mesh, Material, and Transform.
 * Caches model matrix to avoid per-frame CPU matrix multiplications unless dirty.
 */
final class Entity
{
    public Vec3 $position;
    public Vec3 $rotation; // Euler angles in radians (X, Y, Z)
    public Vec3 $scale;
    public Material $material;
    public Mesh $mesh;

    private ?Mat4 $cachedModel = null;
    private float $lastPx = 0.0, $lastPy = 0.0, $lastPz = 0.0;
    private float $lastRx = 0.0, $lastRy = 0.0, $lastRz = 0.0;
    private float $lastSx = 1.0, $lastSy = 1.0, $lastSz = 1.0;

    public function __construct(
        Mesh $mesh,
        ?Material $material = null,
        ?Vec3 $position = null,
        ?Vec3 $rotation = null,
        ?Vec3 $scale = null
    ) {
        $this->mesh = $mesh;
        $this->material = $material ?? new Material();
        $this->position = $position ?? Vec3::zero();
        $this->rotation = $rotation ?? Vec3::zero();
        $this->scale = $scale ?? new Vec3(1.0, 1.0, 1.0);
    }

    public function modelMatrix(): Mat4
    {
        $p = $this->position;
        $r = $this->rotation;
        $s = $this->scale;

        if (
            $this->cachedModel !== null &&
            $p->x === $this->lastPx && $p->y === $this->lastPy && $p->z === $this->lastPz &&
            $r->x === $this->lastRx && $r->y === $this->lastRy && $r->z === $this->lastRz &&
            $s->x === $this->lastSx && $s->y === $this->lastSy && $s->z === $this->lastSz
        ) {
            return $this->cachedModel;
        }

        // T * Rz * Ry * Rx * S
        $t = Mat4::translation($p->x, $p->y, $p->z);
        $rx = Mat4::rotationX($r->x);
        $ry = Mat4::rotationY($r->y);
        $rz = Mat4::rotationZ($r->z);
        $sc = Mat4::scaling($s->x, $s->y, $s->z);

        $this->cachedModel = $t->multiply($rz)->multiply($ry)->multiply($rx)->multiply($sc);

        $this->lastPx = $p->x; $this->lastPy = $p->y; $this->lastPz = $p->z;
        $this->lastRx = $r->x; $this->lastRy = $r->y; $this->lastRz = $r->z;
        $this->lastSx = $s->x; $this->lastSy = $s->y; $this->lastSz = $s->z;

        return $this->cachedModel;
    }
}
