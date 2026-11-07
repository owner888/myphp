<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Painter};

/**
 * Canvas, as SwiftUI's: what $renderer draws in a GraphicsContext, of the
 * size it is given (a CGSize), every time the view is drawn; it takes the
 * room it is given. Draw it anew each frame inside a TimelineView.
 *
 *     Canvas(fn(GraphicsContext $ctx, CGSize $size) => $ctx->fill(Path::ellipse(0, 0, $size->width, $size->height), with: Color::blue))
 */
final class Canvas extends View
{
    public function __construct(private readonly Closure $renderer) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $renderer = $this->renderer;
        $n = $c->column()->draw(static function (Painter $p, array $r) use ($renderer, $env): void {
            $renderer(new GraphicsContext($p, $env, $r), new CGSize($r[2], $r[3]));
        });
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }
}
