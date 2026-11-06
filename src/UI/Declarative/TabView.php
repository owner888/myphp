<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * TabView, as SwiftUI's with .sidebarAdaptable: its Tabs, the one whose
 * value $selection is shown, chosen in a tab bar across the bottom on a
 * phone, tabs across the top on a tablet held upright, a sidebar
 * otherwise. Each tab keeps its NavigationStack's history as another shows.
 */
final class TabView extends View
{
    /** @var list<Tab> */
    private array $tabs = [];
    private mixed $selection;

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
}
