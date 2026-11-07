<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * ScrollViewReader, as SwiftUI's: the view $content(ScrollViewProxy) makes,
 * whose proxy scrolls the ListView inside to a row by its id — a Top button
 * beside a list: $proxy->scrollTo(0).
 */
final class ScrollViewReader extends View
{
    /** @param Closure(ScrollViewProxy): (View|array|null) $content */
    public function __construct(public readonly Closure $content) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $id = $c->upcomingId();
        $proxy = $c->runtime?->local($id, fn() => new ScrollViewProxy()) ?? new ScrollViewProxy();
        $n = $c->column(0, 0);
          View::renderAll($c, $env->along(Node::COLUMN)->with(['scrollProxy' => $proxy]), ($this->content)($proxy));
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n));
        return $n;
    }
}
