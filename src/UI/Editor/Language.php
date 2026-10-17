<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use Closure;

/**
 * What a Buffer knows of the language of its text (mygo's Language):
 * how to highlight it, comment it, match its brackets and indent it.
 */
final class Language
{
    /**
     * @param list<string> $extensions with $filenames, find the language of a file, as ".go" or "Makefile" (Languages::for)
     * @param list<string> $filenames
     * @param string $lineComment starts a comment to the end of the line, as "//"
     * @param ?array{0: string, 1: string} $blockComment encloses one, as C's slash-star and star-slash; toggling comments uses the first, else the second
     * @param list<BracketPair> $brackets matched, highlighted at the caret, and indent the lines between them
     * @param list<BracketPair> $autoClose closed as they are typed, and wrap the selection
     * @param ?string $increaseIndent a regular expression (PCRE, with delimiters) matching a line after which the next is indented one more level, as one ending in { or :
     * @param ?string $decreaseIndent one matching a line that is one level less than the one before, as one starting with end, which typing outdents; without them, the brackets decide, and a closing bracket typed first on its line takes its opener's indentation
     * @param string $wordChars characters other than letters, digits and _ that words hold, as - in CSS
     * @param int $tabSize with $hardTabs, the indentation the language's files usually have, which editors take unless set: Go's tabs
     * @param ?Tokenizer $tokenizer highlights the text a line at a time, as TextMate grammars do (TokenHighlighter): the default highlighter, which $highlighter replaces
     * @param ?Closure(Snapshot): Highlighter $highlighter makes the highlighter of a buffer of the language, for highlighting of its own, as a parser's; when null, $tokenizer highlights
     */
    public function __construct(
        public readonly string $name, public readonly array $extensions = [], public readonly array $filenames = [],
        public readonly string $lineComment = '', public readonly ?array $blockComment = null,
        public readonly array $brackets = [], public readonly array $autoClose = [],
        public readonly ?string $increaseIndent = null, public readonly ?string $decreaseIndent = null,
        public readonly string $wordChars = '', public readonly int $tabSize = 0, public readonly bool $hardTabs = false,
        public ?Tokenizer $tokenizer = null, public readonly ?Closure $highlighter = null,
    ) {}

    /** The highlighter of a buffer of this language with $text: its own, or one of its tokenizer; null for plain text. */
    public function newHighlighter(Snapshot $text): ?Highlighter
    {
        if ($this->highlighter !== null) { return ($this->highlighter)($text); }
        return $this->tokenizer === null ? null : new TokenHighlighter($this->tokenizer, $text);
    }
}
