<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * TabView, as SwiftUI's: its Tabs, the one whose value $selection is
 * shown, chosen in a tab bar across the bottom on a phone and a tablet, as
 * tabs across the top on a Mac (automatic); with tabViewStyle(.sidebarAdaptable),
 * tabs across the top on a tablet held upright, a sidebar otherwise. Each
 * tab keeps its NavigationStack's history as another shows.
 */
final class TabView extends View
{
    /** @var list<Tab> */
    private array $tabs = [];
    private mixed $selection;
    private \TabViewStyle $style = \TabViewStyle::automatic;

    /** How the tabs show: automatic, across the top on a desktop; sidebarAdaptable, a sidebar there, as SwiftUI's. */
    public function tabViewStyle(\TabViewStyle $style): static { $this->style = $style; return $this; }

    public function __construct(mixed &$selection)
    {
        $this->selection = &$selection;
    }

    public function __invoke(Tab ...$tabs): static
    {
        $this->tabs = $tabs;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $index = 0;
        foreach ($this->tabs as $i => $t) { if ($t->value === $this->selection) { $index = $i; } }
        if ($this->style === \TabViewStyle::automatic && !($c->runtime?->touch ?? false)) { return $this->desktop($c, $env, $index); }
        $items = array_map(fn(Tab $t) => [$t->image, Localized::string($t->title, $env)], $this->tabs);
        $n = $c->tabView($items, $index, function (int $i) use ($c, $env): void {
            $c->key('tab' . $i); // each tab its own nodes, which keep their state (a stack's history) by it
            $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
              View::renderAll($c, $env->along(Node::COLUMN), $this->tabs[$i]->content);
            $c->end();
        });
        $this->selection = $this->tabs[$index]->value ?? $this->selection;
        return $n;
    }

    /** The tabs across the top, as a Mac's TabView: the chosen one's content under them. */
    private function desktop(Context $c, Env $env, int $index): Node
    {
        $n = $c->column(0, 8)->alignItems(Node::STRETCH);
          $c->tabs(array_map(fn(Tab $t) => Localized::string($t->title, $env), $this->tabs), $index);
          $c->key('tab' . $index); // each tab its own nodes, which keep their state by it
          $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
            View::renderAll($c, $env->along(Node::COLUMN), $this->tabs[$index]->content ?? []);
          $c->end();
        $c->end();
        $this->selection = $this->tabs[$index]->value ?? $this->selection;
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }
}
