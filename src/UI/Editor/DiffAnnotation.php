<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Room under a line of a side of a diff for what DiffState::$renderAnnotation builds there, $height points high; $key identifies it among the others (mygo's DiffAnnotation). */
final class DiffAnnotation
{
    public function __construct(public DiffSide $side, public int $line, public float $height, public mixed $key = null) {}
}
