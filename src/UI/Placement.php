<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Where along a Timeline a tween or a label starts (mygo's Placement):
 * relative to the tween before it, at a label, or at a time, as GSAP's
 * position parameter. With no placement, what comes next follows the
 * tween before it at once, as afterPrevious(0). Nothing starts before the
 * timeline does: a placement before its start is at its start.
 *
 *     $tl->tween(new Tween(...), Placement::afterPrevious(-0.15)); // overlaps it by 150 ms
 *     $tl->tween(new Tween(...), Placement::withPrevious(0.1));
 *     $tl->tween(new Tween(...), Placement::atLabel('intro', 0.2));
 *     $tl->tween(new Tween(...), Placement::atTime(0.5));
 */
final class Placement
{
    public const AFTER = 0, WITH = 1, AT = 2, LABEL = 3;

    /** What it measures from, the offset in nanoseconds, and the label. */
    private function __construct(public readonly int $kind, public readonly int $at, public readonly string $label = '') {}

    /** $offset seconds after the tween before it ends, or with a negative offset that long before, to overlap it, as GSAP's "+=" and "-=". */
    public static function afterPrevious(float $offset): self { return new self(self::AFTER, Motion::ns($offset)); }

    /** $offset seconds after the tween before it starts, to move something along with it, as GSAP's "<". */
    public static function withPrevious(float $offset): self { return new self(self::WITH, Motion::ns($offset)); }

    /** At $t seconds of the timeline. */
    public static function atTime(float $t): self { return new self(self::AT, Motion::ns($t)); }

    /**
     * $offset seconds after a label of the timeline, as rows staggered from
     * one label each start $i times a step after it:
     *
     *     $rows[$i] = $tl->tween(new Tween(0, 1, 0.2), Placement::atLabel('rows', $i * 0.04));
     */
    public static function atLabel(string $name, float $offset): self { return new self(self::LABEL, Motion::ns($offset), $name); }
}
