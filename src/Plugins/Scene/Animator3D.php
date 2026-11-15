<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Plugins\Scene\Math\Vec3;

/**
 * Hierarchical 3D Animator for procedural creature/character motions
 * (`idle` breathing, `walk` bobbing, `lunge` attack strike, `hurt` recoil, `spin`)
 * and multi-part limb oscillations (wings, head, tail, legs) — inspired by Unity's Animator.
 *
 * Automatically stepped by `Scene::step($dt)` when registered via `$scene->addAnimator($anim)`.
 *
 *     $anim = $scene->addAnimator(new Animator3D($beastEntity));
 *     $anim->addLimb($headEntity, axis: 'x', amplitude: 0.14, frequency: 2.5);
 *     $anim->play(Animator3D::IDLE);
 *     // When attacking in battle:
 *     $anim->play(Animator3D::LUNGE, direction: new Vec3(1.0, 0.0, -1.0));
 */
final class Animator3D
{
    public const IDLE  = 'idle';
    public const WALK  = 'walk';
    public const LUNGE = 'lunge';
    public const HURT  = 'hurt';
    public const SPIN  = 'spin';

    public Vec3 $basePosition;
    public Vec3 $baseRotation;
    public Vec3 $baseScale;

    public string $state = self::IDLE;
    public float $time = 0.0;
    public float $stateTimer = 0.0;
    public float $stateDuration = 0.0;
    public Vec3 $actionDir;

    /**
     * @var list<array{entity: Entity, basePos: Vec3, baseRot: Vec3, axis: string, amplitude: float, frequency: float, phase: float}>
     */
    private array $limbs = [];

    public function __construct(public readonly Entity $root)
    {
        $this->basePosition = clone $root->position;
        $this->baseRotation = clone $root->rotation;
        $this->baseScale = clone $root->scale;
        $this->actionDir = new Vec3(0.0, 0.0, 1.0);
    }

    /**
     * Updates the stored resting transform of the root entity (call after moving the entity on a map/arena).
     */
    public function syncBaseTransform(): self
    {
        $this->basePosition = clone $this->root->position;
        $this->baseRotation = clone $this->root->rotation;
        $this->baseScale = clone $this->root->scale;
        return $this;
    }

    /**
     * Registers a child limb/part (such as a head, wing, tail, or weapon) to oscillate
     * around $axis ('x', 'y', or 'z') during `idle` and `walk` states.
     */
    public function addLimb(
        Entity $limb,
        string $axis = 'x',
        float $amplitude = 0.25,
        float $frequency = 3.0,
        float $phase = 0.0
    ): self {
        $this->limbs[] = [
            'entity' => $limb,
            'basePos' => clone $limb->position,
            'baseRot' => clone $limb->rotation,
            'axis' => $axis,
            'amplitude' => $amplitude,
            'frequency' => $frequency,
            'phase' => $phase,
        ];
        return $this;
    }

    /**
     * Plays a looping state (`IDLE`, `WALK`, `SPIN`) or a one-shot combat clip (`LUNGE`, `HURT`)
     * that automatically returns to `IDLE` when finished.
     */
    public function play(string $state, float $duration = 0.38, ?Vec3 $direction = null): self
    {
        $this->state = $state;
        $this->stateTimer = 0.0;
        $this->stateDuration = max(0.05, $duration);
        if ($direction !== null && $direction->length() > 1e-4) {
            $this->actionDir = $direction->normalize();
        }
        return $this;
    }

    /**
     * Advances the animation clock by $dt seconds and updates root + child limb transforms.
     */
    public function step(float $dt): void
    {
        $this->time += $dt;
        $this->stateTimer += $dt;

        $pos = clone $this->basePosition;
        $rot = clone $this->baseRotation;
        $scl = clone $this->baseScale;
        $limbSpeedMult = 1.0;

        switch ($this->state) {
            case self::WALK:
                $limbSpeedMult = 2.2;
                $bob = abs(sin($this->time * 9.0)) * 0.12;
                $sway = sin($this->time * 4.5) * 0.08;
                $pos->y += $bob;
                $rot->z += $sway;
                break;

            case self::LUNGE:
                $limbSpeedMult = 3.0;
                $t = min(1.0, $this->stateTimer / $this->stateDuration);
                if ($t < 0.2) {
                    $k = -($t / 0.2) * 0.25;
                } elseif ($t < 0.55) {
                    $k = -0.25 + (($t - 0.2) / 0.35) * 1.35;
                } else {
                    $k = 1.10 * (1.0 - ($t - 0.55) / 0.45);
                }
                $pos = $pos->add($this->actionDir->scale($k * 0.85));
                $pos->y += max(0.0, sin($t * M_PI)) * 0.28;
                if ($t >= 1.0) {
                    $this->state = self::IDLE;
                }
                break;

            case self::HURT:
                $t = min(1.0, $this->stateTimer / $this->stateDuration);
                $decay = 1.0 - $t;
                $shake = sin($t * M_PI * 10.0) * 0.22 * $decay;
                $pos = $pos->sub($this->actionDir->scale(sin($t * M_PI) * 0.35));
                $pos->x += $shake;
                $scl->y *= 1.0 - sin($t * M_PI) * 0.22;
                $scl->x *= 1.0 + sin($t * M_PI) * 0.15;
                if ($t >= 1.0) {
                    $this->state = self::IDLE;
                }
                break;

            case self::SPIN:
                $rot->y += $this->time * 2.5;
                break;

            case self::IDLE:
            default:
                $breath = sin($this->time * 2.8);
                $scl->y *= 1.0 + $breath * 0.035;
                $scl->x *= 1.0 - $breath * 0.015;
                $scl->z *= 1.0 - $breath * 0.015;
                break;
        }

        $this->root->position = $pos;
        $this->root->rotation = $rot;
        $this->root->scale = $scl;

        foreach ($this->limbs as $limb) {
            $e = $limb['entity'];
            $r = clone $limb['baseRot'];
            $angle = sin($this->time * $limb['frequency'] * $limbSpeedMult + $limb['phase']) * $limb['amplitude'];
            if ($limb['axis'] === 'x') {
                $r->x += $angle;
            } elseif ($limb['axis'] === 'y') {
                $r->y += $angle;
            } else {
                $r->z += $angle;
            }
            $e->rotation = $r;
        }
    }
}
