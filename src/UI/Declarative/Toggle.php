<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Toggle, as SwiftUI's: $isOn, bound, its title beside a switch, a checkbox or a button that stays pressed (toggleStyle); a switch on a phone and a checkbox on a Mac by default. */
final class Toggle extends View
{
    private bool $isOn;
    private \ToggleStyle $style = \ToggleStyle::automatic;

    public function __construct(public readonly string $title, bool &$isOn)
    {
        $this->isOn = &$isOn;
    }

    /** Whether it is on: a menu's item shows it checked. @internal */
    public function isOn(): bool { return $this->isOn; }

    /** Switches it, as choosing it in a menu does. @internal */
    public function flip(): void { $this->isOn = !$this->isOn; }

    public function toggleStyle(\ToggleStyle $style): static { $this->style = $style; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $title = Localized::string($this->title, $env);
        $style = $this->style === \ToggleStyle::automatic ? (($c->runtime?->touch ?? false) ? \ToggleStyle::switch : \ToggleStyle::checkbox) : $this->style;
        $n = match ($style) {
            \ToggleStyle::checkbox => $c->checkbox($title, $this->isOn),
            \ToggleStyle::button => $c->toggle($this->isOn, $title),
            default => $c->switch($title, $this->isOn),
        };
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }
}
