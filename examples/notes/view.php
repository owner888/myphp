<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Clipboard;
use MyPHP\UI\RichText\{Document, EditorOptions, FormatState, ListKind};

/** A format's button, lit while the selection has it, its shortcut in its tip. */
$format = fn(string $label, string $tip, bool $on, Closure $run) => Button($label, action: $run)->frame(minWidth: 30)
    ->background($on ? Color::accentColor->opacity(0.18) : Color::clear)->cornerRadius(6)->help($tip);

/** The toolbar: it formats the selection of $doc and shows its formats. */
$toolbar = function (Document $doc) use ($format) {
    $f = $doc->formatting();
    $style = new Binding( // the paragraph's style, as the document has it; chosen, set on it
        get: fn() => $f->paragraphsMixed ? -1 : [1 => 0, 2 => 1, 3 => 2][$f->paragraph->heading] ?? 3,
        set: fn(int $i) => $i >= 0 ? $doc->setHeading([1, 2, 3, 0][$i]) : null,
    );
    return HStack(spacing: 4)(
        Picker('Paragraph style', selection: $style)(Each(['Title', 'Heading', 'Subheading', 'Body'])(fn(string $s, int $i) => Text($s)->tag($i)))->labelsHidden(), // at least 140 wide, a pop-up button's least
        Divider()->frame(height: 20),
        $format('B', 'Bold (⌘B)', $f->bold === FormatState::FormatOn, $doc->toggleBold(...))->bold(),
        $format('I', 'Italic (⌘I)', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...))->italic(),
        $format('U', 'Underline (⌘U)', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...))->underline(),
        $format('S', 'Strikethrough (⇧⌘X)', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...))->strikethrough(),
        $format('</>', 'Code (⌘E)', $f->code === FormatState::FormatOn, $doc->toggleCode(...)),
        $format('Link', 'Link (⌘K)', $f->link !== '', fn() => $this->openLink($doc))
            ->popover(isPresented: $this->linkOpen, content: fn() => HStack(spacing: 6)(
                TextField('https://', text: $this->linkURL)->frame(width: 260)->defaultFocus()
                    ->onSubmit(function () use ($doc) { $doc->setLink(trim($this->linkURL)); $this->linkOpen = false; }),
                Button('Link', action: function () use ($doc) { $doc->setLink(trim($this->linkURL)); $this->linkOpen = false; })->buttonStyle(ButtonStyle::borderedProminent),
                $f->link !== '' ? Button('Remove', action: function () use ($doc) { $doc->setLink(''); $this->linkOpen = false; }) : null,
            )->padding(10)),
        Divider()->frame(height: 20),
        $format('•', 'Bulleted list (⇧⌘8)', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets)),
        $format('1.', 'Numbered list (⇧⌘7)', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers)),
        $format('☐', 'Checklist (⇧⌘L)', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks)),
        $format('❝', 'Quote', $f->paragraph->quote, $doc->toggleQuote(...)),
        $format('{}', 'Code block', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...)),
        Divider()->frame(height: 20),
        Button('Copy Markdown', action: fn() => Clipboard::writeText($doc->text()->markdown())),
        Spacer(),
        Button('New', action: $this->add(...))->keyboardShortcut('n')->accessibilityLabel('New note'),
    );
};

return function () use ($toolbar) {
    $n = $this->current();
    $doc = $n->document();
    $words = count(preg_split('/\s+/u', $doc->text()->plainText(), -1, PREG_SPLIT_NO_EMPTY));
    return NavigationSplitView(
        sidebar: ListView(selection: $this->selected)(
            Section('Notes')(Each($this->notes, id: 'id')(fn(Note $note) => Text(verbatim: $note->title())->tag((string)$note->id))),
        ),
        detail: VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
            $toolbar($doc),
            TextEditor(document: $doc, options: new EditorOptions(label: 'Note', placeholder: 'Title', onLinkCommand: fn() => $this->openLink($doc)))->id($n->id),
            Text(verbatim: sprintf('%d words · edited %s', $words, $n->updated->format('M j, H:i')))->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(Edge::vertical, 12)->padding(Edge::horizontal, 20),
    )->onChange(of: [$n->id, $doc->version()], action: function (array $was, array $now) use ($n, $doc) { // an edit, saved; not another note shown
        if ($was[0] !== $now[0]) { return; }
        $n->text = $doc->text(); $n->updated = new DateTimeImmutable();
        $this->save();
    });
};
