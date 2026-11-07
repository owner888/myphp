# Choosing widgets by need

Start from what the user asked for, not from the widget list: find the
need below, take the widget it names, then read that widget's page in
`docs/ui/` for the call shape (every example there runs in the tests).
Signatures are abridged; `&$v` means the widget edits your property in
place. When two fit, take the first: it is the simpler one.

## Show text and pictures

| Need | Widget | Page |
|---|---|---|
| A line or paragraph of text | `$c->text($s, $size, $color)` + `->bold()`, `->textColor()`, `->maxLines(n)` | text.md |
| Text with links, bold runs, headings | `$c->richText()`; `$c->markdown()` for Markdown, streaming too | text.md, markdown.md |
| An icon in the text's color | `$c->icon($svg, $size)`; `Image::symbol('name', $size)` for an SF Symbol | icon.md |
| A photo, screenshot, SVG in its own colors | `$c->image($img, $w, $h, $radius)` | image.md |
| A person | `$c->avatar($name, $image)` | avatar.md |
| A count or status pill | `$c->badge($text)` | badge.md |
| Progress, waiting, a level | `$c->progress($f)`, `$c->spinner()`, `$c->meter($v, $lo, $hi)` | progress.md, spinner.md, meter.md |
| Numbers as bars, lines, donuts | `Chart` with `Axes`, `Linear`, `Band` | chart.md |
| A video or animated GIF | `$c->video($player)` | video.md |

## Let the user act

| Need | Widget | Page |
|---|---|---|
| Do something now | `if ($c->clicked($c->button('Save')))`; `primaryButton` for the main one | button.md |
| A button with an icon | your row on `$c->buttonBase()` | icon.md (In buttons) |
| Open a URL or a page | `$c->link($s, $url)` | link.md |
| A choice from a short menu | `$c->menuButton('Sort', fn(Menu $m) => …)`; `$node->contextMenu()` on right click | menu-button.md, context-menu.md |
| Several buttons along the top | `$c->toolbar(fn() => …)` overflows into a menu | toolbar.md |
| On/off | `$c->switch($label, &$on)` for a setting; `$c->checkbox($label, &$on)` in a form or list | switch.md, checkbox.md |
| A pressed/unpressed button (bold, italic) | `$c->toggle(&$on, $label)`, `toggleGroup` | toggle.md |
| One of a few, all visible | `$c->segmented($options, &$index)`; `radioGroup` with labels | segmented.md, radio.md |
| One of many | `$c->select($options, &$index)`; `combobox` when typing filters | select.md, combobox.md |
| A number in a range | `$c->slider(&$v, $lo, $hi)`; `stepper`, `numberInput` to type it; `rangeSlider` for low–high | slider.md, stepper.md, number-input.md |
| Stars | `$c->rating(&$n)` | rating.md |
| A date, a time, a color | `dateInput(&$d)`, `calendar(&$d)`, `timeInput(&$t)`, `colorPicker(&$rgb, &$a)` | date-input.md, calendar.md, time-input.md, color-picker.md |

## Take text

| Need | Widget | Page |
|---|---|---|
| One line (name, URL, search) | `$c->input($textState, $placeholder)`; `->password()`; `$c->submitted($f)` for Enter | text-input.md |
| Search that Escape clears | `$c->searchField($textState)` | search-field.md |
| Several lines, plain | `$c->textarea($textState, $placeholder, $w, $h)` | text-input.md |
| Notes with formatting (headings, lists, links, images) | `RichText` editor on a `Document` | rich-text-editor.md |
| Code | `Editor\State` + `Buffer` + `Languages` | code-editor.md |
| Two versions side by side | `DiffState` | diff-viewer.md |
| Suggestions while typing | `$c->autocomplete($state, $suggestions)` | autocomplete.md |
| Tags, recipients | `$c->tokenField(&$tokens, $suggestions)` | token-field.md |
| Rename in place | `$c->editableText(&$name)` | editable-text.md |
| Find in a document | `$c->findBar(&$open, $query, $matches, &$current)` | find-bar.md |
| Labeled fields with errors | `$c->form(fn() => $c->field('Name', fn() => …))` | form.md |

## Show many things

| Need | Widget | Page |
|---|---|---|
| Rows, any number, selectable | `$c->list($listState, $n, fn(int $i) => …)` — the default for collections | list.md |
| A few rows that never scroll | `foreach` with `$c->key($id)->row()` in a `column` | views.md |
| Columns the user sorts and resizes | `$c->table($listState, $columns, $n, $cell)` | table.md |
| Thumbnails in a grid | `$c->gridView($gridState, $n, $minW, $h, $item)` | grid-view.md |
| Nested items that open and close | `$c->tree()` for a few; `$c->outline($state, …)` for many | tree.md, outline.md |
| Fixed columns and rows (a dashboard) | `$c->grid($cols, $gap)` | grid.md |
| Rows that animate in, out and when sorted | `$c->transition($row, $elementTransition)` | transitions.md |
| Pull down to refresh | `$c->refreshable($scrollState, $app->refreshing, fn() => …)` | ../mobile.md |
| A large page title that goes under the bar (Mail's, Settings') | `$c->largeTitle($title)` first in the scroll, the `ScrollState` to `navigationBar()` | ../mobile.md |
| Rows swiped to flag or delete (Mail's) | `$c->swipeActions($action, fn() => …, 'Flag', '!Delete')` in a `list` row | ../mobile.md |
| A settings page: grouped rows with a title, a value and a chevron | `$c->insetGroupedList(fn() => $c->section($header, fn() => $c->cell($title, $detail, $disclosure)))` | ../mobile.md |

## Organize the window

| Need | Widget | Page |
|---|---|---|
| Content taller than the window | `$c->scroll(); … $c->end();` with a `ScrollState` to control it | scroll.md |
| A source list on the left (Finder, Notes, Mail) | `$c->sidebar(&$selected, fn() => $c->sidebarItem($id, $label, $icon))` | sidebar.md |
| Two panes the user resizes | `$c->split(&$size, $first, $second)` | split.md |
| Pages with a history, Back | `new Router('/')` + `$router->view($c, ['/notes' => __DIR__ . '/pages/notes.php', '/notes/{id}' => …])`, a page a file; `->push()`, `->back()` | navigation.md |
| A few pages switched by tabs | `$c->tabs($labels, &$index)` on the desktop; `$c->tabView($items, &$index, $content)` adapts to phones and tablets | tabs.md, ../mobile.md |
| An app's top-level pages, on every screen (desktop, tablet, phone) | In the declarative views, `TabView(selection:)( Tab(title, systemImage:, value:)( page ) … )->tabViewStyle(TabViewStyle::sidebarAdaptable)`: a sidebar (NavigationSplitView) on the desktop and a tablet on its side, tabs across the top of an upright tablet, the bottom tab bar on a phone. Write it once; never branch on the platform for it | ../declarative.md "One app, every screen" |
| Where am I | `$c->breadcrumbs($items, &$chosen)`; `$c->backButton()` | breadcrumbs.md, history-buttons.md |
| Sections that fold | `$c->collapsible($label, &$open, $fn)`; `accordion` for several | collapsible.md, accordion.md |
| A card, a panel | `$c->column(16, 8)->background($t->surface)->radius(12)->border(1, $t->border)` | styling.md |
| A frosted or glass panel | `->systemMaterial('sidebar')`, `->systemMaterial('glass')` | styling.md |
| A web page inside | `$c->webView($webView)` | web-view.md |

## Interrupt, ask, tell

| Need | Widget | Page |
|---|---|---|
| A question with buttons | `$i = $c->alertDialog(&$open, $title, $message, 'Cancel', 'Delete')` | alert-dialog.md |
| A form or picker over the window | `$c->modal(&$open, $fn)`; `$c->sheet(&$open, $fn)` on phones | dialog.md, ../mobile.md |
| A small panel by a button | `$c->popover($anchor, &$open, $fn)` | popover.md |
| Something saved, undone | `$c->toast('Saved')`, `toastAction` | toast.md |
| A hint on hover | `->tooltip($s)` | tooltip.md |
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

1. **Window shape**: a list on the left and the note on the right — a
   `sidebar` (titles are short, one section) in a `row` with a `column`
   for the editor. On a phone the same becomes a `tabView` or a `Router`
   with `navigationBar`; the desktop first.
2. **The list**: notes are few — `sidebarItem` per note, keyed by its id,
   the selected id as `&$selected`. Thousands of notes would be a `list`.
3. **The editor**: plain text → `textarea` with a `TextState` per note;
   formatting, images, Markdown export → the `RichText` editor on a
   `Document` per note. Make the state when the note is made, never in
   the view.
4. **Actions**: `primaryButton('New')` and a `Cmd+N` shortcut; delete
   through `contextMenu` on the item, confirmed by `alertDialog`.
5. **Saving**: notes as JSON in `App::path(PathName::UserData)`, loaded in
   the constructor, saved from `save()` after each change; a `toast` is
   not needed for autosave.
6. **Search**: a `searchField` above the sidebar filtering the titles,
   once there is more than a screen of notes.

`examples/notes` is exactly this app: `main.php` its state (the notes,
JSON saving, the Format menu) and `view.php` its view, with the rich-text
editor and the toolbar as a closure: copy its shape, then cut what the
user did not ask for. Test it as `SKILL.md` says: a headless test that adds a
note, types, and finds the title in the sidebar; then `myphp screenshot`
in light and dark.
