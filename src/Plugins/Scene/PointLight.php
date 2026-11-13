<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\Vec3;

/**
 * 3D Point Light with position, color, intensity, and radial attenuation radius.
 */
final class PointLight
{
    public Vec3 $position;
    /** @var array{0: float, 1: float, 2: float} RGB color */
    public array $color;
    public float $intensity;
    public float $radius;

    /**
     * @param ?Vec3 $position
     * @param array{0: float, 1: float, 2: float} $color
     */
    public function __construct(
        ?Vec3 $position = null,
        array $color = [1.0, 0.85, 0.5],
        float $intensity = 1.5,
        float $radius = 10.0
    ) {
        $this->position = $position ?? new Vec3(0.0, 2.0, 0.0);
        $this->color = $color;
        $this->intensity = $intensity;
        $this->radius = max(0.1, $radius);
    }
}
