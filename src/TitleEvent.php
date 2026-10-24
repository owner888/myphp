<?php
declare(strict_types=1);

namespace MyPHP;

/** Given to a page's 'pageTitleUpdated' listeners as its <title> changes (mygo's TitleEvent); preventDefault() keeps the window's title. */
final class TitleEvent
{
    public bool $prevented = false;

    public function __construct(public readonly string $title) {}

    public function preventDefault(): void
    {
        $this->prevented = true;
    }
}
