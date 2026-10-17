<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\TextState;

/**
 * The bar going to a line: line[:column], or +n and -n lines from the
 * caret, as Zed's go to line, which shows the line as it is typed and
 * goes back where it was on Escape (mygo's goToLine).
 */
final class GoToLine
{
    public bool $open = false;
    /** The bar's field, whose text is what was typed. */
    public TextState $field;
    public bool $focus = false;
    public float $savedY = 0.0, $savedX = 0.0;
    public int $preview = -1;

    public function __construct() { $this->field = new TextState; }

    public function show(Editor $ed): void
    {
        $this->open = true; $this->field = new TextState; $this->focus = true;
        $this->savedY = $ed->scrollY; $this->savedX = $ed->scrollX;
        $this->preview = -1;
        $ed->needsBuild = true;
    }

    public function close(Editor $ed): void
    {
        $this->open = false;
        $ed->setScroll($this->savedY);
        $ed->scrollX = $this->savedX;
        $this->preview = -1;
        $ed->refocus = true;
    }

    /** The line and column typed, from 0, or null when the text is not one. @return ?array{0: int, 1: int} */
    public function target(Editor $ed): ?array
    {
        $s = trim($this->field->text);
        if ($s === '') { return null; }
        $t = $ed->view->text;
        $cur = $t->lineOf($ed->primary()->head);
        $rel = 0;
        if ($s[0] === '+' || $s[0] === '-') {
            $rel = $s[0] === '-' ? -1 : 1;
            $s = substr($s, 1);
        }
        $parts = explode(':', $s, 2);
        $ls = trim($parts[0]);
        if (preg_match('/^[+-]?\d+$/', $ls) !== 1) { return null; }
        $line = (int)$ls;
        $col = 0;
        if (count($parts) === 2 && preg_match('/^[+-]?\d+$/', trim($parts[1])) === 1 && (int)trim($parts[1]) > 0) { $col = (int)trim($parts[1]) - 1; }
        $line = $rel !== 0 ? $cur + $rel * $line : $line - 1;
        $line = max(0, min($line, $t->lineCount() - 1));
        // The column counts characters, the position bytes.
        return [$line, Editor::byteOfRuneIn($t->line($line), $col)];
    }
}
