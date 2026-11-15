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

    /** Damped 3D camera shake state */
    public float $shakeIntensity = 0.0;
    public float $shakeDuration = 0.0;
    public float $shakeElapsed = 0.0;
    public Vec3 $shakeOffset;

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
        $this->shakeOffset = Vec3::zero();
    }

    /**
     * Triggers a damped 3D camera shake of $intensity world units over $duration seconds.
     * Automatically stepped by Scene::step($dt).
     */
    public function shake(float $intensity = 0.18, float $duration = 0.25): self
    {
        $this->shakeIntensity = max(0.0, $intensity);
        $this->shakeDuration = max(0.001, $duration);
        $this->shakeElapsed = 0.0;
        return $this;
    }

    /**
     * Advances active camera shake by $dt seconds.
     */
    public function step(float $dt): void
    {
        if ($this->shakeIntensity <= 0.0 || $this->shakeElapsed >= $this->shakeDuration) {
            $this->shakeIntensity = 0.0;
            $this->shakeOffset = Vec3::zero();
            return;
        }
        $this->shakeElapsed += $dt;
        if ($this->shakeElapsed >= $this->shakeDuration) {
            $this->shakeIntensity = 0.0;
            $this->shakeOffset = Vec3::zero();
            return;
        }
        $decay = 1.0 - ($this->shakeElapsed / $this->shakeDuration);
        $amp = $this->shakeIntensity * $decay * $decay;
        $phase = $this->shakeElapsed * 65.0;
        $this->shakeOffset = new Vec3(
            sin($phase * 1.13) * $amp,
            cos($phase * 1.71) * $amp,
            sin($phase * 2.37) * $amp * 0.5
        );
    }

    public function viewMatrix(): Mat4
    {
        if ($this->shakeIntensity > 0.0) {
            return Mat4::lookAt(
                $this->position->add($this->shakeOffset),
                $this->target->add($this->shakeOffset),
                $this->up
            );
        }
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

    /**
     * Projects a 3D world-space coordinate $worldPos into 2D viewport coordinates [x, y]
     * within ($width, $height), or returns null if the point is behind the camera's near plane.
     *
     * @return ?array{0: float, 1: float}
     */
    public function worldToScreen(Vec3 $worldPos, float $width, float $height): ?array
    {
        $aspect = $this->aspect ?? ($width / max(1.0, $height));
        $vp = $this->projectionMatrix($aspect)->multiply($this->viewMatrix());
        $m = $vp->m;

        $x = $worldPos->x;
        $y = $worldPos->y;
        $z = $worldPos->z;

        $clipX = $m[0] * $x + $m[4] * $y + $m[8]  * $z + $m[12];
        $clipY = $m[1] * $x + $m[5] * $y + $m[9]  * $z + $m[13];
        $clipZ = $m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14];
        $clipW = $m[3] * $x + $m[7] * $y + $m[11] * $z + $m[15];

        if ($clipW <= 0.001 || $clipZ < 0.0) {
            return null;
        }

        $ndcX = $clipX / $clipW;
        $ndcY = $clipY / $clipW;

        $sx = ($ndcX + 1.0) * 0.5 * $width;
        $sy = (1.0 - $ndcY) * 0.5 * $height;

        return [$sx, $sy];
    }
}
