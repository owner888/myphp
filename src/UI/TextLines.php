<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Where a text area laid out the lines of its text, kept in the app's
 * state with Node::trackLines, for an app that paints beside them: line
 * numbers by the first row of each line where the text wraps, or marks on
 * a line (mygo's TextLines). The lines are the text's, between newlines;
 * a line the area wraps takes several rows. Between a frame's build and
 * its paint they are the last frame's, at the area's last width; before
 * the area is first painted, each line is one row and every top is 0.
 */
final class TextLines
{
    /** The area's text and its layout, set by trackLines. */
    public ?TextState $state = null;

    /** How many lines the text has. */
    public function count(): int
    {
        return $this->state === null ? 0 : substr_count($this->state->text, "\n") + 1;
    }

    /** The top of line $i in the area's content, from the top of its first line: below the padding, before the area scrolls. */
    public function top(int $i): float
    {
        $l = $this->state?->lastLayout();
        if ($l === null) {
            return 0.0;
        }
        return $this->firstRow($l, max(0, min($i, $this->count()))) * $l->lineHeight;
    }

    /** The height of the text: the top of the line after the last. */
    public function height(): float
    {
        return $this->top($this->count());
    }

    /** The line at $y in the content, as top() measures it: the first above it, the last past the end. */
    public function at(float $y): int
    {
        $l = $this->state?->lastLayout();
        if ($l === null) {
            return 0;
        }
        $row = max(0, min((int)floor(max($y, 0.0) / $l->lineHeight), count($l->lines) - 1));
        return substr_count($l->text, "\n", 0, $l->lines[$row][0]);
    }

    /** Where the rows of line $i start, in runes from the start of the line: [0] for a line on one row. @return list<int> */
    public function rows(int $i): array
    {
        $l = $this->state?->lastLayout();
        if ($l === null || $i < 0 || $i >= $this->count()) {
            return [0];
        }
        $first = $this->firstRow($l, $i);
        $start = $l->lines[$first][0];
        $out = [];
        for ($r = $first; $r < count($l->lines) && ($r === $first || $l->lines[$r][0] > 0 && $l->text[$l->lines[$r][0] - 1] !== "\n"); $r++) {
            $out[] = mb_strlen(substr($l->text, $start, $l->lines[$r][0] - $start));
        }
        return $out;
    }

    /** The row line $i starts on: the rows before it, counted past the newlines. */
    private function firstRow(TextLayout $l, int $i): int
    {
        if ($i === 0) {
            return 0;
        }
        $seen = 0;
        foreach ($l->lines as $r => [$a]) {
            if ($a > 0 && $l->text[$a - 1] === "\n" && ++$seen === $i) {
                return $r;
            }
        }
        return count($l->lines);
    }
}
