<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Where a line's parts are (mygo's lineGeometry): the top of its blocks above, of its rows and of its blocks below, and its bottom. */
final class LineGeometry
{
    public function __construct(public float $top, public float $rows, public float $below, public float $bottom, public bool $hidden) {}
}
