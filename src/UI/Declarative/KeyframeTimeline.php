<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * Keyframes of values from an initial value, sampled at any time, as SwiftUI's KeyframeTimeline: what a
 * KeyframeAnimator plays, and what a scrubber shows at its time (value(time:)). $initialValue is an array of values
 * (or an object of properties) each track animates by its key, or a number one track ('') animates.
 */
final class KeyframeTimeline
{
    /** @param list<KeyframeTrack|Keyframe> $tracks keyframes alone make a track of the number $initialValue */
    public array $tracks = [];

    public function __construct(public readonly array|object|float|int $initialValue, array $tracks = [])
    {
        $loose = [];
        foreach ($tracks as $t) { if ($t instanceof Keyframe) { $loose[] = $t; } else { $this->tracks[] = $t; } }
        if ($loose !== []) { $this->tracks[] = (new KeyframeTrack(''))(...$loose); }
    }

    /** How long it plays, in seconds: its longest track's. */
    public function duration(): float
    {
        $d = 0.0;
        foreach ($this->tracks as $t) { $d = max($d, $t->duration()); }
        return $d;
    }

    /** The value at $time seconds: the initial value, each track's key set as its keyframes have it then. */
    public function value(float $time): array|object|float
    {
        $v = $this->initialValue;
        if (is_int($v) || is_float($v)) {
            foreach ($this->tracks as $t) { $v = $t->value((float)$this->initialValue, $time); }
            return (float)$v;
        }
        if (is_object($v)) { $v = clone $v; }
        foreach ($this->tracks as $t) {
            if (is_array($v)) { $v[$t->property] = $t->value((float)($this->initialValue[$t->property] ?? 0), $time); }
            else { $v->{$t->property} = $t->value((float)($this->initialValue->{$t->property} ?? 0), $time); }
        }
        return $v;
    }
}
