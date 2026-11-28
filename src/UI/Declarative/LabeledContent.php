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
        $n = $c->open(new Node(Node::CONTAINER, Node::ROW, 0, 8))->alignItems(Node::CENTER)->alignSelf(Node::STRETCH);
          new Text($this->title)->render($c, $env->along(Node::ROW));
          $c->attach(new Node(Node::CONTAINER, Node::ROW, 0, 0, 0, 0, 1));
          new Text($this->value)->render($c, $env->along(Node::ROW)->with(['color' => new ColorValue(\Color::secondary)]));
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
        $stacked = $env->formRow && ($c->runtime?->touch ?? false) && $env->theme->textScale() >= 1.6; // the accessibility text sizes: the title over its control, as UIKit's cells then
        $row = $stacked
            ? $c->open(new Node(Node::CONTAINER, Node::COLUMN, 0, 4))->alignItems(Node::START)
            : $c->open(new Node(Node::CONTAINER, Node::ROW, 0, 8))->alignItems(Node::CENTER);
          $lbl = (new Text($title))->render($c, $env->along($stacked ? Node::COLUMN : Node::ROW)->with(['formRow' => false]));
          if (!$wide && $env->formRow && !$stacked) { $c->attach(new Node(Node::CONTAINER, Node::ROW, 0, 0, 0, 0, 1)); }
          $n = $control();
          if ($wide) { $n->grow = max($n->grow, 1); }
        $c->end();
        $row->focusTo = $n->focusTo ?? $n;
        if ($lbl !== null && $c->clicked($lbl) && ($rt = $c->runtime) !== null) {
            $to = $row->focusTo;
            if ($to->kind === Node::INPUT || $to->kind === Node::TEXTAREA || $to->focusable) { $rt->focusOn($to->id); }
        }
        if ($env->formRow || $wide) { View::takeRoom($row, $env->axis, View::FLEX_X); }
        return $row;
    }
}
