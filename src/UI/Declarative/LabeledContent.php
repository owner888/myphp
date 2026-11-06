<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/** LabeledContent, as SwiftUI's: a title at the start and its value muted at the end; a cell in a list. */
final class LabeledContent extends View
{
    public function __construct(public readonly string $title, public readonly string $value) {}

    protected function build(Context $c, Env $env): ?Node
    {
        if ($env->inList) { return $c->cell(Localized::string($this->title, $env), $this->value); }
        $n = $c->row(0, 8)->alignItems(Node::CENTER)->alignSelf(Node::STRETCH);
          (new Text($this->title))->render($c, $env->along(Node::ROW));
          $c->spacer();
          (new Text($this->value))->render($c, $env->along(Node::ROW)->with(['color' => new ColorValue(\Color::secondary)]));
        $c->end();
        return $n;
    }

    /**
     * A control $control builds, led by $title as SwiftUI's controls are: in
     * a Form's row the title at its start and the control at its end, or
     * across the rest of the row when $wide (a slider); elsewhere the title
     * and the control side by side; the control alone without a title.
     * Returns the row, or the control. @internal
     * @param Closure(): Node $control
     */
    public static function control(Context $c, Env $env, string $title, Closure $control, bool $wide = false): Node
    {
        if ($title === '' || $env->labelsHidden) {
            $n = $control();
            if ($wide) { View::takeRoom($n, $env->axis, View::FLEX_X); }
            return $n;
        }
        $row = $c->row(0, 8)->alignItems(Node::CENTER);
          (new Text($title))->render($c, $env->along(Node::ROW)->with(['formRow' => false]));
          if (!$wide && $env->formRow) { $c->spacer(); }
          $n = $control();
          if ($wide) { $n->grow = max($n->grow, 1); }
        $c->end();
        if ($env->formRow || $wide) { View::takeRoom($row, $env->axis, View::FLEX_X); }
        return $row;
    }
}
