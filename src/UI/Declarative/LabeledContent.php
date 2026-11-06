<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** LabeledContent, as SwiftUI's: a title at the start and its value muted at the end; a cell in a list. */
final class LabeledContent extends View
{
    public function __construct(public readonly string $title, public readonly string $value) {}

    protected function build(Context $c, Env $env): ?Node
    {
        if ($env->inList) { return $c->cell($this->title, $this->value); }
        $n = $c->row(0, 8)->alignItems(Node::CENTER)->alignSelf(Node::STRETCH);
          (new Text($this->title))->render($c, $env->along(Node::ROW));
          $c->spacer();
          (new Text($this->value))->render($c, $env->along(Node::ROW)->with(['color' => new ColorValue(\Color::secondary)]));
        $c->end();
        return $n;
    }
}
