<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Highlights a buffer's text and follows its edits, as Zed's syntax map
 * does (mygo's Highlighter): editors give it the edits of the text, have
 * it work toward the lines they show, and paint the tokens it gives.
 *
 * A highlighter can also know the structure of the text, as a parser's
 * does: it may implement SyntaxSelector, Folder, BracketMatcher and
 * Indenter, which editors then use rather than tokens and indentation.
 */
interface Highlighter
{
    /** Follows an edit of the text: $old and $new are the text before and after it, and $changes what it replaced, in order. @param list<Change> $changes */
    public function edit(Snapshot $old, Snapshot $new, array $changes): void;

    /** Works toward the tokens of the lines up to $last, for at most $budget seconds, and reports whether they are ready. Editors call it before painting lines, and in later frames while it reports false. */
    public function highlight(int $last, float $budget): bool;

    /** Appends the tokens of line $i, whose text is $text, to $dst, and reports whether they are final: false while they are a guess made before highlight's work on them is done, which editors show until it is. @param list<array{0: int, 1: int}> $dst @return array{0: list<array{0: int, 1: int}>, 1: bool} */
    public function tokens(int $i, string $text, array $dst): array;
}
