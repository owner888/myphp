<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, SVG};

/**
 * A tab of a TabView, as SwiftUI's Tab(_:systemImage:value:) and Tab(_:image:value:): its title, its icon (an SF
 * Symbol's name or an SVG), the value TabView's selection takes for it, its content; badge() a count or a word by its
 * title, in the sidebar. With role: .search, the app's search (Tab(value:role:)): titled Search and shown by a
 * magnifying glass unless it says otherwise, in the bar with the others, as iOS 27's; with role: .prominent, apart at
 * the end of a phone's tab bar, a circle of glass (iOS 27's TabRole.prominent, iOS 26's search tab).
 */
final class Tab extends View
{
    /** @var list<mixed> */
    public array $content = [];

    public readonly string $title;
    public readonly ?string $systemImage;

    public function __construct(string $title, public readonly ?SVG $image, public readonly mixed $value, ?string $systemImage = null, public readonly ?\TabRole $role = null)
    {
        $search = $role === \TabRole::search;
        $this->title = $title === '' && $search ? 'Search' : $title; // the search tab's own, as SwiftUI's
        $this->systemImage = $systemImage ?? ($search && $image === null ? 'magnifyingglass' : null);
    }

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
