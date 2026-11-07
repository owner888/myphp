<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * Picker, as SwiftUI's: one of the choices its content gives, each a Text
 * or a Label tagged with its value (Text('Red')->tag(Flavor::red)), bound
 * to $selection; drawn as pickerStyle() says: a pop-up menu (menu, and
 * automatic), a segmented control (segmented), radio buttons (radioGroup,
 * inline). Its title leads it in a Form.
 */
final class Picker extends View
{
    private mixed $selection;
    /** @var list<mixed> */
    private array $content = [];

    private ?Binding $binding = null;

    /** $selection: a variable, or a Binding(get:set:) of a value computed. */
    public function __construct(public readonly string $title, mixed &$selection)
    {
        if ($selection instanceof Binding) { $this->binding = $selection; $this->selection = $selection->get(); return; }
        $this->selection = &$selection;
    }

    /** The choices: Text and Label views, each with its tag(), or Each of them. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    /** Its choices, in a menu, as SwiftUI's Picker in a Menu: each an item, the one chosen checked. @internal */
    public function menuItems(\MyPHP\UI\Menu $m, Env $env): void
    {
        [$labels, $values] = $this->choices($env);
        foreach ($labels as $i => $label) {
            if ($m->item($label)->checked($values[$i] === $this->selection)->chosen()) {
                $this->selection = $values[$i];
                $this->binding?->set($values[$i]);
            }
        }
    }

    /** The labels of its choices and the values they choose. @return array{0: list<string>, 1: list<mixed>} */
    private function choices(Env $env): array
    {
        $labels = $values = [];
        foreach (ListView::flat($this->content) as $v) {
            if (!$v->hasTag) { throw new \LogicException("a choice of Picker('{$this->title}') takes a tag(), the value it chooses: Text('Red')->tag(Flavor::red)"); }
            $labels[] = $v instanceof Text ? ($v->verbatim ? $v->text : Localized::string($v->text, $env, $v->args)) : ($v instanceof Label ? Localized::string($v->title, $env) : throw new \LogicException("a choice of Picker('{$this->title}') is a Text or a Label, not " . get_debug_type($v)));
            $values[] = $v->tagValue;
        }
        return [$labels, $values];
    }

    /**
     * The pop-up button $n as a phone's form shows one, UIKit's menu button in a cell: no face or border, no height of
     * its own (the row's 44), the choice in the secondary label's color and chevron.up.chevron.down after it.
     */
    private static function plain(\MyPHP\UI\Node $n, Env $env): void
    {
        $n->bordered = false; $n->color = null; $n->borderWidth = 0.0;
        $n->padding(0)->minWidth(0)->minHeight(0)->shrink(100); // short of room, the choice shortens first (to nothing before the title gives any), as a value1 cell's detail
        $n->gap = 6;
        foreach ($n->kids as $k) {
            if ($k->kind === \MyPHP\UI\Node::TEXT) { $k->fg = $env->theme->textMuted; $k->shrink(100)->minWidth(0); continue; }
            $k->draw(static function (\MyPHP\UI\Painter $p, array $r): void { // up over down, as the symbol
                [$x, $y, $w] = $r; $c = $p->theme()->textMuted; $l = $p->displayList(); $h = $w * 0.32;
                $l->line($x + $w * 0.2, $y + $h + $w * 0.05, $x + $w * 0.5, $y + $w * 0.05, $c, 1.0, 1.5);
                $l->line($x + $w * 0.5, $y + $w * 0.05, $x + $w * 0.8, $y + $h + $w * 0.05, $c, 1.0, 1.5);
                $l->line($x + $w * 0.2, $y + $w * 0.95 - $h, $x + $w * 0.5, $y + $w * 0.95, $c, 1.0, 1.5);
                $l->line($x + $w * 0.5, $y + $w * 0.95, $x + $w * 0.8, $y + $w * 0.95 - $h, $c, 1.0, 1.5);
            });
        }
    }

    protected function build(Context $c, Env $env): ?Node
    {
        [$labels, $values] = $this->choices($env);
        $style = $env->pickerStyle ?? \PickerStyle::automatic;
        $was = $this->selection;
        $n = LabeledContent::control($c, $env, $style === \PickerStyle::segmented && !$env->inList ? '' : $this->title, function () use ($c, $env, $style, $labels, $values): Node {
            if ($style === \PickerStyle::radioGroup || $style === \PickerStyle::inline) {
                $n = $c->radioGroup(function () use ($c, $labels, $values): void {
                    foreach ($labels as $i => $label) { $c->radio($label, $this->selection, $values[$i]); }
                }, $env->radioRow);
            } else {
                $found = array_search($this->selection, $values, true);
                $index = $found === false ? -1 : $found;
                $n = $style === \PickerStyle::segmented ? $c->segmented($labels, $index) : $c->select($labels, $index);
                if ($style !== \PickerStyle::segmented && $env->formRow && ($c->runtime?->touch ?? false)) { self::plain($n, $env); } // a phone's form: the choice in the muted text and its arrows, as UIKit's menu button in a cell
                if ($style === \PickerStyle::segmented && $env->formRow && ($c->runtime?->touch ?? false)) { $n->grow = 1; $n->shrink(1)->minWidth(0); foreach ($n->kids as $k) { $k->basis(0)->grow = 1; } } // across its row, its segments sharing it, as UIKit's in a cell
                if ($index >= 0 && $index !== $found) { $this->selection = $values[$index]; }
            }
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
        if ($this->binding !== null && $this->selection !== $was) { $this->binding->set($this->selection); } // chosen: through the binding's set
        return $n;
    }
}
