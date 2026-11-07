<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** A width and a height, as Core Graphics' CGSize: a drag's translation, a Canvas's size. */
final class CGSize
{
    public function __construct(public readonly float $width = 0, public readonly float $height = 0) {}

    public static function zero(): self { return new self(); }
}
