<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};

/**
 * High-performance 3D GPU-instanced Particle Emitter.
 * Renders hundreds or thousands of 3D particles in a single hardware-instanced draw call.
 */
final class ParticleEmitter3D
{
    public Entity $entity;
    public int $maxParticles;

    /** @var list<float> */
    private array $px = [];
    /** @var list<float> */
    private array $py = [];
    /** @var list<float> */
    private array $pz = [];
    /** @var list<float> */
    private array $vx = [];
    /** @var list<float> */
    private array $vy = [];
    /** @var list<float> */
    private array $vz = [];
    /** @var list<float> */
    private array $age = [];
    /** @var list<float> */
    private array $life = [];
    /** @var list<float> */
    private array $size = [];
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> */
    private array $color = [];

    public float $gravity = -9.8;
    public float $drag = 0.98;
    private int $activeCount = 0;

    public function __construct(?Mesh $particleMesh = null, int $maxParticles = 256)
    {
        $this->maxParticles = max(1, $maxParticles);
        $mesh = $particleMesh ?? Mesh::cube(1.0);
        $this->entity = new Entity($mesh, new Material(color: [1.0, 1.0, 1.0, 1.0], shininess: 64.0), scale: new Vec3(0.0, 0.0, 0.0), name: 'particle_emitter');
        $this->entity->pickable = false;
        $this->entity->castShadow = false;
    }

    public function activeCount(): int
    {
        return $this->activeCount;
    }

    /**
     * Emits a burst of $count 3D particles around $origin.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public function burst(
        Vec3 $origin,
        int $count = 32,
        array $color = [1.0, 0.85, 0.3, 1.0],
        float $speedMin = 2.0,
        float $speedMax = 6.5,
        float $lifeMin = 0.35,
        float $lifeMax = 0.85,
        float $size = 0.14
    ): void {
        for ($i = 0; $i < $count; $i++) {
            if ($this->activeCount >= $this->maxParticles) {
                break;
            }
            $idx = $this->activeCount++;

            // Random spherical direction biased slightly upward
            $theta = (mt_rand(0, 10000) / 10000.0) * 2.0 * M_PI;
            $phi = (mt_rand(1500, 9500) / 10000.0) * M_PI * 0.5;
            $spd = $speedMin + (mt_rand(0, 10000) / 10000.0) * ($speedMax - $speedMin);

            $this->px[$idx] = $origin->x;
            $this->py[$idx] = $origin->y;
            $this->pz[$idx] = $origin->z;

            $this->vx[$idx] = cos($phi) * cos($theta) * $spd;
            $this->vy[$idx] = sin($phi) * $spd + 1.2;
            $this->vz[$idx] = cos($phi) * sin($theta) * $spd;

            $this->age[$idx] = 0.0;
            $this->life[$idx] = $lifeMin + (mt_rand(0, 10000) / 10000.0) * ($lifeMax - $lifeMin);
            $this->size[$idx] = $size * (0.7 + (mt_rand(0, 600) / 1000.0));
            $this->color[$idx] = $color;
        }
    }

    /**
     * Steps particle physics and packs live instances into the GPU instance buffer.
     */
    public function step(float $dt): void
    {
        if ($this->activeCount === 0) {
            return;
        }

        $chunks = [];
        $writeIdx = 0;
        $g = $this->gravity * $dt;
        $d = pow($this->drag, $dt * 60.0);

        for ($i = 0; $i < $this->activeCount; $i++) {
            $age = $this->age[$i] + $dt;
            $life = $this->life[$i];
            if ($age >= $life) {
                continue;
            }

            $vx = $this->vx[$i] * $d;
            $vy = ($this->vy[$i] + $g) * $d;
            $vz = $this->vz[$i] * $d;

            $px = $this->px[$i] + $vx * $dt;
            $py = $this->py[$i] + $vy * $dt;
            $pz = $this->pz[$i] + $vz * $dt;

            // Floor bounce
            if ($py < 0.05) {
                $py = 0.05;
                $vy = -$vy * 0.45;
            }

            $this->px[$writeIdx] = $px;
            $this->py[$writeIdx] = $py;
            $this->pz[$writeIdx] = $pz;
            $this->vx[$writeIdx] = $vx;
            $this->vy[$writeIdx] = $vy;
            $this->vz[$writeIdx] = $vz;
            $this->age[$writeIdx] = $age;
            $this->life[$writeIdx] = $life;
            $this->size[$writeIdx] = $this->size[$i];
            $col = $this->color[$i];
            $this->color[$writeIdx] = $col;

            $t = 1.0 - ($age / $life); // shrinks from 1 -> 0 over lifetime
            $s = max(0.01, $this->size[$i] * $t);

            // Fast inline scale + translation column-major Mat4 + RGBA color (80 bytes)
            $chunks[] = pack(
                'f20',
                $s,  0.0, 0.0, 0.0,
                0.0, $s,  0.0, 0.0,
                0.0, 0.0, $s,  0.0,
                $px, $py, $pz, 1.0,
                $col[0], $col[1], $col[2], $col[3] * $t
            );

            $writeIdx++;
        }

        $this->activeCount = $writeIdx;
        if ($writeIdx === 0) {
            $this->entity->setInstanceData('', 0);
        } else {
            $this->entity->setInstanceData(implode('', $chunks), $writeIdx);
        }
    }
}
