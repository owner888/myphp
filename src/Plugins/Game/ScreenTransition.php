<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use Closure;
use MyPHP\UI\Painter;

/**
 * Classic handheld & arcade screen transitions (Fade, Battle Flash + Horizontal Blinds,
 * Curtain Wipe) drawn over the game viewport via Painter (inspired by Flame's TransitionEffect).
 *
 *     $this->transition->start(ScreenTransition::BATTLE_BLINDS, duration: 0.55, onMidpoint: fn() => $this->enterBattle());
 *     // In update():
 *     $this->transition->step(1.0 / 60.0);
 *     // At the end of draw():
 *     $this->transition->draw($p, $r);
 */
final class ScreenTransition
{
    /** Smooth full-screen alpha fade out then fade in. */
    public const FADE = 'fade';
    /** Classic handheld RPG wild-encounter flash followed by alternating horizontal blinds. */
    public const BATTLE_BLINDS = 'battle_blinds';
    /** Top and bottom theater curtains closing to the center and reopening. */
    public const SHUTTER = 'shutter';

    public string $kind = self::FADE;
    public float $duration = 0.4;
    public float $elapsed = 0.0;
    public bool $active = false;
    private bool $midpointFired = false;
    private ?Closure $onMidpoint = null;
    private ?Closure $onComplete = null;
    public int $color;

    public function __construct(int $color = 0x000000)
    {
        $this->color = $color;
    }

    /**
     * Starts a screen transition of $kind over $duration seconds.
     * $onMidpoint runs exactly once at 50% progress (when the screen is fully covered),
     * ideal for swapping maps or entering/leaving a battle scene.
     */
    public function start(
        string $kind = self::FADE,
        float $duration = 0.4,
        ?Closure $onMidpoint = null,
        ?Closure $onComplete = null,
        ?int $color = null,
    ): self {
        $this->kind = $kind;
        $this->duration = max(0.01, $duration);
        $this->elapsed = 0.0;
        $this->active = true;
        $this->midpointFired = false;
        $this->onMidpoint = $onMidpoint;
        $this->onComplete = $onComplete;
        if ($color !== null) {
            $this->color = $color;
        }
        return $this;
    }

    /**
     * Advances the transition clock by $dt seconds.
     */
    public function step(float $dt): void
    {
        if (!$this->active) {
            return;
        }
        $this->elapsed += $dt;
        $progress = min(1.0, $this->elapsed / $this->duration);

        if (!$this->midpointFired && $progress >= 0.5) {
            $this->midpointFired = true;
            if ($this->onMidpoint !== null) {
                ($this->onMidpoint)();
            }
        }

        if ($progress >= 1.0) {
            $this->active = false;
            if ($this->onComplete !== null) {
                ($this->onComplete)();
            }
        }
    }

    /**
     * Normalized progress in [0.0, 1.0].
     */
    public function progress(): float
    {
        return $this->active ? min(1.0, max(0.0, $this->elapsed / $this->duration)) : 0.0;
    }

    /**
     * Draws the active transition overlay over viewport $r [x, y, w, h].
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r): void
    {
        if (!$this->active) {
            return;
        }
        [$ox, $oy, $w, $h] = $r;
        $t = $this->progress();
        // Envelope: 0 -> 1 at midpoint (0.5), then 1 -> 0 at end (1.0)
        $cover = $t <= 0.5 ? ($t * 2.0) : ((1.0 - $t) * 2.0);

        switch ($this->kind) {
            case self::SHUTTER:
                $halfH = ($h * 0.5) * $cover;
                if ($halfH > 0.5) {
                    $p->fill([$ox, $oy, $w, $halfH], $this->color);
                    $p->fill([$ox, $oy + $h - $halfH, $w, $halfH], $this->color);
                }
                break;

            case self::BATTLE_BLINDS:
                // First 22%: two crisp white encounter flashes
                if ($t < 0.22) {
                    $flashPhase = sin(($t / 0.22) * M_PI * 2.0);
                    if ($flashPhase > 0.0) {
                        $p->fill($r, 0xffffff, 0.0, min(1.0, $flashPhase * 0.85));
                    }
                    return;
                }
                // Remaining 78%: horizontal blinds closing and opening
                $bt = ($t - 0.22) / 0.78;
                $bCover = $bt <= 0.45 ? ($bt / 0.45) : ((1.0 - $bt) / 0.55);
                $bCover = min(1.0, max(0.0, $bCover));
                $stripes = 8;
                $stripeH = $h / $stripes;
                for ($i = 0; $i < $stripes; $i++) {
                    $fillH = $stripeH * $bCover;
                    if ($fillH > 0.5) {
                        $p->fill([$ox, $oy + $i * $stripeH, $w, $fillH + 0.5], $this->color);
                    }
                }
                break;

            case self::FADE:
            default:
                $alpha = min(1.0, max(0.0, $cover));
                if ($alpha > 0.0) {
                    $p->fill($r, $this->color, 0.0, $alpha);
                }
                break;
        }
    }
}