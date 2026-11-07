<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

/** The open note's text, its words and whether it is saved; a note chosen first. */
$editor = fn() => $this->open === null
    ? Text('Choose a note, or make one with New (Cmd+N).')->foregroundStyle(Color::secondary)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
    : VStack(spacing: 0)(
        TextEditor(text: $this->text)->font(Font::system(size: 14))
            ->onChange(of: $this->text, action: $this->tick(...))
            // The preview follows the editor: scrolled the same share of the way through.
            ->onScrollGeometryChange(for: fn(ScrollGeometry $g) => $g->fraction(), action: fn($was, float $f) => $this->follow($f)),
        HStack(spacing: 8)(
            Text(verbatim: sprintf('%d words · edited %s', $this->words(), date('M j, H:i', $this->edited())))->font(Font::caption)->foregroundStyle(Color::secondary),
            Spacer(),
            Text($this->text === $this->savedText() ? 'saved' : 'editing')->font(Font::caption)->padding(Edge::horizontal, 6)->padding(Edge::vertical, 2)
                ->background(Color::gray->opacity(0.2))->cornerRadius(8),
        )->padding(8),
    );

// Three panes the dividers resize: notes | editor | preview (while previewing).
return fn() => HSplitView()(
    VStack(spacing: 0)(
        HStack(spacing: 6)(
            Button('New', action: $this->create(...))->buttonStyle(ButtonStyle::borderless),
            Button('Delete…', action: fn() => $this->asking = true)->buttonStyle(ButtonStyle::borderless)->disabled($this->open === null),
            Spacer(),
            Toggle('Preview', isOn: $this->previewing),
        )->padding(Edge::horizontal, 8)->padding(Edge::bottom, 6),
        Divider(),
        $this->shown() === [] && $this->query !== ''
            ? Text('No note matches.')->font(Font::caption)->foregroundStyle(Color::secondary)->padding(10)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
            : ListView($this->shown(), id: 'path', selection: $this->chosen)(fn(array $it) => VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text(verbatim: $it['title'])->lineLimit(1),
                Text(verbatim: date('M j, H:i', $it['mtime']))->font(Font::system(size: 11))->foregroundStyle(Color::secondary),
            ))->listStyle(ListStyle::plain),
    )->searchable(text: $this->query, prompt: 'Search')->background(Color::gray->opacity(0.08))->frame(minWidth: 200, idealWidth: 240),
    $editor()->frame(minWidth: 240, idealWidth: 380),
    $this->previewing
        ? ScrollView()(Markdown($this->text, $this->md)->padding(16))
            ->onScrollGeometryChange(for: fn(ScrollGeometry $g) => $g->contentSize[1] - $g->containerSize[1], action: fn($was, float $range) => $this->previewRange = $range)
            ->scrollPosition($this->preview)
        : null,
)
    ->onChange(of: $this->chosen, action: fn($was, ?string $path) => $path !== null && $path !== $this->open ? $this->choose($path) : null) // the one before saved first
    // Deleting asks first.
    ->alert('Delete this note?', isPresented: $this->asking, actions: [
        Button('Cancel', role: ButtonRole::cancel),
        Button('Delete', action: $this->delete(...), role: ButtonRole::destructive),
    ], message: basename((string)$this->open) . ' goes to nowhere: there is no undo.');
