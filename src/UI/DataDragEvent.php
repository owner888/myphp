<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Transfer\{Data, Offer, Operation};

/**
 * A native drag over a window, or dropped on it (mygo's platform
 * DataDragEvent): what it offers, the key of a drag of this process and,
 * for a live one, its value and data. The content answers with the effect
 * and the formats it takes ($operation, $formats); the bytes of those are
 * read once dropped ($data), never while hovering.
 */
final class DataDragEvent
{
    public mixed $local = null;
    public ?Data $data = null;
    public int $operation = Operation::NONE;
    /** @var list<string> */
    public array $formats = [];
    /** Whether the source and its data are this process's own, which a drop takes as they are. */
    public bool $resolved = false;

    public function __construct(
        public Offer $offer,
        public string $session = '',
        /** Whether it holds the system's file list. */
        public bool $hasFiles = false,
    ) {}
}
