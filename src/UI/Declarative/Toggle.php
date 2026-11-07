<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Toggle, as SwiftUI's: $isOn, bound, its title beside a switch, a checkbox or a button that stays pressed (toggleStyle); a switch on a phone and a checkbox on a Mac by default. */
final class Toggle extends View
{
    private bool $isOn;
    private ?Binding $binding = null;
    private \ToggleStyle $style = \ToggleStyle::automatic;

    /** @var list<bool>|null the bindings a Toggle(sources:) switches together */
    private ?array $sources = null;

    /** $isOn: a variable, or a Binding(get:set:), whose set() a switch by the user runs, not a change of the app's. */
    public function __construct(public readonly string $title, bool|Binding &$isOn)
    {
        if ($isOn instanceof Binding) { $this->binding = $isOn; $this->isOn = (bool)$isOn->get(); return; }
        $this->isOn = &$isOn;
    }

    /** A Toggle of the bindings $sources: on while all are, mixed while some are; switching it switches them all, as SwiftUI's Toggle(sources:isOn:). @param list<bool> $sources references */
    public static function of(string $title, array &$sources): self
    {
        $on = false;
        $t = new self($title, $on);
        $t->sources = &$sources;
        return $t;
    }

    /** Whether it is on: a menu's item shows it checked. @internal */
    public function isOn(): bool { return $this->isOn; }

    /** Switches it, as choosing it in a menu does. @internal */
    public function flip(): void { $this->isOn = !$this->isOn; $this->binding?->set($this->isOn); }

    public function toggleStyle(\ToggleStyle $style): static { $this->style = $style; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $title = Localized::string($this->title, $env);
        $style = $this->style === \ToggleStyle::automatic ? (($c->runtime?->touch ?? false) ? \ToggleStyle::switch : \ToggleStyle::checkbox) : $this->style;
        if ($this->sources !== null) { return $this->mixed($c, $title, $style); }
        $was = $this->isOn;
        $n = match ($style) {
            \ToggleStyle::checkbox => $c->checkbox($title, $this->isOn),
            \ToggleStyle::button => $c->toggle($this->isOn, $title),
            default => $c->switch($title, $this->isOn),
        };
        if ($this->isOn !== $was) { $this->binding?->set($this->isOn); }
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }

    /** The checkbox of a Toggle(sources:): checked, mixed or clear as its sources are; a click checks them all, or clears them all once all are. */
    private function mixed(Context $c, string $title, \ToggleStyle $style): Node
    {
        $all = $this->sources !== [] && !in_array(false, $this->sources, true);
        $some = in_array(true, $this->sources, true);
        $on = $all;
        $n = $style === \ToggleStyle::switch ? $c->switch($title, $on) : $c->checkbox($title, $on);
        if ($on !== $all) { foreach ($this->sources as &$s) { $s = !$all; } unset($s); }
        elseif (!$all && $some) { $n->mixed = true; $n->checked = 3; } // some on: neither, as the box shows
        return $n;
    }
}
