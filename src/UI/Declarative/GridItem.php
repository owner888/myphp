<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\Track;

/**
 * A column of a LazyVGrid, as SwiftUI's GridItem:
 * - GridItem::adaptive(minimum: 132): as many columns as fit cells $minimum wide
 * - GridItem::flexible(): one column sharing the remaining width equally
 * - GridItem::fixed($size): one column of $size points
 */
final class GridItem
{
    public const ADAPTIVE = 'adaptive';
    public const FLEXIBLE = 'flexible';
    public const FIXED = 'fixed';

    private function __construct(
        public readonly ?float $minimum = null,
        public readonly string $kind = self::ADAPTIVE,
        public readonly ?float $size = null,
    ) {}

    public static function adaptive(float $minimum, ?float $maximum = null): self
    {
        return new self($minimum, self::ADAPTIVE, $maximum);
    }

    public static function flexible(float $minimum = 10, ?float $maximum = null): self
    {
        return new self($minimum, self::FLEXIBLE, $maximum);
    }

    public static function fixed(float $size): self
    {
        return new self($size, self::FIXED, $size);
    }

    /** @internal */
    public function track(): Track
    {
        return $this->kind === self::FIXED ? Track::fixed($this->size ?? 0.0) : Track::fr();
    }
}