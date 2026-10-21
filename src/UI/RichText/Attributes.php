<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * The attributes of a run of a Text (mygo's richtext.Attributes). What
 * they leave unset takes the style of the editor showing the text (its
 * font, size and color), and italic, underline, strikethrough and code
 * only turn those on. Runs with equal attributes (equals()) are one.
 *
 * A Text never changes the Attributes it holds; what it hands out, and
 * what a format's closure gets, is a copy.
 */
final class Attributes
{
    /**
     * @param ?array{0: int, 1: float} $color [0xRRGGBB, alpha]
     * @param ?array{0: int, 1: float} $background
     */
    public function __construct(
        /** A family, as for Node::font(). */
        public string $font = '',
        /** In points. */
        public float $size = 0,
        /** From 100 (thin) to 900 (black): 700 is bold; 0 the editor's. */
        public int $weight = 0,
        public bool $italic = false,
        public bool $underline = false,
        public bool $strikethrough = false,
        /** Colors the text, the background highlights it. */
        public ?array $color = null,
        public ?array $background = null,
        /** Shows inline code, in the editor's monospaced font. Typing at its edges does not continue it. */
        public bool $code = false,
        /** A URL the run links to. Typing at its edges does not continue it, as in NSTextView. */
        public string $link = '',
        /** The app's own mark over the run, as a mention or a comment's ID: typing at its edges does not continue it, and the app styles it with EditorOptions::$tagStyle. */
        public string $tag = '',
        /** What an object replacement character (U+FFFC) shows; only that character takes it. */
        public ?Attachment $attachment = null,
    ) {}

    /** Whether the run is bold: its weight is 600 or more. */
    public function bold(): bool { return $this->weight >= 600; }

    /** Whether $b has the same attributes; attachments are the same object. */
    public function equals(Attributes $b): bool
    {
        return $this === $b || ($this->weight === $b->weight && $this->italic === $b->italic && $this->underline === $b->underline
            && $this->strikethrough === $b->strikethrough && $this->code === $b->code && $this->size === $b->size
            && $this->font === $b->font && $this->link === $b->link && $this->tag === $b->tag
            && $this->color === $b->color && $this->background === $b->background && $this->attachment === $b->attachment);
    }

    /**
     * What typing right after a run with the attributes gets: links, code
     * and tags hold only what they hold, as NSTextView and the expand
     * rules of Peritext and Loro leave them; every other attribute carries
     * on to what is typed after it.
     *
     * @internal
     */
    public function extending(): Attributes
    {
        if ($this->link === '' && !$this->code && $this->tag === '' && $this->attachment === null) { return $this; }
        $a = clone $this;
        $a->link = ''; $a->code = false; $a->tag = ''; $a->attachment = null;
        return $a;
    }

    /** @internal the attributes without an attachment */
    public function plain(): Attributes
    {
        if ($this->attachment === null) { return $this; }
        $a = clone $this;
        $a->attachment = null;
        return $a;
    }
}
