<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * Backdrop state shared between a page's scroll view and the enclosing
 * NavigationStack / TabView bars on mobile. @internal
 */
final class Backdrop
{
    public function __construct(
        public ?int $color = null,
        public bool $tabBarHidden = false,
        public bool $navBarHidden = false,
    ) {}
}
