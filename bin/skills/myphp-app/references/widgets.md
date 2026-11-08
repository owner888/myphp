# Choosing views by need

Start from what the user asked for, not from the list of views: find the
need below, take the view it names, then read its page in `docs/ui/` and
its row in `docs/swiftui.md` for the call shape (every example there runs
in the tests). Signatures are abridged; a property passed to a control
(`isOn: $this->wifi`) is bound by reference, the control writes it. When
two fit, take the first: it is the simpler one.

## Show text and pictures

| Need | View | Page |
|---|---|---|
| A line or paragraph of text | `Text('…')` + `->font(Font::headline)`, `->foregroundStyle(Color::secondary)`, `->lineLimit(2)`, `->bold()` | text.md |
| Text with links, bold runs | `Text([...runs])`, `Markdown($md)` for Markdown, `streaming: true` while it arrives | text.md, markdown.md |
| An icon | `Image(systemName: 'gearshape')` (an SF Symbol, mapped on Linux and Android), `Label('Settings', systemImage: 'gearshape')` with a title | icon.md |
| A photo, screenshot, SVG | `Image(file: $path)`, `Image($image)`, `->resizable()`… | image.md |
| A person | `Avatar($name, $image)` | avatar.md |
| A count on a tab or row | `->badge(3)` | badge.md |
| Progress, waiting, a level | `ProgressView(value: $f)`, `ProgressView()` (spinning), `Gauge(value: $v, in: [$lo, $hi])` | progress.md, spinner.md, meter.md |
| Numbers as bars, lines, donuts | `Chart($data)(fn($row) => BarMark(x: …, y: …))`, `LineMark`, `SectorMark`… | chart.md |
| A video | `VideoPlayer($player)` | video.md |

## Let the user act

| Need | View | Page |
|---|---|---|
| Do something now | `Button('Save', action: fn() => $this->save())`; `->buttonStyle(ButtonStyle::borderedProminent)` for the main one; `role: ButtonRole::destructive` | button.md |
| A button with an icon | `Button('Add', action: …, systemImage: 'plus')`, or `label: Label(…)` | button.md |
| Open a URL | `WebLink('Docs', destination: $url)` | link.md |
| A choice from a short menu | `Menu('Sort')(Button(…), …)`; `->contextMenu(Button(…), …)` on right click or a long press | menu-button.md, context-menu.md |
| Buttons along the top | `->toolbar(Button(…), …)` on the page | toolbar.md |
| On/off | `Toggle('Sync', isOn: $this->sync)`: a switch on a phone, a checkbox on a Mac; `->toggleStyle(ToggleStyle::switch)` to choose | switch.md, checkbox.md |
| A pressed/unpressed button (bold, italic) | `Toggle(…)->toggleStyle(ToggleStyle::button)` | toggle.md |
| One of a few, all visible | `Picker('View', selection: $this->mode)(Text('A')->tag('a'), …)->pickerStyle(PickerStyle::segmented)`; `::radioGroup` | segmented.md, radio.md |
| One of many | `Picker(…)` (a pop-up menu); `ComboBox('City', text: $this->city, items: $cities)` when typing filters | select.md, combobox.md |
| A number in a range | `Slider(value: $this->v, in: [0, 100])`; `Stepper('Copies', value: $this->n, in: [1, 9])`; `RangeSlider(lower:, upper:, in:)` | slider.md, stepper.md, range-slider.md |
| Stars | `Rating('Rating', value: $this->stars)` | rating.md |
| A date, a time, a color | `DatePicker('Due', selection: $this->due)`, `->datePickerStyle(DatePickerStyle::graphical)` for the calendar; `ColorPicker('Tint', selection: $this->rgb)` | date-input.md, calendar.md, color-picker.md |

## Take text

| Need | View | Page |
|---|---|---|
| One line (name, URL) | `TextField('Name', text: $this->name)`, `->onSubmit(fn() => …)` for Return; `SecureField` for a password | text-input.md |
| Search | `->searchable(text: $this->query)` on a list or page; `SearchField(text: $this->query)` on its own | search-field.md |
| Several lines, plain | `TextEditor(text: $this->body)`; `TextField(…, axis: Axis::vertical)` for a field that grows | text-input.md |
| Notes with formatting (headings, lists, links, images) | `TextEditor(document: $this->doc)` on a `RichText\Document` | rich-text-editor.md |
| Code | `CodeEditor($state)` | code-editor.md |
| Two versions side by side | `DiffView($diff)` | diff-viewer.md |
| Suggestions while typing | `TextField(…)->textInputSuggestions($suggestions)` | autocomplete.md |
| Tags, recipients | `TokenField('To', tokens: $this->to, suggestions: $people)` | token-field.md |
| Find in a document | `->findNavigator(isPresented: $this->finding, text: $this->find)` | find-bar.md |
| Labeled fields | `Form()(Section('Account')(TextField(…), Toggle(…), LabeledContent('Version', '1.0')))` | form.md |

## Show many things

| Need | View | Page |
|---|---|---|
| Rows, any number, selectable | `ListView($items, id: 'id', selection: $this->chosen)(fn(array $item) => …)` — the default for collections | list.md |
| A few rows that never scroll | `VStack()(Each($items, id: 'id')(fn($item) => …))` | views.md |
| Columns the user sorts and resizes | `Table($rows, id: 'id')(TableColumn('Name', value: 'name'), …)` | table.md |
| Thumbnails in a grid | `LazyVGrid(columns: [GridItem::adaptive(minimum: 120)])(Each(…))` | grid-view.md |
| Items that open and close | `DisclosureGroup('Advanced', isExpanded: $this->open)(…)` | collapsible.md, outline.md |
| Rows that animate in, out and when sorted | `->animation(Animation::smooth(), value: $this->items)`, `->transition(…)` | transitions.md |
| Pull down to refresh | `->refreshable(fn() => $this->reload())` | ../mobile.md |
| Rows swiped to flag or delete (Mail's) | `->swipeActions(Button('Delete', role: ButtonRole::destructive, action: …))` on a row | ../mobile.md |
| A settings page: grouped rows | `Form()(Section(…)(…))` | form.md, ../mobile.md |

## Organize the window

| Need | View | Page |
|---|---|---|
| Content taller than the window | `ScrollView()(…)`; `ScrollViewReader` to scroll to an id | scroll.md |
| An app's top-level pages, on every screen | `TabView(selection: $this->page)(Tab('Home', systemImage: 'house', value: 'home')(…), …)->tabViewStyle(TabViewStyle::sidebarAdaptable)`: a sidebar on the desktop, tabs on a tablet, the tab bar on a phone. Write it once; never branch on the platform for it | tabs.md, ../mobile.md |
| A source list of the app's data on the left (Notes, Mail) | `NavigationSplitView(ListView($notes, id: 'id', selection: $this->chosen)(fn($n) => …)->searchable(text: $this->query), $detail)`: rows of any views | sidebar.md |
| Two panes the user resizes | `HSplitView()(…, …)` | split.md |
| Pages with a history, Back | `NavigationStack(path: $this->path)(…)->navigationDestination(for: '/notes/{id}', destination: __DIR__ . '/pages/note.php')`, `NavigationLink('Note', value: "/notes/$id")` | navigation.md |
| Where am I | `Breadcrumbs($path, selection: $this->at)` | breadcrumbs.md |
| A card, a panel | `GroupBox('Title')(…)`, or `VStack()(…)->padding()->background(Color::secondarySystemBackground)->cornerRadius(12)` | styling.md |
| A frosted or glass panel | `->glassEffect(…)`; a window's `vibrancy: 'sidebar'` | styling.md, ../plugins/glass.md |
| A web page inside | `WebView($page)` | web-view.md |
| A terminal | `TerminalView($terminal)` | ../plugins/terminal.md |
| A drawing of your own | `Canvas(fn(GraphicsContext $g, CGSize $size) => …)` | drawing.md |
| A control of your own | `Representable(fn(Context $c) => …)`, the engine inside one view | custom-widgets.md |

## Interrupt, ask, tell

| Need | View | Page |
|---|---|---|
| A question with buttons | `->alert('Delete the note?', isPresented: $this->asking, actions: [Button('Delete', role: ButtonRole::destructive, action: …), Button('Cancel', role: ButtonRole::cancel)])` | alert-dialog.md |
| Choices from the bottom (a phone's action sheet) | `->confirmationDialog($title, isPresented:, actions: […])` | ../mobile.md |
| A form or picker over the window | `->sheet(isPresented: $this->editing, content: fn() => …)`, `->presentationDetents([…])` on a phone | dialog.md, ../mobile.md |
| A small panel by a button | `->popover(isPresented: $this->open, content: fn() => …)` | popover.md |
| Something saved, undone | `->toast($this->message)` | toast.md |
| A hint on hover | `->help('…')` | tooltip.md |
| Choose files, save as | `Dialog::open()`, `Dialog::save()` (the system's) | ../native.md |
| The system's menus, notifications, tray, clipboard | `App::setMenu()`, `Notification`, `Tray`, `Clipboard` | ../menus.md, ../native.md |

## Keep and fetch data

| Need | How | Page |
|---|---|---|
| A few settings, a small document | JSON under `App::path(PathName::UserData)`, loaded once, saved on change (`examples/notes`) | ../app.md |
| Many records, queries | the SQLite plugin | ../plugins/sqlite.md |
| HTTP | `Http::get()`, `->json()`, inside `App::go()` | ../native.md, references/async.md |
| Files that change on disk | the watch plugin | ../plugins/watch.md |
| Live updates | the websocket plugin | ../plugins/websocket.md |

## Worked example: "make me a notepad"

What the user means: several notes, a list of them, one open for
editing, saved between runs. Decide in this order:

1. **Window shape**: the notes on the left and the note on the right — a
   `NavigationSplitView` of a `ListView` of the notes and the editor. On
   a phone the same app is a `NavigationStack`: the list, a note pushed
   over it.
2. **The list**: `ListView($this->notes, id: 'id', selection:
   $this->chosen)`, each row the note's title and a muted first line.
3. **The editor**: plain text → `TextEditor(text: $this->notes[$i]['body'])`;
   formatting, images, Markdown export → `TextEditor(document: …)` on a
   `Document` per note, made when the note is made, never in the view.
   An edit is saved from `->onChange(of: …, action: fn() =>
   $this->edited($note))`: the action calls a method of the state, which
   saves.
4. **Actions**: the detail's `->toolbar(Button('New', systemImage:
   'square.and.pencil', action: $this->add(...))->keyboardShortcut('n'),
   Button('Delete…', systemImage: 'trash', action: fn() => $this->asking
   = true))`, which shows in the window's toolbar; the delete confirmed by
   `->alert('Delete this note?', isPresented: $this->asking, actions: […])`.
   A row's `->contextMenu` can offer the same.
5. **Saving**: notes as JSON in `App::path(PathName::UserData)` (after
   `App::setName`), loaded in the constructor, saved from the class's
   methods after each change. The view only reads the state and calls
   those methods: a method it calls while it is built (`current()`,
   `shown()`) changes nothing.
6. **Search**: `->searchable(text: $this->query)` on the sidebar's list,
   filtering by `shown()`; with nothing found, a `ListView()(Text('No note
   matches.'))` in its place, the same modifier on it, so the field stays.

`examples/notes` is this app, laid out as `SKILL.md` says: `src/Notes.php`
its state (the notes, JSON saving, search, delete), `view.php` its view
with the rich-text editor and the toolbars, `main.php` the window and the
Format menu, `tests/ui_test.php` its headless test: copy its shape, then
cut what the user did not ask for.

For plain text, the view binds the editor to the body of the note shown,
by its index, and saves an edit, not a change of note:

```php
return function () {
    $i = $this->index(); // the index of the note chosen, the first without one
    $shown = $this->shown();
    return NavigationSplitView(
        sidebar: ($shown === []
            ? ListView()(Text('No note matches.')->foregroundStyle(Color::secondary))
            : ListView($shown, id: 'id', selection: $this->chosen)(fn(array $n) => Text(verbatim: $this->title($n)))
        )->searchable(text: $this->query),
        detail: TextEditor(text: $this->notes[$i]['body'])->accessibilityLabel('Note') // its label: a test clicks it by that
            ->onChange(of: [$this->chosen, $this->notes[$i]['body']], action: fn(array $was, array $now) => $was[0] === $now[0] ? $this->save() : null),
    );
};
```

A test focuses that editor with `$h->clickLabel('Note')` before it types. Test it as `SKILL.md` says: a headless test that
adds a note, types, finds the title in the list, searches and deletes
(`clickText('Delete')` over the alert clicks the alert's button); then
`myphp screenshot` in light and dark.
