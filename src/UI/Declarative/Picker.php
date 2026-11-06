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

    public function __construct(public readonly string $title, mixed &$selection)
    {
        $this->selection = &$selection;
    }

    /** The choices: Text and Label views, each with its tag(), or Each of them. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $labels = $values = [];
        foreach (ListView::flat($this->content) as $v) {
            if (!$v->hasTag) { throw new \LogicException("a choice of Picker('{$this->title}') takes a tag(), the value it chooses: Text('Red')->tag(Flavor::red)"); }
            $labels[] = $v instanceof Text ? ($v->verbatim ? $v->text : Localized::string($v->text, $env, $v->args)) : ($v instanceof Label ? Localized::string($v->title, $env) : throw new \LogicException("a choice of Picker('{$this->title}') is a Text or a Label, not " . get_debug_type($v)));
            $values[] = $v->tagValue;
        }
        $style = $env->pickerStyle ?? \PickerStyle::automatic;
        return LabeledContent::control($c, $env, $style === \PickerStyle::segmented && !$env->inList ? '' : $this->title, function () use ($c, $env, $style, $labels, $values): Node {
            if ($style === \PickerStyle::radioGroup || $style === \PickerStyle::inline) {
                $n = $c->radioGroup(function () use ($c, $labels, $values): void {
                    foreach ($labels as $i => $label) { $c->radio($label, $this->selection, $values[$i]); }
                }, $env->radioRow);
            } else {
                $found = array_search($this->selection, $values, true);
                $index = $found === false ? -1 : $found;
                $n = $style === \PickerStyle::segmented ? $c->segmented($labels, $index) : $c->select($labels, $index);
                if ($index >= 0 && $index !== $found) { $this->selection = $values[$index]; }
            }
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
    }
}
