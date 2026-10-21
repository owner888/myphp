<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/** The style inlines set as the span builder descends (mygo's spanStyle); a value, compared with key(). */
final class SpanStyle
{
    public function __construct(
        public int $weight = 0,
        public bool $italic = false,
        public bool $strike = false,
        public bool $underline = false,
        public bool $code = false,
        public bool $sub = false,
        public bool $mark = false,
        /** @var ?array{0: int, 1: float} */
        public ?array $color = null,
        public float $size = 0,
        public string $font = '',
        public bool $inLink = false,
        public bool $pendingLink = false,
    ) {}

    public function key(): string { return serialize($this); }
}
