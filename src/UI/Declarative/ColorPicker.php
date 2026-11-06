<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** ColorPicker, as SwiftUI's: a well showing $selection, bound (0xRRGGBB), that opens the system's colors; with $supportsOpacity, its $opacity too. */
final class ColorPicker extends View
{
    private int $selection;
    private float $opacity;

    public function __construct(public readonly string $title, int &$selection, public readonly bool $supportsOpacity = false, ?float &$opacity = null)
    {
        $this->selection = &$selection;
        $opacity ??= 1.0;
        $this->opacity = &$opacity;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        return LabeledContent::control($c, $env, $this->title, function () use ($c, $env): Node {
            $alpha = $this->supportsOpacity ? $this->opacity : 1.0;
            $n = $c->colorWell($this->selection, $alpha);
            if ($this->supportsOpacity) { $this->opacity = $alpha; }
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
    }
}
