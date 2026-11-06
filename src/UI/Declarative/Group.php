<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Group, as SwiftUI's: views together for the modifiers that flow down (font, foregroundStyle…), laid out by the stack around them. */
final class Group extends View
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
        View::renderAll($c, $env, $this->content);
        return null;
    }
}
