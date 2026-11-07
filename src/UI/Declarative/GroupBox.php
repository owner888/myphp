<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Font, Node};

/** GroupBox, as SwiftUI's: its views in a rounded box on the surface color, leading, its title above it; as wide as it is given. */
final class GroupBox extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly string $title = '') {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $t = $env->theme;
        $n = $c->column(0, 6);
          if ($this->title !== '') { $c->text(Localized::string($this->title, $env), style: Font::BOLD); }
          $box = $c->column(10, 6, 0, $t->surface)->radius(8)->border(1, $t->border)->alignSelf(Node::STRETCH)->alignItems(Node::START);
            View::renderAll($c, $env->along(Node::COLUMN), $this->content);
          $c->end();
        $c->end();
        if (View::flexOfKids($box) & View::FLEX_Y) { $box->grow = max($box->grow, 1); }
        View::takeRoom($n, $env->axis, View::FLEX_X | (View::flexOfKids($box) & View::FLEX_Y));
        return $n;
    }
}
