<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * ZoomableView (Flutter's InteractiveViewer / SwiftUI zoomable image container):
 * wraps content with double-tap to toggle zoom ($minScale..$maxScale) and pan
 * dragging while zoomed in.
 */
final class ZoomableView extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(
        public readonly float $minScale = 1.0,
        public readonly float $maxScale = 4.0,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $id = $c->upcomingId();
        $kept = $c->runtime?->local($id, fn() => (object)[
            'scale' => 1.0,
            'x' => 0.0,
            'y' => 0.0,
            'baseX' => 0.0,
            'baseY' => 0.0,
        ], \stdClass::class, 'scale') ?? (object)['scale' => 1.0, 'x' => 0.0, 'y' => 0.0, 'baseX' => 0.0, 'baseY' => 0.0];

        $inner = VStack()(...$this->content)
            ->scaleEffect($kept->scale)
            ->offset($kept->x, $kept->y)
            ->onTapGesture(function () use ($kept): void {
                if ($kept->scale > 1.05) {
                    $kept->scale = 1.0;
                    $kept->x = $kept->y = $kept->baseX = $kept->baseY = 0.0;
                } else {
                    $kept->scale = min($this->maxScale, 2.0);
                }
            }, count: 2)
            ->gesture((new DragGesture(minimumDistance: 4))
                ->onChanged(function (DragGestureValue $v) use ($kept): void {
                    if ($kept->scale > 1.01) {
                        $kept->x = $kept->baseX + $v->translation->width;
                        $kept->y = $kept->baseY + $v->translation->height;
                    }
                })
                ->onEnded(function () use ($kept): void {
                    $kept->baseX = $kept->x;
                    $kept->baseY = $kept->y;
                }));

        $n = $c->column(0, 0)->alignItems(Node::CENTER)->justify(Node::CENTER)->clip();
          $inner->render($c, $env->along(Node::COLUMN));
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }
}