<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Role};

/** Toggle, as SwiftUI's: $isOn, bound, its title beside a switch, a checkbox or a button that stays pressed (toggleStyle); a switch on a phone and a checkbox on a Mac by default. */
final class Toggle extends View
{
    private bool $isOn;
    private ?Binding $binding = null;
    private \ToggleStyle $style = \ToggleStyle::automatic;

    /** @var list<bool>|null the bindings a Toggle(sources:) switches together */
    private ?array $sources = null;

    /** $isOn: a variable, a State<bool>, or a Binding(get:set:), whose set() a switch by the user runs, not a change of the app's. */
    public function __construct(public readonly string $title, bool|Binding|\MyPHP\UI\Reactive\State|null &$isOn)
    {
        if ($isOn instanceof \MyPHP\UI\Reactive\State) { $this->binding = $isOn->binding(); $this->isOn = (bool)$isOn->value; return; }
        if ($isOn instanceof Binding) { $this->binding = $isOn; $this->isOn = (bool)$isOn->get(); return; }
        if ($isOn === null) { $isOn = false; }
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
        if ($this->sources !== null) { return $this->mixed($c, $env, $title, $style); }
        $was = $this->isOn;
        if ($env->formRow && $style === \ToggleStyle::switch) { // a form's row, as SwiftUI's: the title leading, wrapping as long text does, the switch at the row's end
            $row = $c->open(new Node(Node::CONTAINER, Node::ROW, 0, 8))->alignItems(Node::CENTER);
              new Text($this->title)->render($c, $env->along(Node::ROW)->with(['formRow' => false]))?->shrink(1)->grow(1);
              $n = self::makeSwitch($c, $env, '', $this->isOn)->shrink(0);
              $n->label($title);
            $c->end();
            View::takeRoom($row, $env->axis, View::FLEX_X);
            if ($this->isOn !== $was) { $this->binding?->set($this->isOn); }
            if ($env->disabled) { $row->disabled(); } // the title and the switch dimmed together, neither taking a tap
            return $row;
        }
        $n = match ($style) {
            \ToggleStyle::checkbox => self::makeCheckbox($c, $env, $title, $this->isOn),
            \ToggleStyle::button => $c->toggle($this->isOn, $title),
            default => self::makeSwitch($c, $env, $title, $this->isOn),
        };
        if ($this->isOn !== $was) { $this->binding?->set($this->isOn); }
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }

    /** The checkbox of a Toggle(sources:): checked, mixed or clear as its sources are; a click checks them all, or clears them all once all are. */
    private function mixed(Context $c, Env $env, string $title, \ToggleStyle $style): Node
    {
        $all = $this->sources !== [] && !in_array(false, $this->sources, true);
        $some = in_array(true, $this->sources, true);
        $on = $all;
        $n = $style === \ToggleStyle::switch ? self::makeSwitch($c, $env, $title, $on) : self::makeCheckbox($c, $env, $title, $on);
        if ($on !== $all) { foreach ($this->sources as &$s) { $s = !$all; } unset($s); }
        elseif (!$all && $some) { $n->mixed = true; $n->checked = 3; } // some on: neither, as the box shows
        return $n;
    }

    private static function makeCheckbox(Context $c, Env $env, string $label, bool &$checked): Node
    {
        $t = $env->theme;
        $size = $t->fontSize;
        [$w, $h] = $c->metrics()->measure($label, $size);
        $n = $c->attach(new Node(Node::CHECKBOX, 0, 0, 0, $label === '' ? $t->space(4) : $w + $t->space(6), $label === '' ? $t->space(4) : max($h, $t->space(4)), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($c->runtime?->clicked($n->id) ?? false) {
            $checked = !$checked;
            $n->changed = true;
        }
        $n->on = $checked;
        $n->role(Role::CHECK_BOX)->checked($checked);
        return $n;
    }

    private static function makeSwitch(Context $c, Env $env, string $label, bool &$on): Node
    {
        $t = $env->theme;
        $size = $t->fontSize;
        [$w, $h] = $c->metrics()->measure($label, $size);
        [$tw, $th] = $t->switchWidth > 0 ? [$t->switchWidth, $t->switchHeight] : [$t->space(9), $t->space(5)];
        $n = $c->attach(new Node(Node::SWITCH, 0, 0, 0, $label === '' ? $tw : $w + $tw + $t->space(2), max($h, $th), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($c->runtime?->clicked($n->id) ?? false) {
            $on = !$on;
            $n->changed = true;
            $c->runtime->haptic('light');
        }
        $n->on = $on;
        $n->fraction = $c->animate($n, 'knob', $on ? 1 : 0, 0.14);
        $n->role(Role::SWITCH)->checked($on);
        return $n;
    }
}
