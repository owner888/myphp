<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Transfer\{Data, Drop, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};

/** Lit while a drag it takes is over it, $name in $this->over. */
$lit = fn(View $v, string $name) => $v->overlay(RoundedRectangle(cornerRadius: 8)->stroke($this->over === $name ? Color::accentColor : Color::clear, lineWidth: 2));
$targeted = fn(string $name) => fn(bool $over) => $this->over = $over ? $name : ($this->over === $name ? '' : $this->over);
$text = new DropOptions([Format::URI_LIST, Format::MARKDOWN, Format::TEXT]);

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
    Text('Transfer')->font(Font::title2)->bold(),
    Text('Drag the note to TextEdit or Mail; drag text, a link or files from other apps onto the wells.')->font(Font::caption)->foregroundStyle(Color::secondary),
    GroupBox()(
        Text('A note')->bold(),
        Text($this->ended === '' ? 'Drag me out of the window' : $this->ended)->font(Font::caption)->foregroundStyle(Color::secondary),
    )->frame(width: 260)->draggable(new Data(new Item(
        Representation::bytes(Format::TEXT, 'A note from MyPHP'),
        Representation::lazy(Format::HTML, fn() => '<p><b>A note</b> from MyPHP</p>'), // made only for a destination taking HTML
    )), new DragOptions(Operation::COPY, done: function (Result $r) {
        $this->ended = $r->error !== null ? 'Failed: ' . $r->error->getMessage() : ($r->canceled ? 'Nothing took it' : 'Copied');
    })),
    HStack(alignment: VerticalAlignment::top, spacing: 12)(
        $lit(GroupBox('Text and links')(
            Text($this->over === 'text' ? 'Drop to take it' : ($this->dropped === '' ? 'Drop here' : mb_strimwidth($this->dropped, 0, 120, '…')))->font(Font::caption)->foregroundStyle(Color::secondary)
                ->frame(maxWidth: INF, minHeight: 60, alignment: Alignment::topLeading),
        )->frame(width: 260), 'text')
            ->dropDestination(for: $text, action: function (Drop $drop) use ($text) {
                $f = $drop->data->preferred(...$text->formats);
                $this->dropped = $f === Format::URI_LIST ? implode(' ', $drop->data->urls()) : $drop->data->read($f);
            }, isTargeted: $targeted('text')),
        $lit(GroupBox('Files')(
            VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                $this->files === [] ? Text('Drop files from the Finder') : null,
                Each(array_slice($this->files, -4), id: fn($p, int $i) => $i)(fn(string $p) => Text(verbatim: basename($p))),
            )->font(Font::caption)->foregroundStyle(Color::secondary)->frame(maxWidth: INF, minHeight: 60, alignment: Alignment::topLeading),
        )->frame(width: 260), 'files')
            ->dropDestination(for: new DropOptions([Format::FILE_LIST]), action: function (Drop $drop) { $this->files = [...$this->files, ...$drop->data->paths()]; }, isTargeted: $targeted('files')),
    ),
    // Cards: within the window, the card itself moves between the lists; to another app, its text.
    HStack(alignment: VerticalAlignment::top, spacing: 12)(
        Each(['todo' => 'To do', 'done' => 'Done'])(fn(string $title, string $list) => $lit(GroupBox($title)(
            Each($this->$list)(fn(string $card) => Text(verbatim: $card)->padding(10)->frame(maxWidth: INF, alignment: Alignment::leading)
                ->background(Color::background)->cornerRadius(8)->border(Color::separator)
                ->draggable(Data::text($card), value: [$list, $card])), // the card itself to this window's lists, its text to other apps
        )->frame(width: 260), $list)
            ->dropDestination(for: 'array', action: function (array $v) use ($list) {
                [$from, $card] = $v;
                if ($from === $list) { return; }
                $this->$from = array_values(array_diff($this->$from, [$card]));
                $this->$list[] = $card;
            }, isTargeted: $targeted($list))),
    ),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
