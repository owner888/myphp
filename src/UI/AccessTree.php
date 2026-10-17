<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The content of a window for assistive technology, such as screen readers: the elements a user can perceive and act on (mygo's platform.AccessTree). */
final class AccessTree
{
    /** @var list<AccessNode> the elements in tree order: a parent before its children */
    public array $nodes = [];
    /** The ID of the element with the keyboard focus, 0 for none. */
    public int $focus = 0;
    /** @var list<string> texts for assistive technology to read out once, after what it is reading, as a toast: news the focus does not bring */
    public array $announcements = [];
}
