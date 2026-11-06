<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** DisclosureGroup, as SwiftUI's: $title, and its content shown while expanded: $isExpanded when bound, else kept by the group itself. */
final class DisclosureGroup extends View
{
    private ?bool $isExpanded;
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly string $title, ?bool &$isExpanded = null)
    {
        $this->isExpanded = &$isExpanded;
    }

    /** The content. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $own = $this->isExpanded === null ? ($c->runtime?->local($c->upcomingId(), fn() => (object)['open' => false]) ?? (object)['open' => false]) : null;
        $open = $own?->open ?? $this->isExpanded;
        $n = $c->collapsible(Localized::string($this->title, $env), $open, function () use ($c, $env): void { View::renderAll($c, $env->along(Node::COLUMN), $this->content); });
        $own !== null ? $own->open = $open : $this->isExpanded = $open;
        return $n;
    }
}
