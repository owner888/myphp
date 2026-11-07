<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * A value a chart plots, and what it is (its label): PlottableValue::value('Month', 'Mar'),
 * as Swift Charts' .value(_:_:). A string is a category, along a band; a number, along a
 * linear scale; a DateTimeInterface, along time. The label names the series in a legend
 * and the value for assistive technology.
 */
final class PlottableValue
{
    private function __construct(public readonly string $label, public readonly string|int|float|\DateTimeInterface $value) {}

    public static function value(string $label, string|int|float|\DateTimeInterface $value): self { return new self($label, $value); }

    /** $v as a PlottableValue: itself, or a bare value with no label. @internal */
    public static function of(self|string|int|float|\DateTimeInterface|null $v): ?self { return $v === null || $v instanceof self ? $v : new self('', $v); }
}
