<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * KeyframeAnimator, as SwiftUI's: the view $content makes of the value its keyframes give it over time — played as
 * $trigger changes (its initial value before), or over and over while $repeating — built anew every frame while it plays.
 */
final class KeyframeAnimator extends View
{
    /** What a trigger not given holds. @internal */
    public const UNSET = "\0unset";

    /** @param Closure(): list<KeyframeTrack|Keyframe>|list<KeyframeTrack|Keyframe> $keyframes */
    public function __construct(
        public readonly array|object|float|int $initialValue,
        private readonly mixed $trigger,
        private readonly bool $repeating,
        private readonly Closure $content,
        private readonly Closure|array $keyframes,
    ) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $timeline = new KeyframeTimeline($this->initialValue, $this->keyframes instanceof Closure ? ($this->keyframes)($this->initialValue) : $this->keyframes);
        $rt = $c->runtime;
        $kept = $rt?->local($c->upcomingId(), static fn() => (object)['start' => null, 'trigger' => self::UNSET], null) ?? (object)['start' => null, 'trigger' => self::UNSET];
        $now = $c->now();
        if ($this->repeating) { $kept->start ??= $now; }
        elseif ($kept->trigger !== self::UNSET && $kept->trigger !== $this->trigger) { $kept->start = $now; } // the trigger changed: it plays
        $kept->trigger = $this->trigger;
        $value = $this->initialValue;
        if ($kept->start !== null) {
            $d = $timeline->duration();
            $t = ($now - $kept->start) / 1e9;
            if ($this->repeating && $d > 0) { $t = fmod($t, $d); }
            if ($this->repeating || $t < $d) { $c->animationFrame(); } // it plays: another frame
            $value = $timeline->value(min($t, $d));
        }
        $n = $c->column(0, 0)->alignItems(Node::CENTER);
          View::renderAll($c, $env->along(Node::COLUMN), ($this->content)($value));
        $c->end();
        $flex = View::flexOfKids($n);
        if ($flex !== 0) { View::takeRoom($n, $env->axis, $flex); }
        return $n;
    }
}
