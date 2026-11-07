<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\App;
use MyPHP\UI\{Context, Node, ScrollState};

/**
 * ScrollView, as SwiftUI's: its content, one view under the other, 8 apart,
 * scrolled when it outgrows the room, which it takes all of; across with
 * Axis::horizontal. The large title of a NavigationStack's page that asks
 * for one (navigationBarTitleDisplayMode), and pull to refresh
 * (refreshable), are a ScrollView's.
 */
final class ScrollView extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly \Axis $axis = \Axis::vertical) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    private bool $bottom = false;

    /** Where it starts, and stays as its content grows: UnitPoint::bottom, a chat's, as SwiftUI's defaultScrollAnchor. */
    public function defaultScrollAnchor(\UnitPoint $anchor): static { $this->bottom = $anchor === \UnitPoint::bottom; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        $only = count($this->content) === 1 ? $this->content[0] : null;
        if ($only instanceof LazyVStack) { // what is lazy scrolls itself, only what shows built
            return $only->scrolled($c, $env, $this->bottom);
        }
        if ($this->axis === \Axis::horizontal) { // as SwiftUI's: across the room it is given, as high as what it shows
            $state = $c->runtime?->local($c->upcomingId(), fn() => new ScrollState(), ScrollState::class) ?? new ScrollState(); // of its type: another view built here before (an if's other branch) kept other state
            $n = $c->row(0, 8)->alignItems(Node::CENTER)->scroll($state, horizontal: true, vertical: false);
              View::renderAll($c, $env->along(Node::ROW), $this->content);
            $c->end();
            View::takeRoom($n, $env->axis, View::FLEX_X);
            return $n;
        }
        return self::scroller($c, $env, function (Env $env) use ($c): void {
            View::renderAll($c, $env->along(Node::COLUMN), $this->content);
        }, false, 8);
    }

    /**
     * A scroll filling the room it is given, its content what $fn builds:
     * under the large title of a page that asks for one, pulled to refresh
     * when the environment has a refreshable. $env's color its background. @internal
     * @param \Closure(Env): void $fn
     */
    public static function scroller(Context $c, Env $env, \Closure $fn, bool $both = false, float $gap = 0, ?int $background = null): Node
    {
        $large = $env->stack?->largeTitle($c, $env);
        $inset = $c->takeBottomInset(); // a bar floating over the page's bottom: room after the content, which scrolls under it
        $inner = $env->with(['refresh' => null]); // what is inside does not refresh again
        $content = function () use ($c, $env, $inner, $fn, $large, $inset): void {
            if ($large !== null) { $c->largeTitle(Localized::string($env->title ?? '', $env)); }
            $fn($inner);
            if ($inset > 0) { $c->box(0, $inset, 0)->shrink(0)->color = null; }
        };
        $rt = $c->runtime;
        if ($env->refresh !== null) {
            $kept = $rt === null ? (object)['scroll' => new ScrollState(), 'refreshing' => false]
                : $rt->local($c->upcomingId(), fn() => (object)['scroll' => $large ?? new ScrollState(), 'refreshing' => false]);
            $was = $kept->refreshing;
            $n = $c->refreshable($large ?? $kept->scroll, $kept->refreshing, $content);
            if ($kept->refreshing && !$was) { // pulled: the action in a fiber, the spinner until it ends
                $action = $env->refresh;
                App::go(function () use ($action, $kept, $rt): void { try { $action(); } finally { $kept->refreshing = false; $rt?->changed(); } });
            }
        } else {
            $n = $both ? $c->scrollBoth(0, $gap, 1, $background) : $c->scroll(0, $gap, 1, $background);
            if ($large !== null) { $n->scroll($large); }
              $content();
            $c->end();
        }
        $n->alignSelf(Node::STRETCH);
        $n->grow = max($n->grow, 1);
        if ($background !== null) { $n->background($background); }
        if ($background !== null && $env->backdrop !== null) { $env->backdrop->color ??= $background; } // the page's: the bars around it over it
        return $n;
    }
}
