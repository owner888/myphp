<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** A point, as Core Graphics' CGPoint: where a drag is. */
final class CGPoint
{
    public function __construct(public readonly float $x = 0, public readonly float $y = 0) {}

    public static function zero(): self { return new self(); }
}
