<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Sizes a column or a row of a grid, as mygo's Track: fixed, a share (Fr) of the room the other tracks leave, or as large as its content (FitContent). */
final class Track
{
    public const FIT = 0, FIXED = 1, FR = 2;

    private function __construct(public readonly int $kind, public readonly float $v) {}

    /** A track of $v points. */
    public static function fixed(float $v): self { return new self(self::FIXED, $v); }

    /** A track taking a share $f of the room the other tracks leave, as CSS's minmax(0, <f>fr): tracks of fr(1) share it equally, even where their content is wider; where the grid's size is not set, as large as their content asks, in proportion to $f. */
    public static function fr(float $f = 1): self { return new self(self::FR, max($f, 0)); }

    /** A track as wide, or as high, as its content, as CSS's auto; the tracks beyond those a grid sets (the rows it adds) fit their content. */
    public static function fit(): self { return new self(self::FIT, 0); }
}
