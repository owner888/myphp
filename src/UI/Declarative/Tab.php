<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, SVG};

/**
 * A tab of a TabView, as SwiftUI's Tab(_:systemImage:value:) and Tab(_:image:value:): its title, its icon (an SF
 * Symbol's name or an SVG), the value TabView's selection takes for it, its content; badge() a count or a word by its
 * title, in the sidebar.
 */
final class Tab extends View
{
    /** @var list<mixed> */
    public array $content = [];

    public function __construct(public readonly string $title, public readonly ?SVG $image, public readonly mixed $value, public readonly ?string $systemImage = null) {}

    /** Its icon at $size points: the SVG, or a function of the color drawing the SF Symbol, as the engine's tabs and sidebar take them; null for none. @internal */
    public function icon(float $size): SVG|\Closure|null
    {
        if ($this->image !== null) { return $this->image; }
        $name = $this->systemImage;
        return $name === null ? null : static fn(int $rgb) => \MyPHP\UI\Image::symbol($name, $size, $rgb);
    }

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
