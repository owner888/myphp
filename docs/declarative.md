# Declarative UI (v1.178): the design

The developer's call (v1.177): myphp's UI is written as SwiftUI's is — views
are values, a view is a function of the state, containers take their content
as a list, actions are closures, controls bind to the state — with Flutter's
hot reload kept. The immediate-mode builder (`$c->column(); … $c->end();`,
`if ($c->clicked(…))`) becomes the engine's own, `@internal`: no example,
page of the docs or skill shows it. This file is the contract; where it and
the code disagree, the code is wrong. It goes once the work is done, its
content then in `docs/ui/` and `docs/swiftui.md`.

## The rule

Every SwiftUI view, modifier and pattern is written as SwiftUI writes it,
in PHP syntax: the same names, the same argument labels, the same order.
A difference needs a reason in PHP itself, listed under "Where PHP differs".
A myphp widget SwiftUI has no counterpart of (a terminal, a video player,
a chart of its own…) takes a SwiftUI-shaped name and its arguments as
SwiftUI would name them.

## Shape of an app

```php
<?php // app.php — the state, the windows
final class Counter
{
    public int $n = 0;
    public function reset(): void { $this->n = 0; } // logic lives in the state
}

$app = new Counter();
App::whenReady(fn() => new Window(new WindowOptions(title: 'Counter',
    content: Content::file(__DIR__ . '/view.php', $app))));
App::run();
```

```php
<?php // view.php — the view, $this the Counter; saved, it hot reloads
return fn() => VStack(spacing: 12)(
    Text("{$this->n}")->font(Font::largeTitle)->bold(),
    HStack()(
        Button('−', action: fn() => $this->n--),
        Button('Reset', action: fn() => $this->reset())->disabled($this->n === 0),
        Button('+', action: fn() => $this->n++)->buttonStyle(ButtonStyle::borderedProminent),
    ),
    $this->n > 0 ? Text('positive')->foregroundStyle(Color::secondary) : null,
)->padding();
```

- A view file returns a closure that returns a `View`, bound to the state
  (`ViewFile`); its parts are closures made before the `return`
  (`$row = fn(Todo $t) => HStack()(…);`), as SwiftUI's subviews are
  structs. Pages of a `NavigationStack` are files of `pages/`, given the
  path's parts by name and type.
- The body runs for every frame, as SwiftUI's runs for every change: it only
  reads the state and returns views. Actions change the state.

## Where PHP differs (the only differences)

| SwiftUI | myphp | Why |
|---|---|---|
| `VStack(spacing: 12) { … }` | `VStack(spacing: 12)( … )` | No trailing closures; the second parentheses are the braces |
| Children on lines, no separators | Children separated by commas | A PHP argument list |
| `if cond { Text("a") }` in a builder | `$cond ? Text('a') : null` | No result builders: `null` is no view, an array is several |
| `ForEach(items, id: \.id) { … }` | `Each($items, id: 'id')(fn(Item $i) => …)` | `foreach` is a PHP keyword, any case |
| `List { … }` | `ListView( … )` | `list` is a PHP keyword |
| `Link("Docs", destination: url)` | `WebLink('Docs', destination: $url)` | PHP has a built-in `link()`, any case |
| `@State var n = 0` in the view | A property of the state class | A view is a closure, rebuilt every frame: state lives in the state object |
| `$binding` | The property itself: `Toggle('Wi-Fi', isOn: $this->wifi)` | PHP passes by reference |
| `.font(.largeTitle)` | `->font(Font::largeTitle)` | No implicit member expressions: the enum is named |
| `"\(n)"` | `"{$this->n}"` | PHP's interpolation |
| `struct Row: View` | `$row = fn(Todo $t) => …` in the view file | A class cannot be loaded twice: a closure hot reloads |

`VStack`, `Text`, `Button`… are global functions and `Font`, `Color`,
`Alignment`… global enums (`src/UI/Declarative/functions.php`, loaded with
the framework): no `use` line, as SwiftUI needs no import beyond
`SwiftUI`.

The colors that follow the theme, light or dark, by iOS's names: SwiftUI's
`Color::primary`, `::secondary`, `::accentColor`, `::background`,
`::separator`; UIColor's (`Color(.secondarySystemBackground)` in SwiftUI)
`::systemBackground`, `::secondarySystemBackground` (a field's or a card's
surface), `::systemGroupedBackground`, `::secondarySystemGroupedBackground`,
`::label`, `::secondaryLabel`, `::link`, and the grays `::systemGray` to
`::systemGray6`; the system's `::red`, `::blue`… `Color::rgb(0x336699)` is one
of the app's own.

## The views

Layout: `VStack(alignment:, spacing:)`, `HStack(alignment:, spacing:)`,
`ZStack(alignment:)`, `Spacer(minLength:)`, `Divider()`, `Grid`/`GridRow`,
`LazyVGrid(columns:)`, `ScrollView(axes)`, `Group`, `GeometryReader`.
Content: `Text`, `Label(title, systemImage:)`, `Image(…)`, `Color`, shapes
(`Rectangle`, `RoundedRectangle(cornerRadius:)`, `Circle`, `Capsule`),
`ProgressView`, `Gauge`, `Canvas`.
Controls: `Button(title, action:)`, `Toggle(title, isOn:)`,
`TextField(title, text:)`, `SecureField`, `TextEditor(text:)`,
`Picker(title, selection:)( … ->tag(v))`, `Slider(value:, in:)`,
`Stepper(title, value:, in:)`, `DatePicker`, `ColorPicker`, `Menu`,
`WebLink`, `NavigationLink(title, value:)`.
Collections: `ListView`, `Each`, `Section(header:)`, `Form`, `Table`,
`OutlineGroup`, `DisclosureGroup(title, isExpanded:)`.
Navigation: `NavigationStack(path:)( root )` with
`->navigationDestination([pattern => page])`, `NavigationSplitView`,
`TabView(selection:)( Tab(title, systemImage:, value:)( … ) )`.
Charts, as Swift Charts': `Chart($data)(fn($item) => BarMark(x:, y:))`, or
`Chart()(marks…)`, of `BarMark`, `LineMark`, `AreaMark(stacking:)`,
`PointMark`, `RuleMark`, `SectorMark(angle:, innerRadius:, outerRadius:,
angularInset:)`, `LinePlot(x: $xs, y: $ys)` (vectorized: tens of thousands of
points a frame, the extremes of each pixel column painted), each position a `PlottableValue::value('Month', $m)` or a
bare value; marks take `foregroundStyle(by:)`, `cornerRadius`, `opacity`,
`interpolationMethod`, `lineStyle(new StrokeStyle(lineWidth:))`,
`symbolSize`, `annotation(fn() => …)`; the chart `chartXSelection(value:)`,
`chartAngleSelection(value:)`, `chartXScale(domain:)`, `chartYScale(domain:)`,
`chartXAxis(new AxisMarks(…))`, `chartYAxis(…)`, `chartLegend(.hidden)`,
`chartForegroundStyleScale([name => color])`. A string is a category, a
date time, a number a linear scale; bars and areas start at 0 and stack;
the y axis's labels are trailing.
`TimelineView()(fn(TimelineViewContext $context) => …)` builds its view anew every
frame, as SwiftUI's `TimelineView(.animation)`.
myphp's own, SwiftUI-shaped: `VideoPlayer`, `WebView(page)`, `TextEditor`
over rich text, `TerminalView`, `CodeEditor`, `Markdown`, `SearchField(text:)`.
myphp's own controls AppKit has and SwiftUI has not: `ComboBox(title, text:,
items:)`, `TokenField(title, tokens:, suggestions:)`, `Rating(title, value:)`,
`RangeSlider(lower:, upper:, in:, step:)`, `Breadcrumbs(path, selection:)`,
`Avatar(name)`; and the modifier `toast($message)`.

## One app, every screen: TabView(.sidebarAdaptable)

An app of a few top-level pages (Mail's mailboxes, Settings' panes, a
companion app's Devices, Connection and Log) writes them once as a
`TabView` with `.tabViewStyle(.sidebarAdaptable)`, as SwiftUI does on
iOS 18 and macOS 15, and gets each screen's own navigation:

| Screen | The tabs show as |
|---|---|
| Desktop (macOS, Linux, Windows) | a `NavigationSplitView`: the sidebar of the tabs' titles, icons and badges, its button hiding it, beside the chosen tab, its `navigationTitle` and `toolbar` in the window's title bar |
| Tablet on its side (iPad, Android tablet) | the same sidebar |
| Tablet held upright | tabs across the top, as iPadOS 18's |
| Phone (narrower than 600 or lower than 500 points) | the tab bar across the bottom |

```php
return fn() => TabView(selection: $this->page)(
    Tab('Devices', systemImage: 'airpods', value: 'devices')($devices()),
    Tab('Connection', systemImage: 'antenna.radiowaves.left.and.right', value: 'connection')($connection()),
    Tab('Log', systemImage: 'list.bullet.rectangle', value: 'log')($log())->badge(count($this->packets)),
)->tabViewStyle(TabViewStyle::sidebarAdaptable);
```

Each tab's views are a page: a `Form`, a `ListView`, or a `NavigationStack`
for pages under it, with `->navigationTitle()` and `->toolbar()`. For the
look of the Mac's own apps, open the window with
`titleBarStyle: 'hiddenInset', vibrancy: 'sidebar'`: the window controls
over the sidebar, its material showing through. `NavigationSplitView`
itself is for a sidebar of the app's data (Notes' notes, Finder's places)
rather than of its pages: a desktop's, as SwiftUI's on the Mac.

## The modifiers

`padding(edges, length)`, `frame(width:, height:, minWidth:, maxWidth:,
alignment:)` (`maxWidth: INF` fills), `font`, `fontWeight`, `bold`,
`italic`, `foregroundStyle`, `background`, `overlay`, `clipShape`,
`cornerRadius`, `shadow`, `opacity`, `border`, `disabled`, `hidden`,
`id`, `tag`, `onTapGesture`, `onAppear`, `onDisappear`, `task` (a fiber),
`onChange(of:)`, `onSubmit`, `keyboardShortcut`, `help`,
`accessibilityLabel`, `sheet(isPresented:)`, `alert(title, isPresented:,
actions:, message:)`, `confirmationDialog`, `popover(isPresented:)`,
`contextMenu`, `toolbar`, `navigationTitle`, `searchable(text:)`,
`refreshable`, `swipeActions`, `listStyle`, `buttonStyle`, `toggleStyle`,
`pickerStyle`, `textFieldStyle`, `tint`, `animation(_, value:)`,
`transition`, `matchedGeometryEffect`, `environment`, `rotationEffect`,
`grayscale`, `textInputSuggestions` (a TextField's), `onMove` (a
ListView's), `tabViewStyle(.sidebarAdaptable)`. A Picker in a Menu shows its
choices inline, the chosen one checked; `Toggle(title, sources: [&$a, &$b])`
is mixed while some are on.

`popover(isPresented:, arrowEdge:)` shows outside the window, as SwiftUI's on a
Mac: in a flyout (`Window::flyout`, an NSPopover on macOS) next to the view, at
its content's ideal size; give the content a `frame(width:)` to wrap long text.
In a view of no window (a test's), it shows inside it.

## The engine

`View` values are rendered every frame by `View::render(Context)` into the
immediate-mode builder, which keeps doing layout, hit-testing, focus,
accessibility, text, painting and the GPU: a `Button`'s action runs when
`$c->clicked()` of its node is true; a binding is a PHP reference the
control writes; a `TextField`'s cursor, selection and input method live in
a `TextState` the runtime keeps by the field's identity (`Runtime::local`);
inherited modifiers (`font`, `foregroundStyle`, `tint`, `disabled`) flow
down through an environment. `Each`'s `id` keys its rows (`Context::key`).

Sizing is SwiftUI's: a flexible view (`Spacer`, `ListView`, `ScrollView`,
`NavigationStack`, `Divider`, a `frame(maxWidth: INF)`) takes the room it is
offered, and a stack or box around it becomes flexible the same way
(`View::fill`), so an `HStack` of a sidebar and a `NavigationStack` fills
the window; a `frame(width:)` fixes the width over it.

## The work, in order

1. Core: `View`, the environment, `VStack`/`HStack`/`ZStack`/`Spacer`/
   `Divider`/`Text`/`Button`/`Toggle`/`TextField`/`Image`/`Each`, the
   common modifiers; examples/counter-native; tests.
2. Collections and navigation: `ListView`, `Section`, `Form`, `ScrollView`,
   `NavigationStack`, `TabView`, `sheet`, `alert`; examples/mobile and
   router (todo is a web example). Done.
3. Every other view and modifier; every example.
4. The docs (`docs/ui/`, `docs/swiftui.md`), the skill, the template; the
   builder `@internal`; this file folded in and removed.
