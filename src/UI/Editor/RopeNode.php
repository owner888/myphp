<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A node of a rope: a leaf holding up to Rope::CHUNK bytes of text, or a
 * branch of two (mygo's rope.Node). Null is the empty text. Nodes never
 * change once made: an edit makes new ones along its path and shares the
 * rest, so a version of the text is a pointer, kept as long as wanted.
 */
final class RopeNode
{
    public function __construct(
        public readonly ?RopeNode $left,
        public readonly ?RopeNode $right,
        /** only leaves own bytes */
        public readonly string $text,
        public readonly Summary $sum,
        public readonly int $height,
    ) {}

    public function isLeaf(): bool { return $this->left === null; }
}
