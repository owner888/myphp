<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * What an editor made of a paragraph (mygo's richtext paraView): its
 * layout, for the paragraph, style and composition it was made of, and its
 * height with the room around it, 0 until laid out.
 *
 * @internal
 */
final class ParaView
{
    public ?ParaLayout $layout = null;
    /** The list marker painted beside the paragraph, laid out. */
    public string $marker = '';
    public ?ParaLayout $markerLayout = null;
    /** The paragraph the layout was made of: it is never changed, so the same object is the same runs and style. */
    public ?RichPara $para = null;
    public string $compose = '';
    public int $at = 0;
    public float $h = 0;
    /** The space above and below it, in $h. */
    public float $space = 0;
    /** How far its first line goes in (Theme::$firstLineIndent), as its layout indents it. */
    public float $indent = 0;
}
