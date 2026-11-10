<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Route, RouteEntry, Router, ScrollState, ViewFile};

/**
 * NavigationStack, as SwiftUI's: its root view, and over it the pages its
 * $path (a NavigationPath, a Router) holds, each the destination of the
 * first navigationDestination pattern its path matches — a closure or a
 * view file (pages/note.php), given the path's parts by name and type
 * (fn(int $id) => …), as navigationDestination(for:) gives a value — under
 * a navigation bar: the page's navigationTitle, the way back, its toolbar.
 * NavigationLink and $path->append() go to a page; the bar's back button,
 * Back, Escape and the edge swipe come back. A page keeps its state while
 * it is in the history.
 */
final class NavigationStack extends View
{
    /** @var list<mixed> */
    private array $content = [];
    /** @var array<string, string|Closure> */
    private array $destinations = [];
    private Router $router;

    public function __construct(public readonly ?Router $path = null) {}

    /** The root view. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * The page of the paths $for matches ('/notes/{id}'): $destination a
     * view file, or a closure, given the parts the pattern names by name and
     * type, and the Route by its type: fn(int $id) => NoteView(…).
     */
    public function navigationDestination(string $for, string|Closure $destination): static
    {
        $this->destinations[$for] = $destination;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $rt = $c->runtime;
        $this->router = $this->path ?? ($rt === null ? new Router('/') : $rt->local($c->upcomingId(), fn() => new Router('/'), Router::class));
        $entry = $this->router->entry();
        $large = $entry->largeTitle ?? null;
        $n = $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
        $backdrop = (object)['color' => null, 'tabBarHidden' => $env->backdrop->tabBarHidden ?? false, 'navBarHidden' => false];
          $toolbar = $entry->toolbar ?? null;
          $c->navigationBar($this->router->title(), $this->router,
              $toolbar === null ? null : function () use ($c, $env, $toolbar): void { View::renderAll($c, $env->along(Node::ROW)->with(['buttonStyle' => \ButtonStyle::borderless, 'labelStyle' => Label::inToolbar($env)]), $toolbar); },
              $large);
          $c->safeArea(function () use ($c, $env, $backdrop): void {
              $this->router->view($c, function (Route $r) use ($c, $env, $backdrop): void {
                  $e = $env->with(['route' => $r, 'stack' => $this, 'title' => null, 'titleMode' => null, 'toolbar' => null, 'backdrop' => $backdrop]);
                  $r->entry->barSeen = [];
                  View::renderAll($c, $e->along(Node::COLUMN), [$this->page($c, $r)]);
                  foreach (['toolbar', 'largeTitle'] as $k) { // what the page no longer puts in the bar goes from it
                      if (!isset($r->entry->barSeen[$k]) && $r->entry->$k !== null) { $r->entry->$k = null; $this->router->titleChanged(); }
                  }
              });
          });
        $c->end();
        if ($env->backdrop !== null && $backdrop->tabBarHidden) {
            $env->backdrop->tabBarHidden = true;
        }
        if ($backdrop->color !== null && ($c->runtime?->touch ?? false)) { // a phone's bar is clear: the page's color behind it, up to the window's top
            $n->background($backdrop->color);
            if ($env->backdrop !== null) { $env->backdrop->color ??= $backdrop->color; }
        }
        if ($c->runtime?->touch ?? false) { $n->drawOver(self::scrollEdge(...)); }
        $n->grow = max($n->grow, 1); // it takes the room it is given, along the stack it is in and across it
        return $n;
    }

    /**
     * iOS 26's scroll edge under a phone's bar: what the page scrolled up fades into the page's color over the 20
     * points below the bar, rather than being cut at its edge; at rest, the page's top shows whole.
     */
    private static function scrollEdge(\MyPHP\UI\Painter $p, array $r, Node $n): void
    {
        $bar = $n->kids[0] ?? null;
        if ($bar === null) { return; }
        $queue = array_slice($n->kids, 1);
        $scrolled = 0.0;
        while ($queue !== []) { // the page's first scroll down
            $k = array_shift($queue);
            if ($k->scroll !== null && $k->scrollY) { $scrolled = $k->scroll->extent()[0]; break; }
            array_push($queue, ...$k->kids);
        }
        if ($scrolled <= 0) { return; }
        $p->displayList()->gradient($r[0], $bar->y + $bar->h, $r[2], 20, $n->color ?? $p->theme()->background, min(1.0, $scrolled / 20), 0.0);
    }

    /** The view of the page $r shows: the root's at "/", else the destination of the first pattern it matches. */
    private function page(Context $c, Route $r): View|array|null
    {
        if ($r->match('/')) {
            return Group()(...$this->content);
        }
        foreach ($this->destinations as $pattern => $page) {
            if ($r->match($pattern)) {
                $fn = is_string($page) ? ViewFile::of($page, $c->state)->view() : $page;
                return $fn(...self::args($fn, $r, $pattern));
            }
        }
        return Text('No page for ' . $r->path())->foregroundStyle(\Color::secondary);
    }

    /** The arguments of page $fn: the parts of the path $pattern named, by name and type, and the Route by its type. @return list<mixed> */
    public static function args(Closure $fn, Route $r, string $pattern): array
    {
        $named = $r->params();
        $args = [];
        foreach ((new \ReflectionFunction($fn))->getParameters() as $p) {
            $type = $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : 'string';
            if ($type === Route::class) { $args[] = $r; continue; }
            $name = $p->getName();
            if (!array_key_exists($name, $named)) {
                if ($p->isDefaultValueAvailable()) { $args[] = $p->getDefaultValue(); continue; }
                throw new \LogicException("the page of $pattern takes \$$name, which the pattern does not name");
            }
            $v = $named[$name];
            $args[] = match ($type) { 'int' => (int)$v, 'float' => (float)$v, 'bool' => $v !== '' && $v !== '0' && $v !== 'false', default => $v };
        }
        return $args;
    }

    /** What the page $env is on puts in the bar: its toolbar (View::toolbar). @internal */
    public function setToolbar(Env $env, array $items): void
    {
        if ($env->route === null) { return; }
        $entry = $env->route->entry;
        $entry->barSeen['toolbar'] = true;
        if ($entry->toolbar === null) { $this->router->titleChanged(); } // the bar, built before the page, shows it in another pass
        $entry->toolbar = $items;
    }

    /** What scrolls the large title of the page $env is on: its ScrollView's ScrollState, kept by the page, or $scroll, a list's; null unless the page asks for one. @internal */
    public function largeTitle(Context $c, Env $env, ?\MyPHP\UI\Scrollable $scroll = null): ?\MyPHP\UI\Scrollable
    {
        // automatic: large on a phone, as SwiftUI's stack on iOS; inline on the desktop, where macOS has none
        $mode = $env->titleMode ?? \TitleDisplayMode::automatic;
        $large = $mode === \TitleDisplayMode::large || ($mode === \TitleDisplayMode::automatic && ($c->runtime?->touch ?? false) && ($env->title ?? '') !== '');
        if ($env->route === null || !$large) { return null; }
        $entry = $env->route->entry;
        if (isset($entry->barSeen['largeTitle'])) { return null; } // the page's first scroll has it, not one inside it
        $entry->barSeen['largeTitle'] = true;
        $scroll ??= $entry->largeScroll ??= new ScrollState(); // a ScrollView's, kept by the page; or a list's own
        if ($entry->largeTitle !== $scroll) {
            $entry->largeTitle = $scroll;
            $this->router->titleChanged();
        }
        return $entry->largeTitle;
    }
}
