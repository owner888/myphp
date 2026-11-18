<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/** Group, as SwiftUI's: views together for the modifiers that flow down (font, foregroundStyle…), laid out by the stack around them. */
final class Group extends View
{
    /** @var list<mixed> */
    private array $content = [];
    /** Where a ToolbarItem goes in a page's bar: 'leading' (also topBarLeading, navigationBarLeading, cancellationAction) at its start, any other at its end. */
    public string $placement = 'automatic';

    public function isLeading(): bool
    {
        return in_array($this->placement, ['leading', 'topBarLeading', 'navigationBarLeading', 'cancellationAction'], true);
    }

    public function __invoke(View|Closure|array|null ...$content): static
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
