<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, Key, Node, TextState};

/**
 * An editor's go to line bar, as State::$renderGoToLine builds it (mygo's
 * GoToLineBar): line[:column], or +n and -n lines from the caret, which
 * the editor scrolls to as it is typed, as Zed's.
 */
final class GoToLineBar
{
    public function __construct(private Context $c, private Editor $ed) {}

    /** The bar's field, to bind an input to, with input(). */
    public function text(): TextState { return $this->ed->goTo->field; }

    /** Makes $in the bar's field: it takes the focus as the bar opens, Enter goes to the line, and Escape closes the bar where the editor was. It returns $in. */
    public function input(Node $in): Node
    {
        $gt = $this->ed->goTo;
        $rt = $this->c->runtime;
        if ($gt->focus) {
            $gt->focus = false;
            $rt?->focusOn($in->id);
            $in->focused = true;
        }
        if ($this->c->submitted($in)) {
            $this->go();
        } elseif ($rt?->focus === $in->id && $this->c->shortcut(Key::ESCAPE)) {
            $this->close();
        }
        return $in;
    }

    /** Where the newest caret is, and how many lines there are, as the bar tells. */
    public function cursor(): Pos { return Buffer::posOf($this->ed->view->text, $this->ed->primary()->head); }
    public function lineCount(): int { return $this->ed->view->text->lineCount(); }

    /** The line and column typed, from 0, or null when the text is not one. */
    public function target(): ?Pos
    {
        $t = $this->ed->goTo->target($this->ed);
        return $t === null ? null : new Pos($t[0], $t[1]);
    }

    /** Moves the caret to the line typed and closes the bar; close closes it where the editor was. */
    public function go(): void
    {
        $ed = $this->ed;
        if (($t = $ed->goTo->target($ed)) !== null) {
            $off = Buffer::offsetOf($ed->view->text, new Pos($t[0], $t[1]));
            $ed->goTo->open = false;
            $ed->setSels([$ed->newSel($off, $off)], Editor::REVEAL_CENTER);
            $ed->refocus = true;
        }
    }

    public function close(): void { $this->ed->goTo->close($this->ed); }

    /** Builds the editor's own go to line bar into $bar (mygo's DefaultGoToLine): the field, and where the caret is. */
    public static function default(Context $c, Node $bar, GoToLineBar $g): void
    {
        $t = $c->theme;
        $tx = $c->texts();
        $bar->dir = Node::ROW; $bar->gap = $t->space(2); $bar->align(Node::CENTER)->padding($t->space(1.5), $t->space(2));
        $bar->color = $t->surface; $bar->round($t->radius)->border($t->border)->shadow(2, 8, 0.18);
        $g->input($c->input($g->text(), $tx->get('lineRange', ['lines' => $g->lineCount()]), $t->space(60)));
        $cur = $g->cursor();
        $c->text($tx->get('currentLine', ['line' => $cur->line + 1, 'lines' => $g->lineCount(), 'column' => $cur->col + 1]), $t->fontSize - 2, $t->textMuted);
    }
}
