<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Gauge, as SwiftUI's: $value between the ends of $in, a level meter; its title leads it in a Form. */
final class Gauge extends View
{
    /** @param array{0: float, 1: float} $in */
    public function __construct(public readonly float $value, public readonly array $in = [0.0, 1.0], public readonly string $title = '') {}

    protected function build(Context $c, Env $env): ?Node
    {
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env): Node {
            $n = $c->meter($this->value, (float)$this->in[0], (float)$this->in[1], null, 0);
            $n->label(Localized::string($this->title !== '' ? $this->title : 'Gauge', $env));
            return $n;
        }, true);
    }
}
