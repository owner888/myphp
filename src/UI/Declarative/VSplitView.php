<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * VSplitView, as SwiftUI's: its views one above the other, a horizontal
 * divider the user drags between each two, which it keeps. A pane starts as
 * high as its frame(idealHeight:) (or height:) asks, 200 points otherwise;
 * the last takes the rest of the room.
 */
final class VSplitView extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $panes = array_values(array_filter(ListView::flat($this->content), fn($v) => $v !== null));
        $wrap = $c->column(0, 0);
          $kept = $c->runtime?->local($wrap->id, fn() => new SplitKept(), SplitKept::class) ?? new SplitKept();
          $paneEnv = $env->along(Node::COLUMN);
          $build = function (int $i) use (&$build, $c, $panes, $kept, $paneEnv): void {
              if ($i === count($panes) - 1) { View::fillPane($c, $paneEnv, $panes[$i]); return; }
              $kept->widths[$i] ??= $panes[$i]->idealHeight() ?? 200.0;
              $c->split($kept->widths[$i], fn() => View::fillPane($c, $paneEnv, $panes[$i]), fn() => $build($i + 1), true)->grow(1)->alignSelf(Node::STRETCH);
          };
          if ($panes !== []) { $build(0); }
          $build = null;
        $c->end();
        View::takeRoom($wrap, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $wrap;
    }
}