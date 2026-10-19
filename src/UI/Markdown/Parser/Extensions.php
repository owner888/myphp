<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** The syntax a Document reads beyond CommonMark (mygo's parser.Extensions), a bitmask. */
final class Extensions
{
    /** GitHub's tables, of cells between pipes. */
    public const TABLES = 1;
    /** List items starting with [ ] or [x]. */
    public const TASK_LISTS = 2;
    /** Text between one or two tildes. */
    public const STRIKETHROUGH = 4;
    /** Links without angle brackets: www.example.com, https://example.com and someone@example.com. */
    public const AUTOLINKS = 8;
    /** References as [^1], defined by [^1]: lines. */
    public const FOOTNOTES = 16;
    /** TeX between $$ and $$, \[ and \], or \( and \), in blocks of their own or within text. */
    public const MATH = 32;
    /**
     * TeX between single dollar signs, as $x^2$, except where the dollars
     * read as amounts of money: not after an opening dollar followed by a
     * space or a digit, nor before a closing one following a space or
     * followed by a digit. It needs MATH.
     */
    public const DOLLAR_MATH = 64;
    /** GitHub Flavored Markdown. */
    public const GFM = self::TABLES | self::TASK_LISTS | self::STRIKETHROUGH | self::AUTOLINKS;
    /** GitHub Flavored Markdown with footnotes and math, as language models write it. */
    public const DEFAULT = self::GFM | self::FOOTNOTES | self::MATH;
}
