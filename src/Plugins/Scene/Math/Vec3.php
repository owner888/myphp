<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Math;

/**
 * 3D vector for scene coordinates, normals, and directions.
 */
final class Vec3
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public static function up(): self
    {
        return new self(0.0, 1.0, 0.0);
    }

    public static function forward(): self
    {
        return new self(0.0, 0.0, -1.0);
    }

    public function add(self $v): self
    {
        return new self($this->x + $v->x, $this->y + $v->y, $this->z + $v->z);
    }

    public function sub(self $v): self
    {
        return new self($this->x - $v->x, $this->y - $v->y, $this->z - $v->z);
    }

    public function scale(float $s): self
    {
        return new self($this->x * $s, $this->y * $s, $this->z * $s);
    }

    public function dot(self $v): float
    {
        return $this->x * $v->x + $this->y * $v->y + $this->z * $v->z;
    }

    public function cross(self $v): self
    {
        return new self(
            $this->y * $v->z - $this->z * $v->y,
            $this->z * $v->x - $this->x * $v->z,
            $this->x * $v->y - $this->y * $v->x
        );
    }

    public function length(): float
    {
        return sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
    }

    public function normalize(): self
    {
        $len = $this->length();
        if ($len > 1e-6) {
            $inv = 1.0 / $len;
            return new self($this->x * $inv, $this->y * $inv, $this->z * $inv);
        }
        return new self(0.0, 0.0, 0.0);
    }

    /** @return array{0: float, 1: float, 2: float} */
    public function toArray(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}
