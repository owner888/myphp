<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, SVG};

/** Label, as SwiftUI's: an icon (a system symbol, or an SVG) and a title, side by side. */
final class Label extends View
{
    public function __construct(public readonly string $title, public readonly ?string $systemImage = null, public readonly ?SVG $image = null) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->row(0, 6)->alignItems(Node::CENTER);
          if ($this->systemImage !== null) { (new ImageView(null, $this->systemImage))->render($c, $env->along(Node::ROW)); }
          if ($this->image !== null) { $size = $env->theme->fontSize * 1.2; $c->icon($this->image, $size, $env->color?->rgb($env) ?? $env->theme->text); }
          (new Text($this->title))->render($c, $env->along(Node::ROW));
        $c->end();
        $n->label($this->title);
        return $n;
    }
}
