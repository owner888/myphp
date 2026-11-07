<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * The keyframes of one value, as SwiftUI's KeyframeTrack(\.scale): KeyframeTrack('scale')( LinearKeyframe(1.2,
 * duration: 0.2), SpringKeyframe(1, duration: 0.4) ) — the key of the value in the timeline's initialValue array (or
 * its property), '' for an initialValue that is a number.
 */
final class KeyframeTrack
{
    /** @var list<Keyframe> */
    public array $keyframes = [];

    public function __construct(public readonly string $property = '') {}

    public function __invoke(Keyframe ...$keyframes): static
    {
        $this->keyframes = $keyframes;
        return $this;
    }

    /** How long its keyframes take, in seconds. */
    public function duration(): float
    {
        $d = 0.0;
        foreach ($this->keyframes as $k) { $d += $k->kind === Keyframe::MOVE ? 0.0 : $k->duration; }
        return $d;
    }

    /** Its value at $time seconds, from $from. */
    public function value(float $from, float $time): float
    {
        $v = $from; $t0 = 0.0; $velocity = 0.0;
        $ks = $this->keyframes;
        foreach ($ks as $i => $k) {
            if ($k->kind === Keyframe::MOVE) { $v = $k->to; $velocity = 0.0; continue; }
            $d = max($k->duration, 1e-9);
            if ($time < $t0 + $d) {
                $u = max(0.0, ($time - $t0) / $d);
                return match ($k->kind) {
                    Keyframe::LINEAR => $v + ($k->to - $v) * ($k->curve === null ? $u : ($k->curve)($u)),
                    Keyframe::SPRING => $this->spring($v, $k, $velocity, $u * $d),
                    default => self::cubic($ks[$i - 1]->to ?? $v, $v, $k->to, ($ks[$i + 1] ?? null)?->to ?? $k->to, $u, $i === 0 ? $from : null),
                };
            }
            $velocity = $k->kind === Keyframe::LINEAR ? ($k->to - $v) / $d : 0.0;
            $v = $k->to; $t0 += $d;
        }
        return $v;
    }

    /** Through $p1 to $p2 smoothly, as a Catmull-Rom spline through $p0 and $p3 on either side (SwiftUI's cubic keyframes). */
    private static function cubic(float $p0, float $p1, float $p2, float $p3, float $u, ?float $first): float
    {
        if ($first !== null) { $p0 = $p1; } // the first keyframe starts at rest
        $m1 = ($p2 - $p0) / 2; $m2 = ($p3 - $p1) / 2;
        $u2 = $u * $u; $u3 = $u2 * $u;
        return (2 * $u3 - 3 * $u2 + 1) * $p1 + ($u3 - 2 * $u2 + $u) * $m1 + (-2 * $u3 + 3 * $u2) * $p2 + ($u3 - $u2) * $m2;
    }

    /** From $from to the keyframe's value on its spring, $dt seconds in, going at $velocity at first. */
    private function spring(float $from, Keyframe $k, float $velocity, float $dt): float
    {
        [$m, $stiff, $damp] = ($k->spring ?? new \MyPHP\UI\SpringOptions())->physics();
        [$x] = \MyPHP\UI\Motion::solveSpring($from - $k->to, $velocity, $m, $stiff, $damp, $dt);
        return $k->to + $x;
    }
}
