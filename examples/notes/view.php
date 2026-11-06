<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Clipboard;
use MyPHP\UI\{Context, Key, Node, Painter, Span, TextStyle};
use MyPHP\UI\RichText\{Attachment, Attributes, Document, EditorOptions, FormatState, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text};

/** The toolbar: it formats the selection of $doc and shows its formats. */
$toolbar = function (Context $c, Notes $a, Document $doc): void {
    $t = $c->theme;
    $f = $doc->formatting();
    $button = function (string $label, string $tip, bool $on, Closure $run) use ($c, $t): Node {
        $b = $c->button($label)->keepFocus()->padding(4, 9)->minWidth(30)->tooltip($tip);
        if ($on) { $b->background($t->accent, 0.18); }
        if ($c->clicked($b)) { $run(); }
        return $b;
    };
    $divider = fn() => $c->box(1, 20, $t->border);
    $c->row(0, 4)->wrap($c->width - 230 - 40)->alignItems(Node::CENTER);
      $styles = ['Title', 'Heading', 'Subheading', 'Body'];
      $style = $f->paragraphsMixed ? -1 : [1 => 0, 2 => 1, 3 => 2][$f->paragraph->heading] ?? 3;
      $before = $style;
      $c->select($styles, $style, 130)->keepFocus()->label('Paragraph style');
      if ($style !== $before && $style >= 0) {
          $doc->setHeading([1, 2, 3, 0][$style]);
      }
      $divider();
      $button('B', 'Bold (⌘B)', $f->bold === FormatState::FormatOn, $doc->toggleBold(...))->bold();
      $button('I', 'Italic (⌘I)', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...))->italic();
      $button('U', 'Underline (⌘U)', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...))->underline();
      $button('S', 'Strikethrough (⇧⌘X)', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...))->strikethrough();
      $button('</>', 'Code (⌘E)', $f->code === FormatState::FormatOn, $doc->toggleCode(...));
      $link = $button('Link', 'Link (⌘K)', $f->link !== '', fn() => $a->openLink($doc));
      $c->popover($link, $a->linkOpen, function () use ($c, $a, $doc, $f) {
          $c->row(0, 6)->alignItems(Node::CENTER);
            $input = $c->input($a->linkURL, 'https://', 260)->autoFocus()->label('URL');
            if ($c->submitted($input) || $c->clicked($c->primaryButton('Link'))) {
                $doc->setLink(trim($a->linkURL->text));
                $a->linkOpen = false;
            }
            if ($f->link !== '' && $c->clicked($c->button('Remove'))) {
                $doc->setLink('');
                $a->linkOpen = false;
            }
          $c->end();
      });
      $divider();
      $button('•', 'Bulleted list (⇧⌘8)', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets));
      $button('1.', 'Numbered list (⇧⌘7)', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers));
      $button('☐', 'Checklist (⇧⌘L)', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks));
      $button('❝', 'Quote', $f->paragraph->quote, $doc->toggleQuote(...));
      $button('{}', 'Code block', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...));
      $divider();
      if ($c->clicked($c->button('Copy Markdown')->keepFocus())) {
          Clipboard::writeText($doc->text()->markdown());
      }
    $c->end();
};

/** The first note. */
$welcome = function (Context $c) use (&$fileChip): Text {
    $code = new Attributes(code: true);
    return Text::of(
        new Paragraph(new ParagraphStyle(heading: 1), [new Run('Welcome to Notes')]),
        new Paragraph(runs: [new Run('Write with '), new Run('bold', new Attributes(weight: 700)), new Run(', '), new Run('italic', new Attributes(italic: true)), new Run(', '),
            new Run('links', new Attributes(link: 'https://github.com/owner888/myphp')), new Run(' and lists, from the toolbar, the Format menu or the keyboard.')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Try')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Open this note')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Type '), new Run('- ', $code), new Run(' to start a list, '), new Run('# ', $code), new Run(' for a heading')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Copy the note as Markdown')]),
        new Paragraph(runs: [new Run('Attachments sit in the line, as '),
            new Run(Text::OBJECT_REPLACEMENT, new Attributes(attachment: $fileChip($c, 'itinerary.pdf'))),
            new Run(' does.')]),
    );
};

/** An attachment drawn as a chip with a file's name. */
$fileChip = function (Context $c, string $name): Attachment {
    $font = new TextStyle();
    $font->size = 12;
    [$w] = $c->shapeText($name, 0, $font)->size();
    return new Attachment(name: $name, width: $w + 28, height: 20, draw: function (Painter $p, array $r) use ($name): void {
        $t = $p->theme();
        $p->fill($r, $t->accent, $r[3] / 2, 0.12);
        $p->fill([$r[0] + 8, $r[1] + 6, 7, 8], $t->accent, 1.5);
        [, $lh] = $p->measureText(0, new Span($name, 12));
        $p->richText($r[0] + 20, $r[1] + ($r[3] - $lh) / 2, 0, new Span($name, 12, color: $t->accent));
    });
};

return function (Context $c) use (&$welcome, &$toolbar): void {
    $t = $c->theme;
    if ($this->welcome) { // the first note, its chip measured in the window's font
        $this->welcome = false;
        $this->notes = [new Note(++$this->next, $welcome($c), new DateTimeImmutable())];
    }
    $n = $this->current();
    $doc = $n->document();
    if ($c->shortcut('n', Key::COMMAND)) {
        $n = $this->add();
        $doc = $n->document();
    }
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $c->column(0, 8, 0, $t->surface)->width(230)->shrink(0)->padding(12, 0);
        $c->row(0, 0)->padding(0, 16)->alignItems(Node::CENTER);
          $c->text('Notes')->bold()->grow(1);
          if ($c->clicked($c->button('New')->label('New note'))) { $this->add(); }
        $c->end();
        $c->sidebar($this->selected, function () use ($c) {
            foreach ($this->notes as $note) {
                $c->key($note->id)->sidebarItem((string)$note->id, $note->title());
            }
        }, 230)->label('Notes')->grow(1);
      $c->end();
      $c->column(0, 10, 1)->minWidth(0)->padding(12, 20);
        $toolbar($c, $this, $doc);
        $editor = RichText::editor($c->key($n->id), $doc, new EditorOptions(label: 'Note', placeholder: 'Title', onLinkCommand: fn() => $this->openLink($doc)))->grow(1);
        if ($c->changed($editor)) {
            $n->text = $doc->text(); $n->updated = new DateTimeImmutable();
            $this->save();
        }
        $words = count(preg_split('/\s+/u', $doc->text()->plainText(), -1, PREG_SPLIT_NO_EMPTY));
        $c->text(sprintf('%d words · edited %s', $words, $n->updated->format('M j, H:i')), 12, $t->textMuted);
      $c->end();
    $c->end();
};
