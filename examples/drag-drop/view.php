<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Transfer\{Data, Drop, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};

/** A card to drag out: $title, the border of the window's cards. */
$card = fn(string $title) => Text($title)->padding(14)->frame(maxWidth: INF, alignment: Alignment::leading)->border(Color::separator)->cornerRadius(8);

/** A window: its notes to drag out, a URL and a file, and a zone taking notes, files, URLs and text. */
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text(verbatim: $this->name)->font(Font::system(size: 24, weight: Weight::bold)),
    Text('Drag a card to the other window, a text editor, or Finder/Explorer. Drop files, text, and URLs here.')->foregroundStyle(Color::secondary),
    Each($this->notes, id: 'id')(function (Note $n) use ($card) {
        $frozen = clone $n; // the note as it is when the drag starts
        return $card($n->title)->draggable(fn() => new Data(new Item(
            Representation::bytes(Format::TEXT, $frozen->title),
            Representation::lazy(NOTE_FORMAT, fn() => json_encode(['ID' => $frozen->id, 'Title' => $frozen->title], JSON_THROW_ON_ERROR)),
        )), new DragOptions(Operation::COPY | Operation::MOVE, done: function (Result $r) use ($n): void {
            $this->completed($r);
            if ($r->operation === Operation::MOVE && $r->error === null) {
                $this->notes = array_values(array_filter($this->notes, fn(Note $v) => $v !== $n));
            }
        }), value: $n);
    }),
    $card('Drag the myphp repository URL')->draggable(Data::url('https://github.com/owner888/myphp'), new DragOptions(done: $this->completed(...))),
    $card('Drag a sample .txt file')->draggable(Data::files($this->file), new DragOptions(done: $this->completed(...))),
    Text('Drop here')->bold()->frame(maxWidth: INF, minHeight: 100)
        ->background($this->targeted ? Color::accentColor->opacity(0.1) : Color::clear)
        ->border($this->targeted ? Color::accentColor : Color::separator, width: 2)->cornerRadius(8)
        ->dropDestination(new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST], Operation::COPY | Operation::MOVE),
            action: fn(Drop $drop) => $this->receive($drop), isTargeted: fn(bool $over) => $this->targeted = $over),
    Text(verbatim: $this->status)->font(Font::caption)->foregroundStyle(Color::secondary),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
