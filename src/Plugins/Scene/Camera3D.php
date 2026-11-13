<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\{Mat4, Ray, Vec3};

/**
 * 3D Perspective Camera.
 */
final class Camera3D
{
    public Vec3 $position;
    public Vec3 $target;
    public Vec3 $up;
    public float $fovY; // in radians
    public float $near;
    public float $far;
    public ?float $aspect = null; // optional explicit aspect ratio override

    public function __construct(
        ?Vec3 $position = null,
        ?Vec3 $target = null,
        ?Vec3 $up = null,
        float $fovYDegrees = 60.0,
        float $near = 0.1,
        float $far = 1000.0
    ) {
        $this->position = $position ?? new Vec3(0.0, 0.0, 3.0);
        $this->target = $target ?? Vec3::zero();
        $this->up = $up ?? Vec3::up();
        $this->fovY = deg2rad($fovYDegrees);
        $this->near = $near;
        $this->far = $far;
    }

    public function viewMatrix(): Mat4
    {
        return Mat4::lookAt($this->position, $this->target, $this->up);
    }

    public function projectionMatrix(float $aspect): Mat4
    {
        return Mat4::perspective($this->fovY, $this->aspect ?? $aspect, $this->near, $this->far);
    }

    /**
     * Unprojects 2D viewport coordinates ($x, $y) within ($width, $height) into a 3D world-space Ray.
     * ($x, $y) has origin (0, 0) at top-left of the viewport.
     */
    public function screenToRay(float $x, float $y, float $width, float $height): Ray
    {
        $aspect = $this->aspect ?? ($width / max(1.0, $height));
        // Normalized Device Coordinates: x in [-1, 1], y in [-1, 1] (top is +1)
        $ndcX = ($x / max(1.0, $width)) * 2.0 - 1.0;
        $ndcY = 1.0 - ($y / max(1.0, $height)) * 2.0;

        $tanHalfFov = tan($this->fovY * 0.5);

        // Camera orthonormal basis (right-handed)
        $forward = $this->target->sub($this->position)->normalize();
        $right   = $forward->cross($this->up)->normalize();
        $camUp   = $right->cross($forward);

        $dir = $forward
            ->add($right->scale($ndcX * $aspect * $tanHalfFov))
            ->add($camUp->scale($ndcY * $tanHalfFov));

        return new Ray($this->position, $dir);
    }
}
