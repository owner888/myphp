<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** What a GeometryReader gives its content: its size, and the title bar's controls' room (Context::titleBar). */
final class GeometryProxy
{
    /** @param array{0: float, 1: float} $size @param array{height: float, left: float, right: float} $titleBar */
    public function __construct(public readonly array $size, public readonly array $titleBar) {}
}
