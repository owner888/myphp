<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{Clipboard, ClipboardOptions};
use MyPHP\Transfer\{Data, Drop, DropOptions, Format};

// One note copied, dragged and dropped as text, HTML or lazy JSON, through the clipboard and drag and drop.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text('Clipboard and drag data')->font(Font::system(size: 24, weight: Weight::bold)),
    Text('Copy or drag the same note as text, HTML, or lazy JSON. Paste in another app to use its preferred representation.')->foregroundStyle(Color::secondary),
    TextField('Title', text: $this->title),
    TextEditor(text: $this->body)->frame(height: 90),
    HStack(spacing: 8)(
        Button('Copy note', action: function () {
            try {
                Clipboard::write($this->data(), new ClipboardOptions(onRelease: function () { $this->releases++; $this->win?->invalidate(); }));
                $this->status = 'Copied; JSON is produced only if requested';
            } catch (\RuntimeException $e) { $this->status = $e->getMessage(); }
        }),
        Button('Paste', action: function () {
            $preferred = Data::preferredFormat(Clipboard::formats(), NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST);
            if ($preferred === null) { $this->status = 'No supported clipboard format'; return; }
            try { $this->receive(Clipboard::read($preferred)); } catch (\RuntimeException $e) { $this->status = $e->getMessage(); }
        }),
        Button('Flush', action: function () {
            try { Clipboard::flush(); $this->status = 'Clipboard persisted; provider resources released'; } catch (\RuntimeException $e) { $this->status = $e->getMessage(); }
        }),
    ),
    Text('Drag this note')->bold()->padding(16)->border(Color::separator)->cornerRadius(8)->draggable($this->data(...)),
    Text('Drop notes, text, or files here')->padding(16)->border($this->targeted ? Color::accentColor : Color::separator, width: $this->targeted ? 2 : 1)->cornerRadius(8)
        ->dropDestination(new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST]), action: fn(Drop $drop) => $this->receive($drop->data), isTargeted: fn(bool $over) => $this->targeted = $over),
    HStack(spacing: 8)(
        TextField('Absolute file path', text: $this->path),
        Button('Copy file', action: function () {
            try { Clipboard::writeFiles($this->path); $this->status = 'Copied native file list'; } catch (\InvalidArgumentException | \RuntimeException $e) { $this->status = $e->getMessage(); }
        }),
    ),
    Text(verbatim: "JSON requests: {$this->providers} · Clipboard releases: {$this->releases}")->foregroundStyle(Color::secondary),
    Text(verbatim: $this->status),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
