<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** What the parser keeps of a block while it is open (mygo's blockState). @internal */
final class BlockState
{
    /**
     * The offset of the line the block began on, where parsing it anew
     * starts, though definitions may move its start; and $joined, for a
     * table made of a paragraph's last line, is that paragraph, which
     * parsing it anew starts with.
     */
    public int $firstLine = 0;
    public int $joined = 0;
    public int $lastChild = 0;
    public int $prev = 0;
    public int $startLine = 0;
    /** A list's and an item's. */
    public int $markerOffset = 0, $padding = 0;
    /** A fence's, and a table's columns. */
    public int $fenceLength = 0, $fenceOffset = 0, $columns = 0;
    public bool $open = false, $lastLineBlank = false, $lineChecked = false;
    /** For a top-level block, that no block was open as the line it starts on began: parsing from that line gives it anew. */
    public bool $quiet = false;
    public string $fenceChar = '';
    public int $htmlType = 0;
    /** That a paragraph was no table's header, as cmark tries a paragraph once. */
    public bool $tableTried = false;
    /** A math block ending with \] rather than $$, and one that ended. */
    public bool $mathBracket = false, $mathDone = false;

    public function mathClose(): string
    {
        return $this->mathBracket ? '\]' : '$$';
    }
}
