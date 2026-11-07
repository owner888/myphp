<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * HSplitView, as SwiftUI's on macOS: its views side by side, a divider the
 * user drags between each two, which it keeps. A pane starts as wide as its
 * frame(idealWidth:) (or width:) asks, 200 points otherwise; the last takes
 * the rest of the room.
 */
final class HSplitView extends View
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
              $kept->widths[$i] ??= $panes[$i]->idealWidth() ?? 200.0;
              $c->split($kept->widths[$i], fn() => View::fillPane($c, $paneEnv, $panes[$i]), fn() => $build($i + 1))->grow(1)->alignSelf(Node::STRETCH);
          };
          if ($panes !== []) { $build(0); }
          $build = null;
        $c->end();
        View::takeRoom($wrap, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $wrap;
    }
}
