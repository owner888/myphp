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

    private \TabBarMinimizeBehavior $minimize = \TabBarMinimizeBehavior::automatic;

    /** Whether a phone's tab bar shrinks to the chosen tab as the page scrolls down (onScrollDown) or up, as iOS 26's; a tap on it, or the page back at its top, brings it back. */
    public function tabBarMinimizeBehavior(\TabBarMinimizeBehavior $behavior): static { $this->minimize = $behavior; return $this; }

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
        if ($this->style === \TabViewStyle::page || $this->style === \TabViewStyle::pageAlways || $this->style === \TabViewStyle::pageNever) {
            return $this->paged($c, $env, $index);
        }
        $prevTheme = $c->theme;
        if ($env->tint !== null) {
            $tinted = clone $prevTheme;
            $tinted->accent = $env->tint->rgb($env);
            $c->theme = $tinted;
            $env = $env->with(['theme' => $tinted]);
        }
        try {
            if ($this->style === \TabViewStyle::automatic && !($c->runtime?->touch ?? false)) { return $this->desktop($c, $env, $index); }
            if ($this->style === \TabViewStyle::sidebarAdaptable && $c->tabsPlacement() === \MyPHP\UI\Context::TABS_SIDEBAR) { return $this->split($c, $env); }
            $items = array_map(fn(Tab $t) => [$t->icon(24), Localized::string($t->title, $env), $t->role === \TabRole::prominent], $this->tabs); // a prominent tab apart, at the bar's end (iOS 27's)
            $backdrop = (object)['color' => null, 'tabBarHidden' => false];
            $minimize = match ($this->minimize) { \TabBarMinimizeBehavior::onScrollDown => 1, \TabBarMinimizeBehavior::onScrollUp => -1, default => 0 };
            $n = $c->tabView($items, $index, function (int $i) use ($c, $env, $backdrop): void {
                $c->key('tab' . $i); // each tab its own nodes, which keep their state (a stack's history) by it
                $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
                  View::renderAll($c, $env->along(Node::COLUMN)->with(['backdrop' => $backdrop]), [self::stacked($this->tabs[$i]->content)]);
                $c->end();
            }, $minimize, $backdrop);
            if ($backdrop->color !== null) { $n->background($backdrop->color); } // the floating tab bar over the page's color, as iOS's
            $this->selection = $this->tabs[$index]->value ?? $this->selection;
            return $n;
        } finally {
            $c->theme = $prevTheme;
        }

    }

    /**
     * A tab's views as a page of a phone's or a tablet's tabs: in a NavigationStack, unless they are one, as a
     * SwiftUI app puts each tab's — its bar out of the status bar's way, its navigationTitle in it — so that a tab
     * written once for every screen (its title in the window's title bar on the desktop) has its bar there too.
     * @param list<mixed> $content
     */
    private static function stacked(array $content): View
    {
        $views = array_values(array_filter($content, fn($v) => $v !== null));
        if (count($views) === 1 && $views[0] instanceof NavigationStack) { return $views[0]; }
        return (new NavigationStack())(...$views);
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

    /** A paged carousel of the tabs (TabViewStyle::page), with swipe left/right and page indicator dots at the bottom. */
    private function paged(Context $c, Env $env, int &$index): Node
    {
        $count = max(1, count($this->tabs));
        $index = max(0, min($count - 1, $index));
        $showDots = $this->style !== \TabViewStyle::pageNever && $count > 1;
        $accent = $env->tint?->rgb($env) ?? $env->theme->accent;
        $muted = $env->theme->border;

        $n = $c->column(0, 0, 1)->alignSelf(Node::STRETCH)->alignItems(Node::STRETCH);
          $c->key('page' . $index);
          $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
            View::renderAll($c, $env->along(Node::COLUMN), $this->tabs[$index]->content ?? []);
          $c->end();
          if ($showDots) {
              $dots = $c->row(8, 8)->alignSelf(Node::CENTER)->alignItems(Node::CENTER)->justify(Node::CENTER);
              for ($i = 0; $i < $count; $i++) {
                  $active = $i === $index;
                  $dot = $c->box($active ? 8 : 7, $active ? 8 : 7, $active ? $accent : $muted)->radius(4)->cursor(\MyPHP\UI\Cursor::Pointer);
                  if ($c->clicked($dot)) {
                      $index = $i;
                  }
              }
              $c->end();
          }
        $c->end();
        $drag = new DragGesture(minimumDistance: 24);
        $drag->onEnded(function (DragGestureValue $v) use (&$index, $count): void {
            if ($v->translation->width < -30 && $index + 1 < $count) {
                $index++;
            } elseif ($v->translation->width > 30 && $index > 0) {
                $index--;
            }
        });
        self::attachPageDrag($c, $n, $drag);
        $this->selection = $this->tabs[$index]->value ?? $index;
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }

    private static function attachPageDrag(Context $c, Node $n, DragGesture $g): void
    {
        $v = new Group();
        $v->gesture($g);
        $ref = new \ReflectionMethod(View::class, 'dragGesture');
        $ref->invoke(null, $c, $n, $g);
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
