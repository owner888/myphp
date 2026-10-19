<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** A drag over a target, described without reading its bytes (mygo's transfer.Offer). */
final class Offer
{
    /** @param list<string> $formats */
    public function __construct(
        public readonly array $formats = [],
        public readonly int $operations = Operation::NONE,
        /** The effect a modifier key asks for (Option: copy, Command: move), or none. */
        public readonly int $suggested = Operation::NONE,
    ) {}

    /** The first of $formats offered, without reading any. */
    public function preferred(string ...$formats): ?string
    {
        return Data::preferredFormat($this->formats, ...$formats);
    }
}
