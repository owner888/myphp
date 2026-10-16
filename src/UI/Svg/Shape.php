<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** A shape: its path, and how it is filled and stroked. A paint is [kind, color, gradient]. */
final class Shape
{
    public const NONE = 0, COLOR = 1, CURRENT = 2, GRADIENT = 3;
    public array $fill = [self::NONE, Style::BLACK, null], $stroke = [self::NONE, Style::BLACK, null];
    public float $fillOpacity = 1, $strokeOpacity = 1;
    public bool $evenOdd = false;
    public float $width = 1, $miter = 4;
    public int $cap = Style::CAP_BUTT, $join = Style::JOIN_MITER;
    /** @var list<float> */
    public array $dashes = [];
    public float $dashOffset = 0;
    public bool $strokeFirst = false;

    public function __construct(public Path $path) {}
}
