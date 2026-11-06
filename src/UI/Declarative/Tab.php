<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, SVG};

/** A tab of a TabView, as SwiftUI's Tab(_:image:value:): its title, its icon, the value TabView's selection takes for it, its content. */
final class Tab extends View
{
    /** @var list<mixed> */
    public array $content = [];

    public function __construct(public readonly string $title, public readonly SVG $image, public readonly mixed $value) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        throw new \LogicException('a Tab goes in a TabView');
    }
}
