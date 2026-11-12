<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};

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
        return Mat4::perspective($this->fovY, $aspect, $this->near, $this->far);
    }
}
