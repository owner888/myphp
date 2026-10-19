<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A page a Router's view builds (mygo's Route): match() tells its path apart and param() reads the parts of the path the pattern matched. */
final class Route
{
    /**
     * $segments are the parts of the path, unescaped, from part $offset of
     * the whole path for the pages of a Route::view; $params are what the
     * last pattern matched took, $base what the patterns of the layouts
     * around took, and $rest where its {name...} started, -1 without one.
     * @param list<string> $segments @param list<array{0: string, 1: string}> $base
     */
    public function __construct(
        public readonly Router $router,
        public readonly RouteEntry $entry,
        public readonly int $level = 0,
        public readonly int $offset = 0,
        public readonly array $segments = [],
        public readonly array $base = [],
    ) {}

    /** @var list<array{0: string, 1: string}> */
    public array $params = [];
    public int $rest = -1;
    /** The node holding the page, a column filling the router. */
    public ?Node $page = null;

    /** The path of the page, as "/notes/42"; in a Route::view, the part of the path its layout leaves, as "/fonts" of "/settings/fonts". */
    public function path(): string
    {
        return '/' . implode('/', $this->segments);
    }

    /**
     * Whether the page's path matches $pattern, whose parts between slashes
     * are either the same as the path's or wildcards: {name} takes any one
     * part, and {name...}, at the end, the rest of the path, which may be
     * empty; param() returns what they took. A slash at the end of the path
     * or the pattern makes no difference.
     *
     *     $r->match('/notes/{id}')       // "/notes/42"
     *     $r->match('/files/{path...}')  // "/files", "/files/docs/a.txt"
     */
    public function match(string $pattern): bool
    {
        $parts = Router::pathParts($pattern);
        $params = [];
        $n = count($this->segments);
        foreach ($parts as $i => $p) {
            if (strlen($p) < 2 || $p[0] !== '{' || $p[strlen($p) - 1] !== '}') {
                if ($i >= $n || $this->segments[$i] !== $p) {
                    return false;
                }
                continue;
            }
            $name = substr($p, 1, -1);
            if (str_ends_with($name, '...')) {
                if ($i !== count($parts) - 1) {
                    throw new \LogicException("{" . $name . "} does not end the pattern $pattern");
                }
                $params[] = [substr($name, 0, -3), implode('/', array_slice($this->segments, min($i, $n)))];
                $this->params = $params; $this->rest = min($i, $n);
                return true;
            }
            if ($i >= $n) {
                return false;
            }
            $params[] = [$name, $this->segments[$i]];
        }
        if (count($parts) !== $n) {
            return false;
        }
        $this->params = $params; $this->rest = -1;
        return true;
    }

    /** What the wildcard $name of the pattern match() last matched took, unescaped, or of a layout's around; '' if none has it. */
    public function param(string $name): string
    {
        foreach ([$this->params, $this->base] as $ps) {
            for ($i = count($ps) - 1; $i >= 0; $i--) {
                if ($ps[$i][0] === $name) {
                    return $ps[$i][1];
                }
            }
        }
        return '';
    }

    /** The value of the query parameter $name, '' if the page has none. */
    public function query(string $name): string
    {
        return Router::queryOf($this->entry->loc, $name);
    }

    /** Names the page: screen readers read it as the page shows, and the history buttons list it; inside a layout, the title of the page inside names the entry. */
    public function title(string $title): void
    {
        $this->entry->title = $title;
    }

    /**
     * Builds the pages of the rest of the path, which the {name...} wildcard
     * of the pattern match() matched last took, with $fn(Route), in a column
     * taking the room it is given, and returns the column: the page holding
     * it is a layout, as a page of settings with a list of its sections
     * around the section shown. The layout stays as the pages inside change,
     * keeping its state, and they slide or fade in its place, keep their
     * state in the history and take the focus as the router's pages do.
     * Their patterns match the rest of the path, and param() returns the
     * wildcards of the layout's pattern too.
     */
    public function view(Context $c, \Closure $fn): Node
    {
        if ($this->rest < 0) {
            throw new \LogicException('Route::view with no {name...} in the pattern matched');
        }
        $box = $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
        $e = $this->entry;
        while (count($e->layouts) <= $this->level) {
            $e->layouts[] = -1;
        }
        $e->layouts[$this->level] = $this->offset + $this->rest;
        $v = $c->runtime?->local($box->id, fn() => new RouteView) ?? new RouteView;
        $this->router->build($c, $box, $v, $this, $fn);
        $c->end();
        return $box;
    }
}
