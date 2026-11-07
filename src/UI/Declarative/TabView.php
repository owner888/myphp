<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * TabView, as SwiftUI's: its Tabs, the one whose value $selection is
 * shown, chosen in a tab bar across the bottom on a phone and a tablet, as
 * tabs across the top on a Mac (automatic). With
 * tabViewStyle(.sidebarAdaptable), one view for every screen, as SwiftUI's
 * on iOS 18 and macOS 15: the tab bar across the bottom on a phone, tabs
 * across the top on a tablet held upright, and on a tablet on its side and
 * on the desktop a NavigationSplitView of the tabs — the sidebar of their
 * titles, icons and badges, which its button hides, beside the chosen
 * one's views, their navigationTitle and toolbar in the window's title bar.
 * Each tab keeps its NavigationStack's history as another shows.
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
        if ($this->style === \TabViewStyle::sidebarAdaptable && $c->tabsPlacement() === \MyPHP\UI\Context::TABS_SIDEBAR) { return $this->split($c, $env); }
        $items = array_map(fn(Tab $t) => [$t->icon(24), Localized::string($t->title, $env)], $this->tabs);
        $n = $c->tabView($items, $index, function (int $i) use ($c, $env): void {
            $c->key('tab' . $i); // each tab its own nodes, which keep their state (a stack's history) by it
            $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
              View::renderAll($c, $env->along(Node::COLUMN), $this->tabs[$i]->content);
            $c->end();
        });
        $this->selection = $this->tabs[$index]->value ?? $this->selection;
        return $n;
    }

    /** The tabs as a NavigationSplitView, as sidebarAdaptable's where there is room: a Label of each in the sidebar, choosing it, beside its views. */
    private function split(Context $c, Env $env): ?Node
    {
        $chosen = new Binding(get: fn() => $this->selection, set: function ($v) { $this->selection = $v; });
        $sidebar = new ListView(null, null, $chosen, true);
        $sidebar(...array_map(fn(Tab $t) => (new Label($t->title, $t->systemImage, $t->image))->badge($t->badgeValue)->tag($t->value), $this->tabs));
        $shown = null;
        foreach ($this->tabs as $t) { if ($t->value === $this->selection) { $shown = $t; } }
        $shown ??= $this->tabs[0] ?? null;
        $kept = $c->runtime?->local($c->upcomingId(), fn() => new class { public \NavigationSplitViewVisibility $sidebar = \NavigationSplitViewVisibility::all; }); // whether the sidebar shows, its button's
        $visibility = $kept === null ? \NavigationSplitViewVisibility::all : $kept->sidebar;
        $split = new NavigationSplitView($sidebar, $shown === null ? [] : (count($shown->content) === 1 && $shown->content[0] instanceof View ? $shown->content[0] : $shown->content), $visibility, true);
        $n = $split->render($c, $env);
        if ($kept !== null) { $kept->sidebar = $visibility; }
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
