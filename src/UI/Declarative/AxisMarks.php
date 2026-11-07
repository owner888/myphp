<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * The marks of a chart's axis, as Swift Charts' AxisMarks: about $desiredCount ticks, their labels as $format
 * says (fn(float|DateTimeImmutable|string $v): string), on the side $position names (the y axis's labels
 * trailing by default, as Swift Charts'); $values: exactly those ticks.
 */
final class AxisMarks
{
    /** @param list<mixed>|null $values */
    public function __construct(
        public readonly ?\AxisMarkPosition $position = null,
        public readonly ?int $desiredCount = null,
        public readonly ?array $values = null,
        public readonly ?Closure $format = null,
    ) {}
}
