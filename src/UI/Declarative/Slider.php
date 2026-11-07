<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Slider, as SwiftUI's: $value, bound, between the ends of $in ([0, 1] by default), by $step when given; as wide as its room. */
final class Slider extends View
{
    private float|int $value;
    private ?Binding $binding = null;

    /** @param array{0: float, 1: float} $in */
    /** $value: a variable, or a Binding(get:set:) of a value computed. */
    public function __construct(float|int|Binding &$value, public readonly array $in = [0.0, 1.0], public readonly ?float $step = null, public readonly string $title = '', private readonly ?\Closure $onEditingChanged = null)
    {
        if ($value instanceof Binding) { $this->binding = $value; $this->value = (float)$value->get(); return; }
        $this->value = &$value;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        [$lo, $hi] = $this->in;
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env, $lo, $hi): Node {
            $v = (float)$this->value;
            $n = $this->step !== null ? $c->stepSlider($v, $lo, $hi, $this->step, 0) : $c->slider($v, $lo, $hi, 0);
            if ($v !== (float)$this->value) { $this->value = is_int($this->value) ? (int)round($v) : $v; $this->binding?->set($this->value); }
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            if ($this->onEditingChanged !== null && ($life = Lifecycle::of($c, $n)) !== null && $life->editingChanged($c->dragging($n))) { ($this->onEditingChanged)($c->dragging($n)); } // as the pointer takes it and lets it go
            return $n;
        }, true);
    }
}
