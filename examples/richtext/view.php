<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Text\Params;
use MyPHP\UI\Context;
use MyPHP\UI\RichText\{EditorOptions, FormatState, ListKind, RichText};

return function (Context $c): void {
    $t = $c->theme;
    $f = $this->formatting();
    $button = function (string $label, bool $on, Closure $run) use ($c, $t): void {
        $b = $c->button($label)->keepFocus();
        if ($on) { $b->background($t->accent, 0.18); }
        if ($c->clicked($b)) { $run(); }
    };
    $rule = fn() => $c->box(1, 20, $t->border);
    $c->column(20, 12, 1);
      $c->row(0, 4)->wrap($c->width - 40);
        $button('B', $f->bold === FormatState::FormatOn, $this->toggleBold(...));
        $button('I', $f->italic === FormatState::FormatOn, $this->toggleItalic(...));
        $button('U', $f->underline === FormatState::FormatOn, $this->toggleUnderline(...));
        $button('S', $f->strikethrough === FormatState::FormatOn, $this->toggleStrikethrough(...));
        $button('</>', $f->code === FormatState::FormatOn, $this->toggleCode(...));
        $rule();
        foreach (['Body', 'H1', 'H2', 'H3'] as $level => $name) {
            $button($name, !$f->paragraphsMixed && $f->paragraph->heading === $level, fn() => $this->setHeading($level));
        }
        $rule();
        $button('• List', $f->paragraph->list === ListKind::Bullets, fn() => $this->toggleList(ListKind::Bullets));
        $button('1. List', $f->paragraph->list === ListKind::Numbers, fn() => $this->toggleList(ListKind::Numbers));
        $button('☑ Tasks', $f->paragraph->list === ListKind::Tasks, fn() => $this->toggleList(ListKind::Tasks));
        $button('❝', $f->paragraph->quote, $this->toggleQuote(...));
        $button('{ }', $f->paragraph->codeBlock, $this->toggleCodeBlock(...));
        $rule();
        $button('Left', $f->paragraph->align === Params::START, fn() => $this->setAlign(Params::START));
        $button('Center', $f->paragraph->align === Params::CENTER, fn() => $this->setAlign(Params::CENTER));
        $button('Right', $f->paragraph->align === Params::END, fn() => $this->setAlign(Params::END));
        $rule();
        $undo = $c->button('Undo')->keepFocus();
        $undo->disabled = !$this->canUndo();
        if ($c->clicked($undo)) { $this->undo(); }
        $redo = $c->button('Redo')->keepFocus();
        $redo->disabled = !$this->canRedo();
        if ($c->clicked($redo)) { $this->redo(); }
      $c->end();
      RichText::editor($c, $this, new EditorOptions(label: 'Note'))->size(0, 460);
      $c->muted('⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them; ⌥⌘1–3 make headings.', 12);
    $c->end();
};
