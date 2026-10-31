<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The history of the pages of a window, or of a part of one, as a browser
 * keeps it for a tab (mygo's Router): the page shown, those before it that
 * back() returns to, and those after it that forward() goes to again. A
 * page is a path, as "/notes/42?tab=info", and view() builds the one shown.
 * Make a router once, in the app's state, and build its pages in the view:
 *
 *     $router = new Router('/notes');
 *     $router->view($c, function (Route $r) use ($c) {
 *         if ($r->match('/notes')) { $r->title('Notes'); notes($c); }
 *         elseif ($r->match('/notes/{id}')) { $r->title('Note'); note($c, $r->param('id')); }
 *         else { $c->text('Not found'); }
 *     });
 *
 * push() goes to a page and back() comes back, as do Cmd+[ and Cmd+], the
 * back and forward buttons of a mouse, Context::backButton and
 * forwardButton, and links to paths in its pages. A page can be a layout
 * around the pages of the rest of the path, which Route::view builds: the
 * layout stays, keeping its state, as they change inside it.
 *
 * A page keeps the state of its nodes while it is among the KEPT_PAGES
 * nearest in the history: going back to one finds it as it was, the
 * keyboard focus where it was. Going to a page moves the focus into it,
 * unless the focus is outside the router, as in a sidebar choosing the
 * pages, where it stays: screen readers then hear the title of the page.
 * Pages slide or fade in ($transition).
 */
final class Router
{
    /**
     * How pages replace each other: AUTO slides a page in from the right
     * going deeper, as from "/notes" to "/notes/42", and from the left back
     * up; pages across, as from "/notes" to "/settings", fade in; with
     * Reduce Motion, they all fade. FADE fades them in, NONE shows them at
     * once.
     */
    public const AUTO = 0, FADE = 1, NONE = 2;
    /** How many entries a history holds, and how many pages of it besides the one shown keep their state; how long pages slide and fade, in seconds. */
    public const MAX_HISTORY = 100, KEPT_PAGES = 10;
    public const SLIDE_TIME = 0.25, FADE_TIME = 0.15;

    public int $transition = self::AUTO;
    /** How pages move as they replace each other, if set: a spring or a curve, rather than easing out over SLIDE_TIME as they slide, FADE_TIME as they fade. */
    public ?Animation $animation = null;

    /** @var list<RouteEntry> */
    private array $entries = [];
    private int $at = 0;
    private int $pages = 0;
    /** The window's runtime showing the router, which a change asks for a frame. */
    private ?Runtime $rt = null;
    /** The state of view(); those of Route::view keep theirs in their nodes. */
    private RouteView $view;

    /** A router showing the page at $path. */
    public function __construct(string $path = '/')
    {
        $this->view = new RouteView;
        $this->replace($path);
    }

    /** The entry shown. */
    private function current(): RouteEntry
    {
        if ($this->entries === []) {
            $this->entries = [new RouteEntry('/')];
            $this->at = 0;
        }
        return $this->entries[$this->at];
    }

    /** The page showing entry $e at $level, made the first time: a number of the router. */
    public function pageAt(RouteEntry $e, int $level): int
    {
        while (count($e->pages) <= $level) {
            $e->pages[] = ++$this->pages;
        }
        return $e->pages[$level];
    }

    /**
     * The location of $target, relative to the page shown as a link in a
     * web page is (RFC 3986's resolution, as Go's ResolveReference): its
     * path made absolute and clean, escaped, and its query; and the path
     * unescaped.
     * @return array{0: string, 1: string}
     */
    private function resolve(string $target): array
    {
        [$path, $query] = self::split($target);
        [$basePath, $baseQuery] = self::split($this->entries === [] ? '/' : $this->entries[$this->at]->loc);
        if ($path === '') {
            $path = $basePath;
            $query ??= $baseQuery;
        } elseif ($path[0] !== '/') {
            $path = substr($basePath, 0, (int)strrpos($basePath, '/') + 1) . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) { // Go's path.Clean of "/" + the path, on the escaped parts
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = self::escape(rawurldecode($seg));
        }
        $escaped = '/' . implode('/', $parts);
        return [$escaped . ($query !== null && $query !== '' ? '?' . $query : ''), rawurldecode($escaped)];
    }

    /** The path and the query of a location or a link, without its fragment; the query null when it has none. @return array{0: string, 1: ?string} */
    private static function split(string $s): array
    {
        $s = explode('#', $s, 2)[0];
        $q = strpos($s, '?');
        return $q === false ? [$s, null] : [substr($s, 0, $q), substr($s, $q + 1)];
    }

    /** A part of a path escaped as Go escapes paths: all but letters, digits, -_.~ and $&+,:;=@. */
    private static function escape(string $seg): string
    {
        return strtr(rawurlencode($seg), ['%24' => '$', '%26' => '&', '%2B' => '+', '%2C' => ',', '%3A' => ':', '%3B' => ';', '%3D' => '=', '%40' => '@']);
    }

    /** The pages of entry $e that an entry of path $p shares, with what they take of the path: all of them for the same path, else those of the layouts whose part of the path $p keeps. @return array{0: list<int>, 1: list<int>} */
    private static function shared(RouteEntry $e, string $p): array
    {
        if ($p === self::pathOf($e->loc)) {
            return [$e->pages, $e->layouts];
        }
        $parts = self::pathParts($p); $from = self::pathParts(self::pathOf($e->loc));
        $n = 0;
        while ($n < count($e->pages) && $n < count($e->layouts)) {
            $k = $e->layouts[$n];
            if ($k < 0 || $k > count($parts) || $k > count($from) || array_slice($parts, 0, $k) !== array_slice($from, 0, $k)) {
                break;
            }
            $n++;
        }
        return [array_slice($e->pages, 0, $n), array_slice($e->layouts, 0, $n)];
    }

    /**
     * Goes to the page at $target, after the page shown, whose later pages
     * it forgets: an absolute path, as "/notes/42", or one relative to the
     * page shown, as a link in a web page, as "?tab=info" or "../". Going
     * to the page shown does nothing. The same path with another query is
     * the same page, keeping its state, in another entry of the history,
     * and layouts whose part of the path stays stay too.
     */
    public function push(string $target): void
    {
        $cur = $this->current();
        [$loc, $p] = $this->resolve($target);
        if ($loc === $cur->loc) {
            return;
        }
        $e = new RouteEntry($loc);
        [$e->pages, $e->layouts] = self::shared($cur, $p);
        $this->entries = array_slice($this->entries, 0, $this->at + 1);
        $this->entries[] = $e;
        if (($n = count($this->entries) - self::MAX_HISTORY) > 0) {
            $this->entries = array_slice($this->entries, $n);
        }
        $this->at = count($this->entries) - 1;
        $this->changed();
    }

    /**
     * Shows the page at $target in place of the page shown, in its entry of
     * the history, as after a page moved: back() does not return to the
     * page replaced. The same path with another query is the same page,
     * keeping its state, as a search page following what is typed.
     */
    public function replace(string $target): void
    {
        [$loc, $p] = $this->resolve($target);
        if ($this->entries !== [] && $this->entries[$this->at]->loc === $loc) {
            return;
        }
        $e = new RouteEntry($loc);
        if ($this->entries === []) {
            $this->entries = [$e];
        } else {
            $old = $this->entries[$this->at];
            [$e->pages, $e->layouts] = self::shared($old, $p);
            if ($p === self::pathOf($old->loc)) {
                $e->title = $old->title;
            }
            $this->entries[$this->at] = $e;
        }
        $this->changed();
    }

    /** Shows the page before, if any. */
    public function back(): void { $this->go(-1); }

    /** Shows the page after, which back() left, if any. */
    public function forward(): void { $this->go(1); }

    /** Moves $n pages through the history: back for a negative $n, forward for a positive one, as far as it goes. */
    public function go(int $n): void
    {
        $this->current();
        $at = max(0, min($this->at + $n, count($this->entries) - 1));
        if ($at !== $this->at) {
            $this->at = $at;
            $this->changed();
        }
    }

    public function canGoBack(): bool { return $this->at > 0; }

    public function canGoForward(): bool { return $this->at < count($this->entries) - 1; }

    /** The path of the page shown, as "/notes/42", without its query. */
    public function path(): string { return self::pathOf($this->current()->loc); }

    /** The path and the query of the page shown, as "/notes/42?tab=info", which push() and replace() take, to show the page again when the app starts. */
    public function location(): string { return $this->current()->loc; }

    /** The value of the query parameter $name of the page shown, '' if it has none. */
    public function query(string $name): string { return self::queryOf($this->current()->loc, $name); }

    /** The title of the page shown, as its Route set it in the last frame. */
    public function title(): string { return $this->current()->title; }

    /** Asks for a frame showing the page now current: another pass of the frame being built, or a frame. */
    private function changed(): void
    {
        $this->rt?->changed();
    }

    /** Whether a link's target is a path within the app, as "/notes/42", "edit" or "?tab=info", rather than a URL with a scheme. */
    public static function isPath(string $target): bool
    {
        return preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $target) !== 1 && !str_starts_with($target, '//');
    }

    /** The path of a location, unescaped. */
    public static function pathOf(string $loc): string
    {
        return rawurldecode(self::split($loc)[0]);
    }

    /** The parts of the path of a location, each unescaped, so that an escaped slash stays inside its part. @return list<string> */
    public static function segmentsOf(string $loc): array
    {
        return array_map('rawurldecode', self::pathParts(self::split($loc)[0]));
    }

    /** The value of the query parameter $name of a location, '' if it has none. */
    public static function queryOf(string $loc, string $name): string
    {
        foreach (explode('&', self::split($loc)[1] ?? '') as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($k) === $name) {
                return urldecode($v);
            }
        }
        return '';
    }

    /** The parts of a path between slashes. @return list<string> */
    public static function pathParts(string $p): array
    {
        $p = trim($p, '/');
        return $p === '' ? [] : explode('/', $p);
    }

    /**
     * Builds the page shown with $fn(Route), in a column taking the room it
     * is given, and returns the column. As a page slides in, it builds the
     * one going away too, which takes neither the pointer nor the keyboard.
     */
    public function view(Context $c, \Closure $fn): Node
    {
        $this->rt = $c->runtime;
        $box = $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
        $this->keys($c, $box);
        $this->build($c, $box, $this->view, null, $fn);
        $c->end();
        return $box;
    }

    /** Builds the pages of view $v in $box: the router's, or those inside the page of route $parent, a layout. Router's and Route's own. */
    public function build(Context $c, Node $box, RouteView $v, ?Route $parent, \Closure $fn): void
    {
        $cur = $this->current(); $level = 0;
        if ($parent !== null) {
            $cur = $parent->entry; $level = $parent->level + 1;
        }
        if ($v->shown === null) {
            $v->shown = $cur; // the first page: nothing changes
        } elseif ($this->pageAt($v->shown, $level) !== $this->pageAt($cur, $level)) {
            $this->show($c, $box, $v, $parent, $cur);
        } else {
            $v->shown = $cur;
        }
        $rt = $c->runtime;
        $progress = 1.0;
        if ($v->leaving !== null && $rt !== null) {
            $v->progress->advance($rt->now);
            if ($v->progress->moving || $v->progress->pending) {
                $progress = max(0.0, min($v->progress->value, 1.0));
                $rt->animationFrame();
            } else {
                $v->leaving = null;
            }
        }
        if ($v->leaving === null) {
            $page = $this->page($c, $box, $parent, $cur, $fn, false);
        } elseif ($v->dir === 0) {
            // Across, the page fades in in place of the other, which goes at once: the two would blur into each other.
            $page = $this->page($c, $box, $parent, $cur, $fn, false)->opacity($progress);
        } else {
            // The page going away and the one coming: the one in front in the history slides over the other, which moves less, dimmed.
            $box->clip();
            $bg = $c->background();
            [, , $bw, $bh] = $c->layoutBounds($box);
            $shown = $v->dir > 0 ? $progress : 1 - $progress;
            // The lower page first: it paints under the upper.
            if ($v->dir > 0) {
                $old = $this->page($c, $box, $parent, $v->leaving, $fn, true);
                $page = $this->page($c, $box, $parent, $cur, $fn, false);
                [$upper, $lower] = [$page, $old];
            } else {
                $page = $this->page($c, $box, $parent, $cur, $fn, false);
                $old = $this->page($c, $box, $parent, $v->leaving, $fn, true);
                [$upper, $lower] = [$old, $page];
            }
            $upper->at($bw * (1 - $shown), 0)->size($bw, $bh)->shadow(0, 0, 16, 0, 0x000000, 0.15);
            $upper->color = $bg;
            $lower->at(-0.3 * $shown * $bw, 0)->size($bw, $bh)->drawOver(function (Painter $p, array $r) use ($shown) {
                $p->fill($r, 0x000000, 0, 0.06 * $shown);
            });
            $lower->color = $bg;
        }
        $this->keep($c, $box, $v, $level, $cur);
        if ($v->focusing) {
            $v->focusing = false;
            $this->focusPage($c, $page, $cur);
        }
        if ($v->announcing) {
            $v->announcing = false;
            $c->announce($cur->title);
        }
    }

    /** The id of page $page of $box. */
    private static function pageId(Context $c, Node $box, int $page): int
    {
        return $c->keyedId("$box->id:$page");
    }

    /**
     * Goes back and forward for the keys that do, Cmd+[ and Cmd+] and a
     * mouse's back and forward buttons, with the keyboard focus in the
     * router, or anywhere for the first router of the window, unless it is
     * in another router.
     */
    private function keys(Context $c, Node $box): void
    {
        $rt = $c->runtime;
        if ($rt === null) {
            return;
        }
        $first = $rt->addRouter($box->id);
        $owner = $rt->routerOf($rt->focus);
        if (!($owner === $box->id || ($owner === null && $first))) {
            return;
        }
        $back = $c->shortcut(Key::BRACKET_LEFT, Key::COMMAND) | $c->shortcut(Key::BACK);
        $forward = $c->shortcut(Key::BRACKET_RIGHT, Key::COMMAND) | $c->shortcut(Key::FORWARD);
        if ($back) { $this->back(); }
        if ($forward) { $this->forward(); }
    }

    /** Starts view $v showing entry $cur in place of the entry it shows: the transition, and where the keyboard focus goes. */
    private function show(Context $c, Node $box, RouteView $v, ?Route $parent, RouteEntry $cur): void
    {
        $rt = $c->runtime;
        $old = $v->shown; $level = 0; $offset = 0;
        if ($parent !== null) {
            $level = $parent->level + 1; $offset = $parent->offset + $parent->rest;
        }
        $v->shown = $cur;
        if ($rt === null) {
            return;
        }
        if ($rt->focus !== null && $rt->within($rt->focus, self::pageId($c, $box, $this->pageAt($old, $level)))) {
            $old->focus = $rt->focus;
        }
        // The focus in the page going away, or in a popup that may have chosen the page, goes into the new page; outside, as in a sidebar choosing the pages, it stays.
        if ($rt->focus === null || $rt->rectOf($rt->focus) === null || $rt->within($rt->focus, $box->id) || $rt->inOverlay($rt->focus)) {
            $v->focusing = true; $v->announcing = false;
            $rt->focus = null;
        } else {
            $v->focusing = false; $v->announcing = true;
        }
        $v->leaving = null; $v->dir = 0;
        $rt->forgetColors(); // the page that comes shows its colors at once (mygo's 1549ce9): a node coming back may have been painted for the one that took its place meanwhile, whose color it is not
        if ($this->transition === self::NONE || $rt->instant()) {
            return;
        }
        $v->leaving = $old;
        if ($this->transition === self::AUTO && !$rt->reduceMotion) {
            $from = self::segmentsOf($old->loc); $to = self::segmentsOf($cur->loc);
            $from = array_slice($from, min($offset, count($from))); $to = array_slice($to, min($offset, count($to)));
            if (count($to) > count($from) && array_slice($to, 0, count($from)) === $from) {
                $v->dir = 1;
            } elseif (count($from) > count($to) && array_slice($from, 0, count($to)) === $to) {
                $v->dir = -1;
            }
        }
        $v->progress = new MotionChannel(0.0, $rt->now);
        $v->progress->headFor(1.0, $this->animationFor($v->dir), $rt->now);
    }

    /** How pages go in direction $dir (0 fading). */
    private function animationFor(int $dir): Animation
    {
        return $this->animation ?? new Animation($dir === 0 ? self::FADE_TIME : self::SLIDE_TIME, Easing::easeOut(...));
    }

    /** Builds the page of entry $e with $fn, in $box, inside the page of route $parent: inert while it goes away. */
    private function page(Context $c, Node $box, ?Route $parent, RouteEntry $e, \Closure $fn, bool $leaving): Node
    {
        $level = 0; $offset = 0; $base = [];
        if ($parent !== null) {
            $level = $parent->level + 1; $offset = $parent->offset + $parent->rest;
            $base = [...$parent->base, ...$parent->params];
        }
        $segments = self::segmentsOf($e->loc);
        $route = new Route($this, $e, $level, $offset, array_slice($segments, min($offset, count($segments))), $base);
        $pg = $c->key("$box->id:" . $this->pageAt($e, $level))->column(0, 0, 1);
        $route->page = $pg;
        $pg->page = true;
        if ($leaving) {
            $pg->inert = true;
        } else {
            $pg->focusable(); // the focus goes to it, but Tab goes into it, past it
            $pg->focusTarget = true; $pg->ownRing = true;
            $pg->role(Role::GROUP);
        }
        $c->inRoute($leaving ? $c->router() : $this, $leaving, fn() => $fn($route));
        $c->end();
        $pg->label($e->title);
        return $pg;
    }

    /** Keeps the state of the pages at $level of the entries nearest the one shown, but those built: of a layout's view, those inside the same layout, which keeps them when it goes out of sight. */
    private function keep(Context $c, Node $box, RouteView $v, int $level, RouteEntry $cur): void
    {
        $rt = $c->runtime;
        if ($rt === null) {
            return;
        }
        $n = 0;
        for ($d = 1; $n < self::KEPT_PAGES && ($this->at - $d >= 0 || $this->at + $d < count($this->entries)); $d++) {
            foreach ([$this->at - $d, $this->at + $d] as $i) {
                if ($i < 0 || $i >= count($this->entries) || $n === self::KEPT_PAGES) {
                    continue;
                }
                $e = $this->entries[$i];
                if (count($e->pages) <= $level || ($level > 0 && $e->pages[$level - 1] !== $cur->pages[$level - 1])) {
                    continue;
                }
                $page = $e->pages[$level];
                $id = self::pageId($c, $box, $page);
                if ($page === $cur->pages[$level] || ($v->leaving !== null && $v->dir !== 0 && $page === $this->pageAt($v->leaving, $level)) || $rt->isKept($id)) {
                    continue;
                }
                $rt->keep($id);
                $n++;
            }
        }
    }

    /** Gives the keyboard focus to the node of the page that had it as the page went out of sight, else to the page, unless a node of the page took it as it was built. */
    private function focusPage(Context $c, Node $page, RouteEntry $cur): void
    {
        $rt = $c->runtime;
        if ($rt === null || $rt->focus !== null) {
            return;
        }
        if ($cur->focus !== null && self::builtIn($page, $cur->focus)) {
            $rt->focusOn($cur->focus);
            return;
        }
        $rt->focusOn($page->id);
    }

    /** Whether node $id is in the tree of $n, as built. */
    private static function builtIn(Node $n, int $id): bool
    {
        if ($n->id === $id) {
            return true;
        }
        foreach ($n->kids as $k) {
            if (self::builtIn($k, $id)) {
                return true;
            }
        }
        return false;
    }

    /** The titles, or locations, of the pages $d -1 before the one shown or 1 after it, nearest first, 15 at most, as the menu of a history button lists them (Context::backButton). @return list<string> */
    public function pagesToward(int $d): array
    {
        $this->current();
        $out = [];
        for ($i = $this->at + $d, $n = 1; $i >= 0 && $i < count($this->entries) && $n <= 15; $i += $d, $n++) {
            $out[] = $this->entries[$i]->title !== '' ? $this->entries[$i]->title : $this->entries[$i]->loc;
        }
        return $out;
    }
}
