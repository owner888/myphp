<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * ControlGroup, as SwiftUI's: groups related controls visually in a compact
 * horizontal bar.
 */
final class ControlGroup extends View
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
        $n = $c->row(2, 2)
            ->alignItems(Node::CENTER)
            ->radius(8)
            ->background($env->theme->surface)
            ->border(1, $env->theme->border);
          if ($this->title !== '') {
              $n->label(Localized::string($this->title, $env));
          }
          View::renderAll($c, $env->along(Node::ROW)->with(['buttonStyle' => \ButtonStyle::borderless]), $this->content);
        $c->end();
        return $n;
    }
}