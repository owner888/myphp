<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/** What Markdown::stream shows at a time (mygo's markdown.RevealUnit). */
enum RevealUnit: int
{
    /** A word and the spaces after it. */
    case ByWord = 0;
    /** A character. */
    case ByCharacter = 1;
    /** A sentence, once it is complete: up to a period, a question or exclamation mark followed by a space, an ideographic full stop, or the end of a line. */
    case BySentence = 2;
    /** A line, once it is complete. */
    case ByLine = 3;
    /** A paragraph, a block between blank lines, once it is complete. */
    case ByParagraph = 4;
    /** Text as it comes, without pacing. */
    case AtOnce = 5;
}
