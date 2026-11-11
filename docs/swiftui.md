# Coming from SwiftUI

A guide for people and coding agents who know SwiftUI and are about to
write a myphp view. myphp's views are SwiftUI's: the same names, argument
labels and order, in PHP syntax. Left is SwiftUI, right is myphp; every PHP
block runs headless in `tests/docs_examples_test.php`. In the blocks,
`$app` is the state and `$view` what a view file returns. `docs/mobile.md`
covers what a phone adds; `docs/upstream-deviations.md` lists where myphp
differs from the Go framework it ports.

## The shape of an app

```swift
struct Counter: View {
    @State var n = 0
    var body: some View {
        VStack(spacing: 12) {
            Text("\(n)").font(.largeTitle).bold()
            Button("Increment") { n += 1 }
        }
    }
}
```

```php
$app = new class { public int $n = 0; };
$view = fn() => VStack(spacing: 12)(
    Text("{$app->n}")->font(Font::largeTitle)->bold(),
    Button('Increment', action: fn() => $app->n++),
);
```

In an app the state is a class of its own, and the view a file,
`view.php`, that returns that closure with `$this` for `$app`
(`Content::file(__DIR__ . '/view.php', $app)`): saved, it hot reloads with
the state kept, as SwiftUI's previews and Flutter's hot reload do
([view files](ui/views.md)). The view runs for every frame, as SwiftUI's
body runs for every change: it reads the state and returns views; actions
change the state.

## Where PHP differs (the only differences)

The rule: every SwiftUI view, modifier and pattern is written as SwiftUI
writes it, in PHP syntax: the same names, the same argument labels, the
same order. A difference needs a reason in PHP itself, listed here. A myphp
view SwiftUI has no counterpart of (a terminal, a video player, a rating…)
takes a SwiftUI-shaped name and its arguments as SwiftUI would name them.

| SwiftUI | myphp | Why |
|---|---|---|
| `VStack(spacing: 12) { … }` | `VStack(spacing: 12)( … )` | No trailing closures; the second parentheses are the braces |
| Children on lines, no separators | Children separated by commas | A PHP argument list |
| `if cond { Text("a") }` in a builder | `$cond ? Text('a') : null` | No result builders: `null` is no view, an array is several |
| `ForEach(items, id: \.id) { … }` | `Each($items, id: 'id')(fn(array $item) => …)` | `foreach` is a PHP keyword, any case |
| `List { … }` | `ListView( … )` | `list` is a PHP keyword |
| `Link("Docs", destination: url)` | `WebLink('Docs', destination: $url)` | PHP has a built-in `link()` |
| `@State var n = 0` in the view | A property of the state class | A view is a closure, rebuilt every frame: state lives in the state object |
| `$binding` | The property itself: `Toggle('Wi-Fi', isOn: $app->wifi)` | PHP passes by reference |
| `.font(.largeTitle)` | `->font(Font::largeTitle)` | No implicit member expressions: the enum is named |
| `"\(n)"` | `"{$app->n}"` | PHP's interpolation |
| `struct Row: View` | `$row = fn(Todo $t) => …` in the view file | A class cannot be loaded twice: a closure hot reloads |

`VStack`, `Text`, `Button`… are global functions and `Font`, `Color`,
`Alignment`… global enums (`src/UI/Declarative/functions.php`, loaded with
the framework): no `use` line, as SwiftUI needs no import beyond
`SwiftUI`.

## State and data flow

| SwiftUI | myphp |
|---|---|
| `@State var on = false` | A property of the state class: `public bool $on = false;` |
| `@Binding var on: Bool`, passed as `$on` | The property itself, by reference: `Toggle('Wi-Fi', isOn: $app->on)`; `Binding(get:, set:)` for a computed one |
| `@StateObject`, `@Observable` | An object your state holds. Nothing to observe: the next frame reads it |
| `@Environment(\.colorScheme)` | `->preferredColorScheme()` to set it; the theme follows the system |
| `@FocusState` | `->focused($app->editing)`, `->defaultFocus()` |
| `onChange(of: value) { }` | `->onChange(of: $app->value, action: fn() => …)` |
| `@AppStorage` | A file under `App::path(PathName::UserData)`, read when the state is made, written from its methods |

```php
$app = new class { public bool $wifi = true; public float $volume = 0.5; public int $changes = 0; };
$view = fn() => Form()(
    Toggle('Wi-Fi', isOn: $app->wifi),
    $app->wifi ? Slider(value: $app->volume, in: [0, 1])->onChange(of: $app->volume, action: fn() => $app->changes++) : null,
);
```

## Layout

| SwiftUI | myphp |
|---|---|
| `VStack(alignment: .leading, spacing: 8) { }` | `VStack(alignment: HorizontalAlignment::leading, spacing: 8)( … )` |
| `HStack`, `ZStack(alignment:)` | `HStack(alignment: VerticalAlignment::top)( … )`, `ZStack(alignment: Alignment::topTrailing)( … )` |
| `Spacer()`, `Divider()` | `Spacer()`, `Divider()` |
| `.frame(width: 200, height: 44)` | `->frame(width: 200, height: 44)` |
| `.frame(maxWidth: .infinity, alignment: .leading)` | `->frame(maxWidth: INF, alignment: Alignment::leading)` |
| `.padding()`, `.padding(.horizontal, 12)` | `->padding()`, `->padding(Edge::horizontal, 12)` |
| `.offset(x: 10)` | `->offset(x: 10)` |
| `.overlay(alignment: .topTrailing) { Badge() }` | `->overlay(Text('3'), alignment: Alignment::topTrailing)` |
| `GeometryReader { proxy in }` | `GeometryReader(fn(GeometryProxy $proxy) => …)` |
| `Grid { GridRow { } }`, `.gridCellColumns(2)` | `Grid()(GridRow()( … ))`, `->gridCellColumns(2)` |
| `LazyVGrid(columns:)`, `LazyHGrid(rows:)` | `LazyVGrid(columns: [GridItem::adaptive(minimum: 120), GridItem::flexible(), GridItem::fixed(80)])( … )`, `LazyHGrid(rows: […])( … )` |
| `LazyVStack`, `LazyHStack` | `LazyVStack()( … )`, `LazyHStack()( … )` |
| `ScrollView { }`, `EmptyView()` | `ScrollView()( … )` (`ScrollViewReader` to scroll to an id), `EmptyView()` |

```php
$view = fn() => HStack(spacing: 12)(
    Text('Title')->bold(),
    Spacer(),
    Button('Edit', action: fn() => null),
)->padding()->frame(maxWidth: INF);
```

## Text and fonts

| SwiftUI | myphp |
|---|---|
| `Text("Hi")`, `Text(verbatim:)` | `Text('Hi')` (looked up in the app's strings), `Text(verbatim: $s)` |
| `.font(.title)`, `.font(.caption)` | `->font(Font::title)`, `->font(Font::caption)`: the text styles, at the platform's size, scaled with the system's text size; `->font(Font::system(size: 28))` |
| `.bold()`, `.fontWeight(.semibold)` | `->bold()`, `->fontWeight(Weight::semibold)` |
| `.italic()`, `.underline()`, `.strikethrough()`, `.monospaced()` | the same names |
| `.foregroundStyle(.secondary)` | `->foregroundStyle(Color::secondary)` |
| `.multilineTextAlignment(.center)`, `.lineLimit(2)` | `->multilineTextAlignment(TextAlignment::center)`, `->lineLimit(2)` |
| `.textSelection(.enabled)` | `->textSelection(TextSelectability::enabled)` |
| Markdown | `Markdown($text)` (and `streaming: true` while it arrives) |
| `Label("Save", systemImage: "square.and.arrow.down")` | the same: an SF Symbol on Apple platforms, the GTK theme's icon on Linux, a Material Symbol on Android |

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
    Text('Settings')->font(Font::largeTitle)->bold(),
    Text('12 files, 3.4 MB')->foregroundStyle(Color::secondary),
    Text('A long description that wraps and is cut after two lines.')->lineLimit(2),
);
```

## Colors, backgrounds and materials

| SwiftUI | myphp |
|---|---|
| `Color.accentColor`, `.primary`, `.secondary` | `Color::accentColor`, `Color::primary`, `Color::secondary` |
| `Color(.systemBackground)`, `Color(.secondarySystemBackground)` | `Color::systemBackground`, `Color::secondarySystemBackground` (and the grouped ones, `::label`, `::separator`, `::systemGray`…) |
| `.red`, `.orange`, `.green` | `Color::red`, `Color::orange`, `Color::green` |
| A color of the app's own | `Color::rgb(0x336699)` |
| `.background(.blue)`, `.background { … }` | `->background(Color::blue)`, `->background($view)` |
| `LinearGradient`, `RadialGradient`, `AngularGradient` | `LinearGradient(colors: [Color::blue, Color::purple], startPoint: UnitPoint::topLeading, endPoint: UnitPoint::bottomTrailing)`, `RadialGradient(colors: [...])`, `AngularGradient(colors: [...])` |
| `.glassEffect()`, `.background(.regularMaterial)` | `->glassEffect($material)`; a window's `vibrancy:` for the window's material |
| `.cornerRadius(12)`, `.clipShape(RoundedRectangle(cornerRadius: 12))` | the same |
| `.border(.gray)`, `.shadow(radius: 8, y: 4)`, `.opacity(0.5)` | `->border(Color::separator)`, `->shadow(radius: 8, y: 4)`, `->opacity(0.5)` |
| `preferredColorScheme(.dark)` | the same: `->preferredColorScheme(ColorScheme::dark)`, or `App::setColorScheme(true)` (null follows the system) |
| `dynamicTypeSize(.xxLarge)` | the same, or `App::setTextScale(DynamicTypeSize::xxLarge->scale())` |

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Text('Card')->bold(),
    Text('Follows light and dark mode')->foregroundStyle(Color::secondary),
)->padding()->background(Color::secondarySystemBackground)->cornerRadius(12);
```

The named colors follow the system's appearance, accent and contrast (see
[Styling](ui/styling.md)); take a literal only for data, a chart's series
or a user's tag.

## Controls

| SwiftUI | myphp |
|---|---|
| `Button("Save") { save() }` | `Button('Save', action: fn() => $app->save())` |
| `Button("Copy", systemImage: "doc.on.doc") { }` | `Button('Copy', action: …, systemImage: 'doc.on.doc')`: a Label of the title and the symbol; in a phone's context menu the item's icon |
| `Button(role: .destructive)`, `.buttonStyle(.borderedProminent)` | `role: ButtonRole::destructive`, `->buttonStyle(ButtonStyle::borderedProminent)` (`::bordered`, `::borderless`, `::plain`) |
| `.disabled(busy)`, `.keyboardShortcut("s")` | `->disabled($busy)`, `->keyboardShortcut('s')`; `->keyboardShortcut(KeyEquivalent::return, modifiers: [])` |
| `Toggle("Wi-Fi", isOn: $on)` | `Toggle('Wi-Fi', isOn: $app->on)`: a switch on a phone, a checkbox on a Mac; `->toggleStyle(ToggleStyle::switch)` |
| `Slider(value: $v, in: 0...1)` | `Slider(value: $app->v, in: [0, 1])` |
| `Stepper("Copies", value: $n, in: 1...10)` | `Stepper('Copies', value: $app->n, in: [1, 10])` |
| `Picker("Plan", selection: $plan) { Text("Free").tag(0) }` | `Picker('Plan', selection: $app->plan)(Text('Free')->tag(0), …)`, `->pickerStyle(PickerStyle::segmented)` (`::menu`, `::radioGroup`, `::inline`) |
| `TextField("Name", text: $name)`, `.onSubmit { }` | `TextField('Name', text: $app->name)->onSubmit(fn() => …)`; `prompt:`, `axis: Axis::vertical` |
| `SecureField`, `TextEditor(text:)` | `SecureField('Password', text: $app->password)`, `TextEditor(text: $app->notes)` |
| `DatePicker`, `ColorPicker` | `DatePicker('Due', selection: $app->due)`, `ColorPicker('Tint', selection: $app->rgb)` |
| `ProgressView()`, `ProgressView(value:)`, `Gauge` | the same |
| `Link("Site", destination:)` | `WebLink('Site', destination: 'https://…')` |
| `Menu("Sort") { Button("Name") { } }` | `Menu('Sort')(Button('Name', action: …), …)` |
| `.contextMenu { }` | `->contextMenu(Button(…), Divider(), …)`: a right click on a Mac, a long press on a phone |
| `Image(systemName:)`, `Image("asset")` | `Image(systemName: 'gearshape')`, `Image(file: $path)`, `Image($svg)` (`->resizable()`, `->scaledToFit()`, `->scaledToFill()`, `->aspectRatio(ContentMode::fit)`) |
| `AsyncImage(url:)` | `AsyncImage($url, content: fn(ImageView $img) => $img->resizable(), placeholder: fn() => ProgressView())` |
| `PhotosPicker`, `ShareLink`, `.fileImporter` | `PhotosPicker('Select Photo', selection: $app->photo)`, `ShareLink($url, title: 'Share')`, `->fileImporter(isPresented: $app->importing, onCompletion: fn(array $files) => …)` |
| `ContentUnavailableView` | `ContentUnavailableView('No Mail', systemImage: 'tray', description: '…')`, `ContentUnavailableView::search($query)` |
| `.help("tip")`, `.accessibilityLabel("Close")` | the same names |
| `Form { Section("Account") { LabeledContent("Version", value: "1.0") } }` | `Form()(Section('Account')(LabeledContent('Version', '1.0')))` |

```php
$app = new class { public string $name = ''; public int $plan = 0; public bool $news = false; };
$view = fn() => Form()(
    Section('Account')(
        TextField('Name', text: $app->name, prompt: 'Your name'),
        Picker('Plan', selection: $app->plan)(Text('Free')->tag(0), Text('Pro')->tag(1))->pickerStyle(PickerStyle::segmented),
        Toggle('Send me news', isOn: $app->news),
    ),
);
```

myphp's own controls AppKit has and SwiftUI has not, SwiftUI-shaped:
`ComboBox(title, text:, items:)`, `TokenField(title, tokens:,
suggestions:)`, `Rating(title, value:)`, `RangeSlider(lower:, upper:,
in:)`, `Breadcrumbs(path, selection:)`, `Avatar(name)`, `SearchField(text:)`,
`NumberField(title, value:, in:)`, `DecimalField(title, value:, in:, step:)`,
`EditableText(text:)`;
and the views of what the framework does: `VideoPlayer`, `WebView`,
`TerminalView`, `CodeEditor`, `DiffView`, `Markdown`, `SpriteView`.

## Lists and collections

| SwiftUI | myphp |
|---|---|
| `List(items) { Row($0) }` | `ListView($items, id: 'id')(fn(array $item, int $i) => …)`: only the rows in view are built, for millions of rows |
| `List(selection: $chosen)` | `ListView($items, id: 'id', selection: $app->chosen)` |
| `ForEach(items, id: \.id) { }` | `Each($items, id: 'id')(fn(array $item) => …)` |
| `.swipeActions { Button(role: .destructive) }` | `->swipeActions(Button('Delete', role: ButtonRole::destructive, action: …))` on a row: a full swipe performs the first |
| `.onMove` | `->onMove(fn(array $from, int $to) => …)` on the list |
| `Section("Today") { }`, `.listStyle(.plain)` | `Section('Today')( … )`, `->listStyle(ListStyle::plain)` |
| `.refreshable { await load() }` | `->refreshable(fn() => $app->reload())` |
| `.searchable(text:)`, `.searchScopes` | `->searchable($app->query)`, `->searchScopes($app->scope, Text('All')->tag('all'), …)` |
| `Table(rows) { TableColumn("Name", value: \.name) }` | `Table($rows, id: 'id')(TableColumn('Name', value: 'name'), …)` |
| `DisclosureGroup`, `OutlineGroup` | `DisclosureGroup('More', isExpanded: $app->open)( … )`, nested for an outline |
| `LazyVGrid` of photos | `LazyVGrid(columns: [GridItem::adaptive(minimum: 140)])(Each($photos)(…))` |

```php
$app = new class {
    public array $files;
    public function __construct() { $this->files = array_map(fn($i) => ['id' => $i, 'name' => "file-$i.txt"], range(1, 10_000)); }
};
$view = fn() => ListView($app->files, id: 'id')(fn(array $f) => HStack()(
    Text(verbatim: $f['name']),
    Spacer(),
    Text('2 KB')->foregroundStyle(Color::secondary),
));
```

## Navigation

| SwiftUI | myphp |
|---|---|
| `NavigationStack(path: $path) { root }` | `NavigationStack(path: $app->path)( root )`, `$app->path` a `Router` |
| `.navigationDestination(for:) { NoteView(id: $0) }` | `->navigationDestination(for: '/notes/{id}', destination: __DIR__ . '/pages/note.php')`, the file returning `fn(int $id) => …`; `{id}` comes by name, typed |
| `NavigationLink("Note", value: note)` | `NavigationLink('Note', value: "/notes/$id")`; `$app->path->push(…)` from an action |
| `dismiss()`, the back button | `$app->path->back()`; the bar's back button and the edge swipe are built in |
| `.navigationTitle("Notes")`, `.toolbar { }` | the same: `->navigationTitle('Notes')`, `->toolbar(Button(…), …)` |
| `.navigationBarTitleDisplayMode(.large)` | `->navigationBarTitleDisplayMode(TitleDisplayMode::large)` |
| `NavigationSplitView { sidebar } detail: { }` | `NavigationSplitView(ListView(…), $detail)`, for a sidebar of the app's data |
| `TabView { Tab("A", systemImage:, value:) { } }` | `TabView(selection: $app->tab)(Tab('A', systemImage: 'house', value: 'a')( … ), …)`; `->tabViewStyle(TabViewStyle::sidebarAdaptable)` for every screen; `->tabBarMinimizeBehavior(TabBarMinimizeBehavior::onScrollDown)` |
| `Tab(value:role: .search)`, `role: .prominent` | `Tab(value: 'search', role: TabRole::search)`: titled Search, in the bar with the others (iOS 27's); `TabRole::prominent` apart at the bar's end |

```php
$app = new class {
    public Router $path; public array $notes = [42 => 'Groceries', 7 => 'Ideas'];
    public function __construct() { $this->path = new Router('/'); }
};
$view = fn() => NavigationStack(path: $app->path)(
    ListView()(Each($app->notes)(fn(string $title, int $id) => NavigationLink($title, value: "/notes/$id")))->navigationTitle('Notes'),
)->navigationDestination(for: '/notes/{id}', destination: fn(int $id) => Text($app->notes[$id] ?? '')->navigationTitle('Note'));
```

## Sheets, alerts, popovers and menus

A presentation is driven by an `isPresented` binding, as SwiftUI's: a
property the control sets back to false when the user dismisses it.

| SwiftUI | myphp |
|---|---|
| `.sheet(isPresented: $open) { }` | `->sheet(isPresented: $app->open, content: fn() => …)`: a dialog on the desktop, a sheet from the bottom on a phone, a page sheet on an iPad |
| `.presentationDetents([.medium, .large])` | the same on the sheet's content: `[PresentationDetent::medium(), PresentationDetent::large()]` |
| `.alert("Title", isPresented:) { Button("OK") {} } message: { }` | `->alert('Title', isPresented: $app->asking, actions: [Button(…), …], message: '…')` |
| `.confirmationDialog` | `->confirmationDialog('Title', isPresented: $app->asking, actions: [Button(…), Button('Cancel', role: ButtonRole::cancel)])` |
| `.popover(isPresented:)` | `->popover(isPresented: $app->open, content: fn() => …)` |
| A toast (no SwiftUI equivalent) | `->toast($app->message)`: shows while the message is set |

```php
$app = new class { public bool $asking = false; public int $deleted = 0; };
$view = fn() => Button('Delete…', role: ButtonRole::destructive, action: fn() => $app->asking = true)
    ->alert('Delete the note?', isPresented: $app->asking, actions: [
        Button('Delete', role: ButtonRole::destructive, action: fn() => $app->deleted++),
        Button('Cancel', role: ButtonRole::cancel),
    ], message: 'This cannot be undone.');
```

## Animation

| SwiftUI | myphp |
|---|---|
| `withAnimation { open.toggle() }` | `withAnimation(Animation::snappy(), fn() => $app->open = !$app->open)` |
| `.animation(.spring, value: x)` | `->animation(Animation::smooth(), value: $app->x)` |
| `.spring(duration: 0.5, bounce: 0.2)` | `new Animation(spring: new SpringOptions(duration: 0.5, bounce: 0.2))` |
| `.smooth`, `.snappy`, `.bouncy` | `Animation::smooth()`, `::snappy()`, `::bouncy()` |
| `.transition(.opacity)`, `.matchedGeometryEffect` | `->transition(AnyTransition::opacity())`, `->matchedGeometryEffect(id: 'card', in: 'ns')` |
| `.scaleEffect`, `.rotationEffect`, `.offset` | the same names |
| `TimelineView(.animation)`, `.keyframeAnimator` | `TimelineView()(fn(TimelineViewContext $context) => …)`, `KeyframeAnimator(…)` |
| Reduce Motion | Honored by every built-in motion |
| `.sensoryFeedback(.success, trigger: saved)` | `Haptics::success()` in the action that saved; `Haptics::impact('light')`, `::selection()` |

```php
$app = new class { public bool $open = false; };
$view = fn() => VStack(spacing: 12)(
    Button('Toggle', action: fn() => $app->open = !$app->open),
    RoundedRectangle(cornerRadius: 12)->fill(Color::accentColor)->frame(width: 120, height: 80)
        ->offset(x: $app->open ? 100 : 0)->animation(Animation::snappy(), value: $app->open),
);
```

## Lifecycle, time and async

| SwiftUI | myphp |
|---|---|
| `.onAppear { }`, `.onDisappear { }` | the same |
| `.task { await load() }` | `->task(fn() => $app->load())`: a fiber, started as the view appears; inside it `Http::get()` waits without stopping the window |
| `Timer.publish`, `TimelineView(.periodic)` | `TimelineView()(fn(TimelineViewContext $context) => …)` |
| `@MainActor` | Everything already is: PHP runs the app on one thread. What waits goes in a fiber (`App::go()`), heavy CPU work in a child process (see [app.md](app.md)) |
| `URLSession.shared.data(from:)`, `URLRequest`, `JSONDecoder` | `[$data, $resp] = URLSession::shared()->data(from: $url)` (or `for: new URLRequest($url, 'POST', body: [...])`), `URLSession::shared()->json(from: $url)`, `URLSession::shared()->decode(User::class, from: $url)`, `upload()`, `download()`; or `Http::get()`, `Http::post()`, `Http::postJson()`, `Http::put()`, `Http::patch()`, `Http::delete()` |
| `scenePhase` | `App::onActivate()`, the window's events (see [The application](app.md)) |

<!-- no-test -->
```php
$app = new class {
    public array $items = []; public bool $loading = false;
    public function load(): void { $this->loading = true; $this->items = Http::get('https://example.com/items')->json(); $this->loading = false; }
};
$view = fn() => VStack()(
    $app->loading ? ProgressView() : ListView($app->items)(fn(string $item) => Text(verbatim: $item)),
)->task(fn() => $app->load());
```

## Beyond the views

What no view does: `Canvas(fn(GraphicsContext $g, CGSize $size) => …)`
draws, as SwiftUI's; `Representable(fn(Context $c) => …)` is a control of
your own, made with the engine's builder inside one view, as
`NSViewRepresentable` (see [Custom widgets](ui/custom-widgets.md)).

## Platform behavior you get for free

- **Light and dark mode**, the system's accent, higher contrast and larger
  text: the named colors and the text styles follow them. On phones,
  Dynamic Type (iOS's Text Size, Android's Font size) scales every
  `Font` style as the setting changes.
- **Reduce Motion** (iOS; Android's "Remove animations"): springs,
  transitions and page slides settle at once.
- **Right to left**: `leading` and `trailing` follow the language; rows
  mirror (see [Right to left](ui/right-to-left.md)).
- **Accessibility**: views are read by VoiceOver and TalkBack by their
  text and `accessibilityLabel` (see [Accessibility](ui/accessibility.md)).
- **On phones**: touch, the safe area, the keyboard, Back, the pickers,
  long press and swipes (see [Android and iOS](mobile.md)).

## Not in myphp

| SwiftUI | Status |
|---|---|
| `WidgetKit`, `App Intents`, `Live Activities` | Out of scope (OS extensions) |
| `tvOS`, `Android TV`, `visionOS`, `watchOS` | Not targeted (`macOS`, `Windows`, `Linux`, `Android` and `iOS`/`iPadOS` are supported; `Device::type()` reports `Desktop`, `Mobile`, `Pad`, `Tv`) |

## Checklist for an agent writing a myphp view

1. State is a class made once; the view is `view.php`, returning `fn() =>
   …`, `$this` the state (`Content::file()`). No named class or function
   in `view.php`.
2. Write SwiftUI's views and modifiers with SwiftUI's names and labels;
   the second parentheses hold the children, commas between them, `null`
   for no view.
3. Controls bind to the property itself (`isOn: $this->wifi`); actions
   change the state through its methods.
4. What repeats gets an id: `Each($items, id: 'id')`, `ListView($items,
   id: 'id')`.
5. Colors are the named ones (`Color::secondary`,
   `Color::secondarySystemBackground`); a literal only for data.
6. Before a view you have not seen, `grep -n "function Name("
   src/UI/Declarative/functions.php`; what is not there does not exist.
7. Run on a device with the CLI only: `myphp run -d macos|windows|linux|ios|android <app>`.
