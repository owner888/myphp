<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

/** An entry's icon: the folder in the accent color, a file muted. */
$icon = fn(array $e) => Image($this->icons[$e['dir'] ? 'folder' : 'file'])->foregroundStyle($e['dir'] ? Color::accentColor : Color::secondary);

/** The entries as a grid of icons 96 wide: a thumbnail for an image, the icon otherwise, the name below; Cmd- and Shift-click choose several, Return or a double click opens. */
$grid = fn() => LazyVGrid(columns: [GridItem::adaptive(minimum: 96)], selection: $this->chosen)(
    Each($this->entries, id: 'path')(fn(array $e) => VStack(spacing: 4)(
        Files::isImage($e) && ($img = $this->thumb($e)) !== null
            ? Image($img)->resizable()->scaledToFit()->cornerRadius(6)->frame(width: 64, height: 64)
            : $icon($e)->font(Font::system(size: 40))->frame(width: 64, height: 64),
        Text(verbatim: $e['name'])->font(Font::caption)->lineLimit(1),
    )->frame(maxWidth: INF)->padding(4)),
)->contextMenu(primaryAction: fn(array $paths) => count($paths) === 1 ? $this->enter($paths[0]) : null);

/** The entries as a table: name with its icon, size, modified; the headers sort, Return or a double click opens. */
$table = fn() => Table($this->entries, id: 'path', selection: $this->chosen, sortOrder: $this->sortOrder)(
    TableColumn('Name', fn(array $e) => HStack(spacing: 6)($icon($e), Text(verbatim: $e['name'])->lineLimit(1)), value: 'name')->width(min: 160),
    TableColumn('Size', fn(array $e) => Text(verbatim: Files::size($e['size']))->foregroundStyle(Color::secondary), value: 'size')->width(ideal: 90)->alignment(HorizontalAlignment::trailing),
    TableColumn('Modified', fn(array $e) => Text(verbatim: date('Y-m-d H:i', $e['mtime']))->foregroundStyle(Color::secondary), value: 'mtime')->width(ideal: 150),
)->contextMenu(primaryAction: fn(array $paths) => count($paths) === 1 ? $this->enter($paths[0]) : null)
    ->onChange(of: $this->sortOrder, action: $this->reload(...));

return function () use ($grid, $table) {
    $crumbs = preg_split(DIRECTORY_SEPARATOR === '/' ? '~/+~' : '~[\\\\/]+~', $this->path, -1, PREG_SPLIT_NO_EMPTY) ?: []; // on Windows either separator
    // The folder of the first $n crumbs: from the root elsewhere, from the drive on Windows (C:\ for C:).
    $upTo = fn(int $n): string => DIRECTORY_SEPARATOR === '/' ? '/' . implode('/', array_slice($crumbs, 0, $n)) : implode('\\', array_slice($crumbs, 0, $n)) . ($n === 1 ? '\\' : '');
    $chosen = $this->chosenEntries();
    $one = count($chosen) === 1 ? $chosen[0] : null;
    $names = array_map(fn($e) => $e['name'], $chosen);
    return NavigationSplitView(
        sidebar: ListView(selection: $this->place)(
            Section('Places')(Each(Files::places())(fn(string $path, string $id) => Label($id === 'root' ? 'Macintosh HD' : ($id === 'home' ? basename($path) : ucfirst($id)))->tag($id))),
        ),
        detail: VStack(spacing: 0)(
            // The toolbar: the path, the view, Back, Quick Look, Delete….
            HStack(spacing: 6)(
                Button('‹', action: fn() => $this->open(dirname($this->path)))->keyboardShortcut(KeyEquivalent::delete, modifiers: [])->disabled($this->path === '/' || $this->finding)->help('Enclosing folder'),
                Button('Macintosh HD', action: fn() => $this->open('/'))->buttonStyle(ButtonStyle::plain),
                Each($crumbs, id: fn($s, int $i) => $i)(fn(string $crumb, int $i) => HStack(spacing: 6)(
                    Text(verbatim: '›')->foregroundStyle(Color::secondary),
                    Button($crumb, action: fn() => $this->open($upTo($i + 1)))->buttonStyle(ButtonStyle::plain),
                )),
                Spacer(),
                Picker('View', selection: $this->view)(Text('Icons')->tag(0), Text('List')->tag(1))->pickerStyle(PickerStyle::segmented)->labelsHidden()->frame(width: 140),
                Button('Quick Look', action: function () use ($one) { $this->previewing = true; $this->preview(); })->keyboardShortcut(' ', modifiers: [])->disabled($one === null || !Files::isImage($one) || $this->finding || $this->previewing),
                Button('Delete…', action: fn() => $this->asking = true)->keyboardShortcut(KeyEquivalent::delete)->disabled($chosen === []),
            )->padding(8),
            Divider(),
            $this->view === 0 ? $grid() : $table(),
            Divider(),
            Text(verbatim: $this->status !== '' ? $this->status : ($chosen !== [] ? count($chosen) . ' of ' . count($this->entries) . ' chosen' : count($this->entries) . ' items'))
                ->font(Font::caption)->foregroundStyle(Color::secondary)->padding(10)->frame(maxWidth: INF, alignment: Alignment::leading),
        )
            // The find bar, over the folder, filtering its entries as the query changes.
            ->findNavigator(isPresented: $this->finding, text: $this->find, matches: $this->find === '' ? 0 : count($this->entries), current: $this->found)
            ->onChange(of: [$this->find, $this->finding], action: $this->reload(...))
            ->onChange(of: $this->found, action: fn() => $this->chosen = isset($this->entries[$this->found]) ? [$this->entries[$this->found]['path']] : []),
    )
        ->onChange(of: $this->place, action: fn() => isset(Files::places()[$this->place]) ? $this->open(Files::places()[$this->place]) : null)
        // Space previews the image chosen; Escape or Space closes the preview.
        ->sheet(isPresented: $this->previewing, content: fn() => VStack(spacing: 10)(
            $this->preview === null ? ProgressView() : Image($this->preview)->resizable()->scaledToFit()->cornerRadius(8)->frame(maxWidth: 800, maxHeight: 560),
            Text(verbatim: $one['name'] ?? ''),
            Button('Close', action: fn() => $this->previewing = false)->keyboardShortcut(' ', modifiers: []),
        )->padding(20))
        // Delete…: an alert, Delete the default.
        ->alert(count($names) === 1 ? "Move “{$names[0]}” to the Trash?" : 'Move ' . count($names) . ' items to the Trash?', isPresented: $this->asking,
            actions: [Button('Cancel', role: ButtonRole::cancel), Button('Delete', action: $this->trash(...), role: ButtonRole::destructive)], message: 'You can put them back from the Trash.');
};
