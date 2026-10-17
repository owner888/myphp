<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * An application-owned text-input target (mygo's TextInputClient,
 * Node::handleTextInput). It provides native input methods with document
 * queries, composition and geometry; it owns the text, selection, marked
 * range, editing policy and undo history. All ranges count UTF-16 code
 * units of the current document, including its marked text. A null
 * replacement range means the current marked range, if present,
 * otherwise the selection. setMarkedText's selected range is relative to
 * the new marked text. replaceText commits and clears preedit;
 * unmarkText clears marked status, retaining the document's text.
 */
interface TextInputClient
{
    /** The text of $r, clamped to valid text, and the range it is of. @return array{0: string, 1: TextInputRange} */
    public function textForRange(TextInputRange $r): array;
    public function selection(): TextInputSelection;
    /** The marked range, or null for none. */
    public function markedRange(): ?TextInputRange;
    public function replaceText(?TextInputRange $r, string $s): void;
    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void;
    public function unmarkText(): void;
    /** The element-relative rectangle [x, y, w, h] of $r and the range it is of, or null for unavailable text. @return ?array{0: array{0: float, 1: float, 2: float, 3: float}, 1: TextInputRange} */
    public function boundsForRange(TextInputRange $r): ?array;
    /** The UTF-16 offset at an element-relative point, or null. */
    public function indexForPoint(float $x, float $y): ?int;
}
