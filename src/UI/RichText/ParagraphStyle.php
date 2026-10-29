<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Params;

/**
 * The style of a paragraph of a Text (mygo's richtext.ParagraphStyle):
 * what it is (a heading, an item of a list, a quote, a block of code) and
 * how its lines align. Lists are flat, as in Quill and Automerge: an
 * item's indent is its level, and items next to each other make a list.
 *
 * A Text never changes the styles it holds; what it hands out, and what a
 * format's closure gets, is a copy.
 */
final class ParagraphStyle
{
    /** The deepest a paragraph indents. */
    public const MAX_INDENT = 8;

    public function __construct(
        /** The level of a heading, from 1, or 0 for a body paragraph. */
        public int $heading = 0,
        public ListKind $list = ListKind::NoList,
        /** Marks a task done. */
        public bool $checked = false,
        /** How many levels the paragraph is indented: the level of a list's item, from 0. */
        public int $indent = 0,
        /** Aligns its lines: Params::START (the default), CENTER or END. */
        public int $align = Params::START,
        /** Show the paragraph as a quotation, or as code in the monospaced font, its spaces kept. */
        public bool $quote = false,
        public bool $codeBlock = false,
        /**
         * The app's name for the paragraph, the key of what it keeps about
         * it, which the editor does not show (mygo's ID). It stays with the
         * paragraph's text as it is edited (Text::replace): a paragraph
         * split keeps it in its first half (the second when split at its
         * start), paragraphs joined keep the first's, and the paragraphs an
         * edit makes have none, unless the document names them
         * (Document::identifyParagraphs). Undo brings it back with the
         * text, JSON saves it, and the clipboard, Markdown, HTML and RTF
         * leave it out.
         */
        public string $id = '',
    ) {}

    public function equals(ParagraphStyle $b): bool
    {
        return $this === $b || ($this->heading === $b->heading && $this->list === $b->list && $this->checked === $b->checked
            && $this->indent === $b->indent && $this->align === $b->align && $this->quote === $b->quote && $this->codeBlock === $b->codeBlock && $this->id === $b->id);
    }

    /** The style with ID $id: itself when it has it. @internal */
    public function withID(string $id): ParagraphStyle
    {
        if ($id === $this->id) { return $this; }
        $s = clone $this;
        $s->id = $id;
        return $s;
    }

    /** The style with its levels in range, as text pasted or loaded may have any. @internal */
    public function normalized(): ParagraphStyle
    {
        $s = clone $this;
        $s->heading = max(0, min($s->heading, 6));
        $s->indent = max(0, min($s->indent, self::MAX_INDENT));
        if ($s->list !== ListKind::Tasks) { $s->checked = false; }
        return $s->equals($this) ? $this : $s;
    }
}
