<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/**
 * How Markdown::stream shows text as it comes (mygo's Reveal): rather than
 * in the bursts a network delivers, at an even pace that catches up with
 * what came within $catchUp, a unit at a time, each fading in, as the chat
 * apps show replies.
 */
final class Reveal
{
    // What shows at a time (mygo's RevealUnit).
    /** A word and the spaces after it. */
    public const BY_WORD = 0;
    /** A character. */
    public const BY_CHARACTER = 1;
    /** A sentence, once it is complete: up to a period, a question or exclamation mark followed by a space, an ideographic full stop, or the end of a line. */
    public const BY_SENTENCE = 2;
    /** A line, once it is complete. */
    public const BY_LINE = 3;
    /** A paragraph, a block between blank lines, once it is complete. */
    public const BY_PARAGRAPH = 4;
    /** Text as it comes, without pacing. */
    public const AT_ONCE = 5;

    public function __construct(
        public int $unit = self::BY_WORD,
        /** How long text that came waits at most before it shows, in seconds: 0.25 when 0. Text comes in faster the more there is waiting. */
        public float $catchUp = 0,
        /** How long text takes to fade in, in seconds: 0.3 when 0, none when negative, nor when the desktop asks for less motion. */
        public float $fade = 0,
    ) {}
}
