<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * An inline of a block's content (mygo's parser.Inline): inlines are in
 * document order, an inline's children right after it, up to its $next.
 */
final class Inline
{
    // Kinds of inlines.
    /** Literal text, in $text. */
    public const TEXT = 0;
    /** A line ending within a paragraph, shown as a space. */
    public const SOFT_BREAK = 1;
    /** A hard line break. */
    public const LINE_BREAK = 2;
    /** A code span, its text in $text. */
    public const CODE = 3;
    public const EMPHASIS = 4;
    public const STRONG = 5;
    public const STRIKETHROUGH = 6;
    /** The inlines of its text, to $dest, titled $title. */
    public const LINK = 7;
    /** The inlines of its description, from $dest. */
    public const IMAGE = 8;
    /** Raw HTML, in $text. */
    public const HTML = 9;
    /** TeX, in $text, $display or within the line. */
    public const MATH = 10;
    /** A reference to the footnote labelled $text. */
    public const FOOTNOTE_REF = 11;

    private const NAMES = ['text', 'soft break', 'line break', 'code', 'emphasis', 'strong', 'strikethrough', 'link', 'image', 'HTML', 'math', 'footnote reference'];

    public function __construct(
        public int $kind,
        /** The literal of text, code, HTML and math, and the label of a footnote reference. */
        public string $text = '',
        /** A link's or an image's. */
        public string $dest = '',
        public string $title = '',
        /** The offsets of the inline's bytes in the source. */
        public int $start = 0,
        public int $end = 0,
        /** The index after the inline and its children. */
        public int $next = 0,
        /** A link written as its address, as <https://…> or www.example.com. */
        public bool $auto = false,
        /** Math shown on its own lines, between $$ or \[ \]. */
        public bool $display = false,
        /** While the document is streaming, a link or an image whose destination is still being written. */
        public bool $pending = false,
    ) {}

    /** The name of kind $k. */
    public static function kindName(int $k): string
    {
        return self::NAMES[$k] ?? 'unknown';
    }

    /**
     * The text of inlines without their markup (mygo's PlainText): the
     * text of links and emphasis, code as its text, line breaks as spaces.
     *
     * @param list<Inline> $in
     */
    public static function plainText(array $in): string
    {
        $b = '';
        foreach ($in as $n) {
            $b .= match ($n->kind) {
                self::TEXT, self::CODE, self::HTML, self::MATH => $n->text,
                self::SOFT_BREAK, self::LINE_BREAK => ' ',
                self::FOOTNOTE_REF => '[^' . $n->text . ']',
                default => '',
            };
        }
        return $b;
    }
}
