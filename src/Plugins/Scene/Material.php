<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

/**
 * 3D Material properties for shading.
 */
final class Material
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public function __construct(
        public array $color = [1.0, 1.0, 1.0, 1.0],
        public float $ambient = 0.25,
        public float $diffuse = 0.85,
        public float $specular = 0.5,
        public float $shininess = 32.0
    ) {}

    public static function basic(array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        return new self($color);
    }
}
