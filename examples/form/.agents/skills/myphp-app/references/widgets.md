# Choosing Views by Need

Quick lookup from product requirement -> MyPHP SwiftUI view (`src/UI/Declarative/functions.php`).

## 1. Text, Icons, Images & Charts

| Need | View & Modifiers |
|---|---|
| Text / paragraph | `Text('Key {n}', n: $count)` (i18n) or `Text(verbatim: $raw)` + `->font(Font::headline)`, `->foregroundStyle(Color::secondary)`, `->lineLimit(2)` |
| Rich text / Markdown | `Text([Text('Bold')->bold(), Text(' normal')])`, `Markdown($md, streaming: $bool)` |
| System icon | `Image(systemName: 'gearshape')`, `Label('Settings', systemImage: 'gearshape')` |
| Local image / multi-color SVG | `Image(file: $path)->resizable()->scaledToFit()`, `Image(SVG::parse($xml))->resizable()->scaledToFit()->frame(width: 44, height: 44)` |
| Remote HTTP(S) image | `AsyncImage($url, content: fn($img) => $img->resizable()->scaledToFill(), placeholder: fn() => ProgressView())` |
| Avatar (image or initials) | `Avatar($name, $image)` |
| Empty state / no search results | `ContentUnavailableView('No Items', systemImage: 'tray', description: '…')(Button(…))`, `ContentUnavailableView::search($query)` |
| Spinner / progress bar / gauge | `ProgressView()`, `ProgressView('Loading', value: $done, total: $max)`, `Gauge(value: $v, in: [0, 100])` |
| Charts (bar, line, area, pie) | `Chart($rows)(fn($r) => BarMark(x: PlottableValue::value('X', $r['x']), y: PlottableValue::value('Y', $r['y'])))`, `LineMark`, `AreaMark`, `PointMark`, `RuleMark`, `SectorMark` |

## 2. Buttons, Pickers & Inputs

| Need | View & Modifiers |
|---|---|
| Action button | `Button('Save', action: fn() => $this->save(), systemImage: 'checkmark')->buttonStyle(ButtonStyle::borderedProminent)` (`::bordered`, `::plain`, `::borderless`, `role: ButtonRole::destructive`) |
| Switch / checkbox | `Toggle('Wi-Fi', isOn: $this->wifi)` (`->toggleStyle(ToggleStyle::switch | checkbox | button)`) |
| Segmented / menu / radio picker | `Picker('Mode', selection: $this->mode)(Text('A')->tag('a'), …)->pickerStyle(PickerStyle::segmented | menu | radioGroup)` |
| Date / time / graphical calendar | `DatePicker('Due', selection: $this->due, displayedComponents: [DatePickerComponents::date])` (`->datePickerStyle(DatePickerStyle::graphical)`) |
| Slider / range / stepper / stars | `Slider(value: $this->v, in: [0, 100])`, `RangeSlider(lower: $this->lo, upper: $this->hi, in: [0, 100])`, `Stepper('Qty', value: $this->n, in: [1, 10])`, `Rating('Score', value: $this->stars)` |
| Single-line / password / search | `TextField('Name', text: $this->name)->onSubmit(fn() => …)`, `SecureField('Password', text: $this->pwd)`, `->searchable(text: $this->query)` |
| Multi-line / rich text / code | `TextEditor(text: $this->body)`, `TextField('Bio', text: $this->bio, axis: Axis::vertical)`, `TextEditor(document: $this->richDoc)`, `CodeEditor($state)`, `DiffView($diff)` |
| Photo picker / share / file | `PhotosPicker('Select Photo', selection: $this->photo)`, `ShareLink($url, title: 'Share')`, `->fileImporter(isPresented: $this->open, onCompletion: fn(array $paths) => …)` |

## 3. Lists, Grids & Navigation

| Need | View & Modifiers |
|---|---|
| Virtualized list (100k+ rows) | `ListView($items, id: 'id', selection: $this->chosen)(fn(array $item, int $i) => …)` + `->swipeActions(Button('Delete', role: ButtonRole::destructive, action: …))`, `->refreshable(fn() => …)` |
| Grouped settings / form | `Form()(Section('Account')(TextField(…), Toggle(…), LabeledContent('Version', '1.0')))` |
| Multi-column sortable table | `Table($rows, id: 'id', selection: $this->sel, sortOrder: $this->sort)(TableColumn('Name', value: 'name'), …)` |
| Responsive grid / 2D grid | `LazyVGrid(columns: [GridItem::adaptive(minimum: 140), GridItem::flexible()])(Each($items, id: 'id')(…))`, `Grid()(GridRow()(…))` |
| Wrapping tags / chips | `FlowLayout(spacing: 8)(Each($tags)(fn(string $t) => …))` |
| Tree / collapsible / accordion | `OutlineGroup($tree, children: 'children', id: 'id')(fn($item) => …)`, `Accordion(selection: $this->openSection)(DisclosureGroup('FAQ 1')(…)->tag('q1'), …)`, `DisclosureGroup('Details', isExpanded: $this->open)(…)` |
| Zoomable / pannable image viewer | `ZoomableView(minScale: 1.0, maxScale: 4.0)(Image(…))`, `->gesture(MagnifyGesture()->onChanged(…))` |
| Top-level app tabs + sidebar | `TabView(selection: $this->tab)(Tab('Home', systemImage: 'house', value: 'home')(…))->tabViewStyle(TabViewStyle::sidebarAdaptable)->tint($accent)` |
| Paged carousel / onboarding | `TabView(selection: $this->page)(Tab('1', value: 0)(…))->tabViewStyle(TabViewStyle::page(PageIndexDisplayMode::always))` |
| Push navigation stack | `NavigationStack(path: $this->path)(…)->navigationDestination(for: '/item/{id}', destination: __DIR__ . '/pages/item.php')`, `NavigationLink('Open', value: '/item/1')` |
| Modals, sheets & alerts | `->sheet(isPresented: $this->open, content: fn() => …)` (`->presentationDetents([PresentationDetent::medium(), PresentationDetent::large()])`, `->interactiveDismissDisabled()`), `->fullScreenCover()`, `->alert()`, `->confirmationDialog()`, `->popover()`, `->toast($this->toast)` |