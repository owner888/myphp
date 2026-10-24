<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Given to a page's 'willNavigate' listeners before it goes to
 * another URL, as a link or the page's script would take it (mygo's
 * NavigateEvent of Page.OnWillNavigate); preventDefault() keeps the page.
 * Loads the app starts (WindowOptions' html and url) are not told.
 */
final class NavigateEvent
{
    public bool $prevented = false;

    public function __construct(
        public readonly string $url,
        /** Whether a user gesture, such as clicking a link, started it. */
        public readonly bool $userInitiated,
    ) {}

    public function preventDefault(): void
    {
        $this->prevented = true;
    }
}
