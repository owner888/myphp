<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** A radius of a SectorMark, as Swift Charts' MarkDimension: a ratio of the pie's radius, the radius less an inset, or fixed points. */
final class MarkDimension
{
    private function __construct(private readonly string $kind, private readonly float $v) {}

    public static function ratio(float $ratio): self { return new self('ratio', $ratio); }
    public static function inset(float $points): self { return new self('inset', $points); }
    public static function fixed(float $points): self { return new self('fixed', $points); }

    /** In points, of a pie of radius $r. @internal */
    public function of(float $r): float
    {
        return max(0.0, match ($this->kind) { 'ratio' => $r * $this->v, 'inset' => $r - $this->v, default => $this->v });
    }
}
