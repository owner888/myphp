<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A row of a view that is not a line of its text (mygo's block): the deleted lines of a diff, the spacers aligning its sides, or an annotation. */
final class Block
{
    public const SPACER = 0, DELETED = 1, SEPARATOR = 2, ANNOTATION = 3, HEADER = 4;

    /**
     * @param int $line the line it is attached to
     * @param bool $below below the line, else above it
     * @param int $from with $to: for deleted lines, the lines of the other text it shows; for spacers and separators, what they stand for
     * @param mixed $id identifies an annotation
     * @param int $hunk the diff hunk it belongs to
     * @param bool $sticky keeps the block when its line is hidden, as the separator of the lines a diff collapses does
     */
    public function __construct(
        public int $line, public bool $below, public float $height, public int $kind = self::SPACER,
        public int $from = 0, public int $to = 0, public mixed $id = null, public int $hunk = 0, public bool $sticky = false,
    ) {}

    public function equals(Block $b): bool
    {
        return $this->line === $b->line && $this->below === $b->below && $this->height === $b->height && $this->kind === $b->kind
            && $this->from === $b->from && $this->to === $b->to && $this->id === $b->id && $this->hunk === $b->hunk && $this->sticky === $b->sticky;
    }
}
