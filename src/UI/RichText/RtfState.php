<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * What a group of RTF sets (mygo's rtfState): the formats of characters,
 * the style of the paragraph, and what the group holds. A group starts as
 * a copy of the one around it; its attributes and style are copied before
 * they change.
 *
 * @internal
 */
final class RtfState
{
    public Attributes $a;
    public ParagraphStyle $para;
    /** A destination whose text is not the document's. */
    public bool $skip = false;
    /** 'inst' in a field's instruction, 'result' in its result. */
    public string $field = '';
    /** The URL of the field the group is in. */
    public string $link = '';
    /** A paragraph's list text, which says its marker. */
    public bool $list = false;
    /** Hidden text, \v. */
    public bool $hide = false;
    /** How many characters follow a \u as its fallback. */
    public int $uc = 1;

    public function __construct()
    {
        $this->a = new Attributes;
        $this->para = new ParagraphStyle;
    }
}
