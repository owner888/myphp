<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * A block of a document (mygo's parser.Block): blocks are in document
 * order, a block's children right after it, up to its $next.
 */
final class Block
{
    // Kinds of blocks.
    /** The top-level blocks' holder: block 0 of a document. */
    public const ROOT = 0;
    public const PARAGRAPH = 1;
    /** A heading has a $level from 1 to 6. */
    public const HEADING = 2;
    public const THEMATIC_BREAK = 3;
    /** Fenced or indented code, whose info() names the language of a fence. */
    public const CODE_BLOCK = 4;
    /** Raw HTML, whose lines are its content. */
    public const HTML_BLOCK = 5;
    /** A block quote, holding blocks. */
    public const QUOTE = 6;
    /** Items, $ordered or not, $tight or not. */
    public const LIST = 7;
    /** An item of a list, holding blocks, with a $task state. */
    public const ITEM = 8;
    /** Rows, the first its $header, whose cells align as $align says. */
    public const TABLE = 9;
    public const TABLE_ROW = 10;
    /** Inline content. */
    public const TABLE_CELL = 11;
    /** The blocks of a footnote, label() its label. */
    public const FOOTNOTE_DEFINITION = 12;
    /** Display math, whose lines are its TeX. */
    public const MATH_BLOCK = 13;

    private const NAMES = ['root', 'paragraph', 'heading', 'thematic break', 'code block', 'HTML block', 'quote', 'list', 'item', 'table', 'table row', 'table cell', 'footnote definition', 'math block'];

    // The check box of a task list item.
    public const NO_TASK = 0, UNCHECKED = 1, CHECKED = 2;
    // How a table's column aligns its cells.
    public const ALIGN_NONE = 0, ALIGN_LEFT = 1, ALIGN_CENTER = 2, ALIGN_RIGHT = 3;

    public int $kind;
    /** The index of the block holding it, -1 for the root. */
    public int $parent;
    /** The index after the block and its descendants. */
    public int $next;
    /** The offsets of the block's bytes in the source, from its first character to the end of its last line. */
    public int $start, $end;
    /** That the block is still open at the end of the source, so that more text can continue it: a fence without its closing fence, or the paragraph being written. */
    public bool $open = false;
    /** A heading's, from 1 to 6. */
    public int $level = 0;
    /**
     * A list's: $ordered, $number (an ordered list's first), $marker (*, -
     * or + for a bullet list, . or ) after an ordered list's numbers) and
     * $tight (no blank lines between its items and their blocks).
     */
    public bool $ordered = false;
    public int $number = 0;
    public string $marker = '';
    public bool $tight = false;
    /** An item's check box. */
    public int $task = self::NO_TASK;
    /** A fenced code block, not an indented one. */
    public bool $fenced = false;
    /** A table's header row. */
    public bool $header = false;
    /** @var list<int> the alignment of a table's columns */
    public array $align = [];

    // What the parser and the inline parser keep. @internal
    /** A fenced code block's info string or a footnote's label. */
    public string $info = '';
    public BlockState $parse;
    /** The block's content lines in the document's segs, and how many lines they held as the block began. */
    public int $segA = 0, $segB = 0, $segMark = 0;
    public string $text = '';
    /** The source offset of $text's first byte when it is a slice of the source, else -1. */
    public int $textS = -1;
    /** @var ?list<Inline> */
    public ?array $inl = null;
    public bool $inlOK = false;
    /** @var ?list<string> the labels its inlines looked up as references, to parse them anew when a definition of one comes or goes */
    public ?array $labels = null;
    public int $rev = 0;
    /** Inlines parsed completed, as the end of a streaming document's. */
    public bool $healed = false;

    public function __construct(int $kind, int $parent, int $next, int $start = 0, int $end = 0)
    {
        $this->kind = $kind; $this->parent = $parent; $this->next = $next; $this->start = $start; $this->end = $end;
        $this->parse = new BlockState();
    }

    public function __clone()
    {
        $this->parse = clone $this->parse;
    }

    /** The name of kind $k. */
    public static function kindName(int $k): string
    {
        return self::NAMES[$k] ?? 'unknown';
    }

    /** A fenced code block's info string, unescaped, its language first (see language()). */
    public function info(): string
    {
        return $this->kind === self::CODE_BLOCK ? $this->info : '';
    }

    /** A footnote definition's label. */
    public function label(): string
    {
        return $this->kind === self::FOOTNOTE_DEFINITION ? $this->info : '';
    }

    /** The language of a fenced code block, the first word of its info string. */
    public function language(): string
    {
        $s = $this->info();
        $i = strcspn($s, " \t");
        return substr($s, 0, $i);
    }

    /**
     * Changes when the block's inlines are parsed anew, as when a link
     * reference defined later in the document resolves its links: views
     * keep what they made of a block while it stays the same.
     */
    public function revision(): int { return $this->rev; }

    /** Whether the block's inlines were completed as the tail of a streaming document: their closers are not in the source, and change as it grows. */
    public function healed(): bool { return $this->healed; }
}
