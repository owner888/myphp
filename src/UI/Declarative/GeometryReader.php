<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Painter};

/**
 * GeometryReader, as SwiftUI's: takes the room it is given and builds the
 * view $content(GeometryProxy) makes of it — its size as the last frame
 * laid it out (the first frame, the window's), and, as myphp tells, the
 * room the window's controls take in a window with a hidden title bar.
 */
final class GeometryReader extends View
{
    /** @param Closure(GeometryProxy): (View|array|null) $content */
    public function __construct(public readonly Closure $content) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $id = $c->upcomingId();
        $rect = $c->runtime?->rectOf($id);
        $size = $rect === null ? $c->size() : [$rect[2], $rect[3]];
        $n = $c->column(0, 0);
          View::renderAll($c, $env->along(Node::COLUMN), ($this->content)(new GeometryProxy($size, $c->titleBar())));
        $c->end();
        // Built with the size of the frame before: laid out to another (the window resized), it builds again with it.
        $n->draw(static function (Painter $p, array $r) use ($size): void {
            if (abs($r[2] - $size[0]) > 0.5 || abs($r[3] - $size[1]) > 0.5) { $p->animationFrame(); }
        });
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }
}

