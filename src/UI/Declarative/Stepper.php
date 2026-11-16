<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Stepper, as SwiftUI's: its title, and buttons that step $value, bound, by $step between the ends of $in. */
final class Stepper extends View
{
    private int|float $value;
    private ?Binding $binding = null;

    /** @param array{0: float, 1: float} $in */
    public function __construct(public readonly string $title, int|float|Binding|\MyPHP\UI\Reactive\State &$value, public readonly array $in = [PHP_INT_MIN, PHP_INT_MAX], public readonly float $step = 1)
    {
        if ($value instanceof \MyPHP\UI\Reactive\State) { $this->binding = $value->binding(); $this->value = $value->value; return; }
        if ($value instanceof Binding) { $this->binding = $value; $this->value = $value->get(); return; }
        $this->value = &$value;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env): Node {
            $was = $this->value;
            $n = $c->stepper($this->value, (float)$this->in[0], (float)$this->in[1], $this->step);
            if ($this->value !== $was) { $this->binding?->set($this->value); }
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
    }
}
