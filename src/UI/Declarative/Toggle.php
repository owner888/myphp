<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Toggle, as SwiftUI's: $isOn, bound, switched by a switch with its title, or a checkbox with toggleStyle(ToggleStyle::checkbox). */
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
        $n = $this->style === \ToggleStyle::checkbox ? $c->checkbox($title, $this->isOn) : $c->toggle($this->isOn, $title);
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }
}
