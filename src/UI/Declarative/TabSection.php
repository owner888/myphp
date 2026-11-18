<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * TabSection, as SwiftUI's: tabs grouped under a title in a TabView's sidebar (and in the list a phone's "More" tab
 * shows of the tabs its bar has no room for). In the bar they are tabs like the others.
 */
final class TabSection
{
    /** @var list<Tab> */
    public array $tabs = [];

    public function __construct(public readonly string $title = '') {}

    public function __invoke(Tab ...$tabs): static
    {
        $this->tabs = array_values($tabs);
        return $this;
    }
}
