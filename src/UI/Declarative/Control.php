<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * One of myphp's own controls SwiftUI has none of (ComboBox, TokenField,
 * Rating, RangeSlider, Breadcrumbs, Avatar), among the declarative views:
 * the node $make builds of the engine's control, its title leading it in
 * a Form as a Picker's does, disabled as the views around it are. @internal
 */
final class Control extends View
{
    /** @param Closure(Context, Env): Node $make */
    public function __construct(public readonly string $title, private readonly Closure $make, public readonly bool $wide = false) {}

    protected function build(Context $c, Env $env): ?Node
    {
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env): Node {
            $n = ($this->make)($c, $env);
            if ($this->title !== '') { $n->label(Localized::string($this->title, $env)); }
            if ($env->disabled) { $n->disabled(); }
            return $n;
        }, $this->wide);
    }
}
