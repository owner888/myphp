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

    protected function build(Context $c, Env $env): ?Node
    {
        return self::scroller($c, $env, function (Env $env) use ($c): void {
            if ($this->axis === \Axis::horizontal) {
                $c->row(0, 8)->alignItems(Node::CENTER);
                  View::renderAll($c, $env->along(Node::ROW), $this->content);
                $c->end();
                return;
            }
            View::renderAll($c, $env->along(Node::COLUMN), $this->content);
        }, $this->axis === \Axis::horizontal, 8);
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
        $inner = $env->with(['refresh' => null]); // what is inside does not refresh again
        $content = function () use ($c, $env, $inner, $fn, $large): void {
            if ($large !== null) { $c->largeTitle($env->title ?? ''); }
            $fn($inner);
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
        return $n;
    }
}
