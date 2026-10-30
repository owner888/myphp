<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use MyPHP\UI\AccessTree;

/**
 * What the protocol asks of the app (mygo's automation driver): the
 * app's windows (AppDriver), or a fake one in the tests. Window 0 is the
 * focused window, else the first. Methods throw a Failure for what they
 * cannot do.
 *
 * @internal
 */
interface Driver
{
    /** @return list<array{id: int, title: string, kind: string, url?: string, bounds: array<string, float>, contentBounds: array<string, float>, focused: bool, visible: bool}> */
    public function windows(): array;

    /**
     * The elements of the window's last frame, and the keys Context::key
     * gave them, by ID.
     * @return array{0: int, 1: AccessTree, 2: array<int, string|int>} the window's ID, its tree and the keys
     */
    public function tree(int $window): array;

    /** Performs an action of assistive technology (an AccessNode constant, from PRESS) on element $id of the window's last frame. */
    public function act(int $window, int $id, int $action, string $text, int $from, int $to): void;

    /** @param list<array> $events as Protocol::events makes them */
    public function input(int $window, array $events): void;

    /** @param list<string> $path the titles of the item of the application menu and of the menus it is in */
    public function menu(int $window, array $path): void;

    /** A PNG of what the window shows. */
    public function capture(int $window): string;

    /** The value of $script, evaluated in the page the window shows. */
    public function eval(int $window, string $script): mixed;

    /**
     * Whether the window asks for no frame it has not drawn, or its page
     * has loaded.
     * @return array{0: int, 1: bool} the window's ID, and whether it is idle
     */
    public function idle(int $window): array;
}
