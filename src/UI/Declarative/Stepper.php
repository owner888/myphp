<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Stepper, as SwiftUI's: its title, and buttons that step $value, bound, by $step between the ends of $in. */
final class Stepper extends View
{
    private int|float $value;

    /** @param array{0: float, 1: float} $in */
    public function __construct(public readonly string $title, int|float &$value, public readonly array $in = [PHP_INT_MIN, PHP_INT_MAX], public readonly float $step = 1)
    {
        $this->value = &$value;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env): Node {
            $n = $c->stepper($this->value, (float)$this->in[0], (float)$this->in[1], $this->step);
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
    }
}
