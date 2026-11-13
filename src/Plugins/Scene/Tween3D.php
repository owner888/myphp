<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use Closure;
use MyPHP\Plugins\Scene\Math\Vec3;

/**
 * 3D Transform Tween / Keyframe animation for Entity position, rotation, and scale.
 * Driven automatically when registered via $scene->addTween($tween).
 */
final class Tween3D
{
    public const LINEAR = 'linear';
    public const EASE_OUT_CUBIC = 'easeOutCubic';
    public const EASE_IN_OUT_QUAD = 'easeInOutQuad';
    public const BOUNCE_OUT = 'bounceOut';

    public Entity $entity;
    public float $duration;
    public float $elapsed = 0.0;
    public string $easing;
    public bool $yoyo = false;
    public bool $repeat = false;

    private ?Vec3 $fromPos = null;
    private ?Vec3 $toPos = null;
    private ?Vec3 $fromRot = null;
    private ?Vec3 $toRot = null;
    private ?Vec3 $fromScale = null;
    private ?Vec3 $toScale = null;
    private ?Closure $onComplete = null;
    private bool $reversed = false;

    public function __construct(Entity $entity, float $duration = 0.5, string $easing = self::EASE_OUT_CUBIC)
    {
        $this->entity = $entity;
        $this->duration = max(0.001, $duration);
        $this->easing = $easing;
    }

    public function toPosition(Vec3 $target, ?Vec3 $from = null): self
    {
        $this->fromPos = $from ?? new Vec3($this->entity->position->x, $this->entity->position->y, $this->entity->position->z);
        $this->toPos = $target;
        return $this;
    }

    public function toRotation(Vec3 $target, ?Vec3 $from = null): self
    {
        $this->fromRot = $from ?? new Vec3($this->entity->rotation->x, $this->entity->rotation->y, $this->entity->rotation->z);
        $this->toRot = $target;
        return $this;
    }

    public function toScale(Vec3 $target, ?Vec3 $from = null): self
    {
        $this->fromScale = $from ?? new Vec3($this->entity->scale->x, $this->entity->scale->y, $this->entity->scale->z);
        $this->toScale = $target;
        return $this;
    }

    public function yoyo(bool $yoyo = true, bool $repeat = false): self
    {
        $this->yoyo = $yoyo;
        $this->repeat = $repeat;
        return $this;
    }

    public function onComplete(Closure $callback): self
    {
        $this->onComplete = $callback;
        return $this;
    }

    /**
     * Advances the tween by $dt seconds. Returns true when finished.
     */
    public function step(float $dt): bool
    {
        $this->elapsed += $dt;
        $rawT = min(1.0, max(0.0, $this->elapsed / $this->duration));
        $t = $this->reversed ? (1.0 - $rawT) : $rawT;
        $k = self::ease($t, $this->easing);

        if ($this->fromPos !== null && $this->toPos !== null) {
            $this->entity->position = self::lerpVec3($this->fromPos, $this->toPos, $k);
        }
        if ($this->fromRot !== null && $this->toRot !== null) {
            $this->entity->rotation = self::lerpVec3($this->fromRot, $this->toRot, $k);
        }
        if ($this->fromScale !== null && $this->toScale !== null) {
            $this->entity->scale = self::lerpVec3($this->fromScale, $this->toScale, $k);
        }

        if ($rawT >= 1.0) {
            if ($this->yoyo && !$this->reversed) {
                $this->reversed = true;
                $this->elapsed = 0.0;
                return false;
            }
            if ($this->repeat) {
                $this->reversed = false;
                $this->elapsed = 0.0;
                return false;
            }
            if ($this->onComplete !== null) {
                ($this->onComplete)($this->entity);
            }
            return true;
        }

        return false;
    }

    private static function lerpVec3(Vec3 $a, Vec3 $b, float $t): Vec3
    {
        return new Vec3(
            $a->x + ($b->x - $a->x) * $t,
            $a->y + ($b->y - $a->y) * $t,
            $a->z + ($b->z - $a->z) * $t
        );
    }

    public static function ease(float $t, string $mode): float
    {
        return match ($mode) {
            self::EASE_OUT_CUBIC => 1.0 - pow(1.0 - $t, 3),
            self::EASE_IN_OUT_QUAD => $t < 0.5 ? 2.0 * $t * $t : 1.0 - pow(-2.0 * $t + 2.0, 2) / 2.0,
            self::BOUNCE_OUT => self::bounceOut($t),
            default => $t,
        };
    }

    private static function bounceOut(float $x): float
    {
        $n1 = 7.5625;
        $d1 = 2.75;
        if ($x < 1.0 / $d1) {
            return $n1 * $x * $x;
        }
        if ($x < 2.0 / $d1) {
            $x -= 1.5 / $d1;
            return $n1 * $x * $x + 0.75;
        }
        if ($x < 2.5 / $d1) {
            $x -= 2.25 / $d1;
            return $n1 * $x * $x + 0.9375;
        }
        $x -= 2.625 / $d1;
        return $n1 * $x * $x + 0.984375;
    }
}
