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
     * @param array{0: float, 1: float, 2: float} $rimColor RGB color for Fresnel rim lighting
     */
    public function __construct(
        public array $color = [1.0, 1.0, 1.0, 1.0],
        public float $ambient = 0.25,
        public float $diffuse = 0.85,
        public float $specular = 0.5,
        public float $shininess = 32.0,
        public ?Image $texture = null,
        public bool $wireframe = false,
        public float $rimStrength = 0.0,
        public array $rimColor = [0.35, 0.9, 1.0],
        public float $metallic = 0.0,
        public float $roughness = 0.5
    ) {}

    public static function pbr(
        array $color = [1.0, 1.0, 1.0, 1.0],
        float $metallic = 0.5,
        float $roughness = 0.3,
        ?Image $texture = null,
        float $rimStrength = 0.0
    ): self {
        return new self(
            color: $color,
            texture: $texture,
            rimStrength: $rimStrength,
            metallic: max(0.0, min(1.0, $metallic)),
            roughness: max(0.04, min(1.0, $roughness))
        );
    }

    /**
     * Packs shininess or negative encoded PBR (metallic, roughness) for the shader's cameraPos.w slot.
     */
    public function packedSpecularParam(): float
    {
        if ($this->metallic > 0.001 || abs($this->roughness - 0.5) > 0.001) {
            $m = (float)round(max(0.0, min(1.0, $this->metallic)) * 100.0);
            $r = max(0.04, min(0.99, $this->roughness));
            return -(1.0 + $m + $r);
        }
        return max(1.0, $this->shininess);
    }

    public static function basic(array $color = [1.0, 1.0, 1.0, 1.0], ?Image $texture = null): self
    {
        return new self($color, texture: $texture);
    }

    public static function textured(Image $texture, array $tint = [1.0, 1.0, 1.0, 1.0], float $shininess = 32.0): self
    {
        return new self($tint, shininess: $shininess, texture: $texture);
    }
}
