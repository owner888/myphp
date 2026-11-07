<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Text\Params;
use MyPHP\UI\RichText\{FormatState, ListKind};

/** A format's button, lit while the selection has it. */
$format = fn(string $label, bool $on, Closure $run) => Button($label, action: $run)->background($on ? Color::accentColor->opacity(0.18) : Color::clear)->cornerRadius(6);

return function () use ($format) {
    $f = $this->formatting(); // what the selection has, as the frame builds
    return VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        HStack(spacing: 4)(
            $format('B', $f->bold === FormatState::FormatOn, $this->toggleBold(...)),
            $format('I', $f->italic === FormatState::FormatOn, $this->toggleItalic(...)),
            $format('U', $f->underline === FormatState::FormatOn, $this->toggleUnderline(...)),
            $format('S', $f->strikethrough === FormatState::FormatOn, $this->toggleStrikethrough(...)),
            $format('</>', $f->code === FormatState::FormatOn, $this->toggleCode(...)),
            Divider()->frame(height: 20),
            Each(['Body', 'H1', 'H2', 'H3'])(fn(string $name, int $level) => $format($name, !$f->paragraphsMixed && $f->paragraph->heading === $level, fn() => $this->setHeading($level))),
            Spacer(),
            Button('Undo', action: $this->undo(...))->disabled(!$this->canUndo()),
            Button('Redo', action: $this->redo(...))->disabled(!$this->canRedo()),
        ),
        HStack(spacing: 4)(
            $format('• List', $f->paragraph->list === ListKind::Bullets, fn() => $this->toggleList(ListKind::Bullets)),
            $format('1. List', $f->paragraph->list === ListKind::Numbers, fn() => $this->toggleList(ListKind::Numbers)),
            $format('☑ Tasks', $f->paragraph->list === ListKind::Tasks, fn() => $this->toggleList(ListKind::Tasks)),
            $format('❝', $f->paragraph->quote, $this->toggleQuote(...)),
            $format('{ }', $f->paragraph->codeBlock, $this->toggleCodeBlock(...)),
            Divider()->frame(height: 20),
            $format('Left', $f->paragraph->align === Params::START, fn() => $this->setAlign(Params::START)),
            $format('Center', $f->paragraph->align === Params::CENTER, fn() => $this->setAlign(Params::CENTER)),
            $format('Right', $f->paragraph->align === Params::END, fn() => $this->setAlign(Params::END)),
        ),
        TextEditor(document: $this)->accessibilityLabel('Note'),
        Text('⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them; ⌥⌘1–3 make headings.')->font(Font::caption)->foregroundStyle(Color::secondary),
    )->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
};
