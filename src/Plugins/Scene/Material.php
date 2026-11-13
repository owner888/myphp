<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\UI\Image;

/**
 * 3D Material properties for shading and texture mapping.
 */
final class Material
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     * @param ?Image $texture Optional diffuse texture map (uses pixelated flag for nearest vs linear sampling)
     */
    public function __construct(
        public array $color = [1.0, 1.0, 1.0, 1.0],
        public float $ambient = 0.25,
        public float $diffuse = 0.85,
        public float $specular = 0.5,
        public float $shininess = 32.0,
        public ?Image $texture = null
    ) {}

    public static function basic(array $color = [1.0, 1.0, 1.0, 1.0], ?Image $texture = null): self
    {
        return new self($color, texture: $texture);
    }

    public static function textured(Image $texture, array $tint = [1.0, 1.0, 1.0, 1.0], float $shininess = 32.0): self
    {
        return new self($tint, shininess: $shininess, texture: $texture);
    }
}
