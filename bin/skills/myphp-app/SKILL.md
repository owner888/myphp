---
name: myphp-app
description: Write new PHP desktop (macOS, Windows, Linux) and mobile (Android, iOS, iPadOS) apps, windows and screens with MyPHP, native UI in SwiftUI-style declarative views or a web frontend, from a blank project to a tested, packaged app. Use when creating a MyPHP app, adding a window, page, view or service to one, or turning a design (SwiftUI or otherwise) into MyPHP code.
---

# Writing MyPHP apps

MyPHP draws SwiftUI-style declarative views directly on the GPU (Metal on macOS/iOS, Direct3D 11 on Windows, OpenGL/GLES on Linux/Android) in pure PHP 8.3+. Write SwiftUI's view names, argument labels, and modifiers in PHP syntax; never guess React, HTML, or CSS APIs.

## 1. Quick Reference: SwiftUI -> MyPHP

| SwiftUI | MyPHP (`src/UI/Declarative/functions.php`) |
|---|---|
| `VStack(spacing: 12) { … }` | `VStack(spacing: 12)( … )` — second `()` holds children, comma-separated |
| `if cond { Text("a") }` | `$cond ? Text('a') : null` (`null` renders nothing; arrays flatten) |
| `ForEach(items, id: \.id) { … }` | `Each($items, id: 'id')(fn(array $item, int $i) => …)` |
| `List { … }` | `ListView($items, id: 'id')(fn($item) => …)` or `ListView()(Section()(…))` |
| `Link("Docs", destination: url)` | `WebLink('Docs', destination: $url)` (or `Link(...)`) |
| `@State var x` / `$x` binding | State class property; pass by reference: `Toggle('Wi-Fi', isOn: $this->wifi)` |
| `.font(.largeTitle)` | `->font(Font::largeTitle)`, `->foregroundStyle(Color::secondary)` |
| `URLSession.shared` | `URLSession::shared()->data(for: $req)`, `->json()`, `->decode()`, `->upload()` |
| `AsyncImage(url:)` | `AsyncImage($url, content: fn($img) => $img->resizable()->scaledToFill(), placeholder: fn() => …)` |

### Core Views & Modifiers Cheat Sheet (no need to read extra docs for standard UI)
- **Layout**: `VStack`, `HStack` (`VerticalAlignment::top|center|bottom|firstTextBaseline`), `ZStack`, `LazyVStack`, `LazyHStack`, `Grid` + `GridRow` (`->gridCellColumns($n)`), `LazyVGrid` / `LazyHGrid` (`GridItem::adaptive(minimum:)`, `GridItem::flexible()`, `GridItem::fixed()`), `FlowLayout(spacing:)` (wrapping tags/chips), `ViewThatFits`, `ScrollView` + `ScrollViewReader`, `GeometryReader`, `HSplitView`, `VSplitView`, `Spacer`, `Divider`, `EmptyView`.
- **Navigation & Bars**: `TabView(selection: $this->tab)(Tab('Home', systemImage: 'house', value: 'home')(…))->tabViewStyle(TabViewStyle::sidebarAdaptable)` (or `TabViewStyle::page` carousel), `NavigationStack(path: $this->path)(…)->navigationDestination(for: '/item/{id}', destination: __DIR__ . '/pages/item.php')`, `NavigationLink('Title', value: '/item/1')`, `NavigationSplitView(sidebar:, detail:)`, `->navigationTitle()`, `->toolbar(...)`, `->toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::tabBar | navigationBar)`, `->searchable(text: $this->query)`.
- **Controls & Data**: `Text` (`Text('Hi {name}', name: $n)` for i18n, `Text(verbatim: $raw)` for user data), `Label`, `Button` (`ButtonStyle::borderedProminent|bordered|plain|borderless`, `ButtonRole::destructive|cancel`), `Toggle`, `Picker` (`PickerStyle::segmented|menu|radioGroup|inline`), `DatePicker` (`DatePickerStyle::graphical|compact`), `ColorPicker`, `Slider`, `RangeSlider`, `Stepper`, `TextField` (`->onSubmit()`, `->keyboardType()`), `SecureField`, `SearchField`, `TextEditor`, `CodeEditor`, `Markdown`, `ProgressView`, `Gauge`, `ContentUnavailableView($title, systemImage:, description:)` (`::search($q)`), `OutlineGroup`, `Accordion` (exclusive collapsible sections), `DisclosureGroup`, `ZoomableView` (double-tap/drag zoom container), `Form`, `Section`, `GroupBox`, `LabeledContent`, `ControlGroup`, `Menu`, `ShareLink`, `PhotosPicker`, `Avatar`, `Chart` (`BarMark`, `LineMark`, `AreaMark`, `PointMark`, `RuleMark`, `SectorMark`).
- **Shapes, Gradients & Modals**: `Rectangle`, `RoundedRectangle($r)`, `Circle`, `Capsule`, `Ellipse`, `LinearGradient`, `RadialGradient`, `AngularGradient`, `->background()`, `->overlay()`, `->clipShape()`, `->glassEffect()`, `->sheet(isPresented:, content:)` (`->presentationDetents()`, `->interactiveDismissDisabled()`), `->fullScreenCover()`, `->popover()`, `->alert()`, `->confirmationDialog()`, `->fileImporter()`, `->fileExporter()`, `->contextMenu()`, `->swipeActions()`, `->refreshable()`, `->toast($this->toast)`.

### Exact Parameter Signatures (PHP named arguments must match these)
- **Containers**: `VStack(alignment: HorizontalAlignment::leading, spacing: 12)( ... )`, `HStack(alignment: VerticalAlignment::center, spacing: 8)( ... )`, `ZStack(alignment: Alignment::center)( ... )`, `ScrollView(Axis::vertical)( ... )`.
- **Sizing & Padding**: `->frame(width: 48, height: 48)` or `->frame(maxWidth: INF, alignment: Alignment::leading)`; `->padding(16)` or `->padding(Edge::horizontal, 16)->padding(Edge::top, 8)`.
- **Styling**: `->background(Color::secondarySystemGroupedBackground)` or `->background(LinearGradient(colors: [Color::blue, Color::purple], startPoint: UnitPoint::topLeading, endPoint: UnitPoint::bottomTrailing))`; `->cornerRadius(16)` or `->clipShape(Capsule())` / `->clipShape(Circle())` / `->clipShape(RoundedRectangle(cornerRadius: 12))`.
- **Colors & Fonts**: `Color::primary`, `Color::secondary`, `Color::systemBackground`, `Color::secondarySystemBackground`, `Color::systemGroupedBackground`, `Color::secondarySystemGroupedBackground`, `Color::tertiarySystemGroupedBackground`, `Color::rgb(0x00CAA4)`; `Font::largeTitle`, `Font::title`, `Font::title2`, `Font::title3`, `Font::headline`, `Font::subheadline`, `Font::body`, `Font::callout`, `Font::footnote`, `Font::caption`, `Font::caption2`, `Font::system(size: 24, weight: Weight::bold)`.
- **Modals**: `->sheet(isPresented: $this->open, content: fn() => ...)` (`->presentationDetents([PresentationDetent::medium(), PresentationDetent::large()])`, `->interactiveDismissDisabled()`), `->alert('Title', isPresented: $this->alertOpen, actions: [Button('OK')], message: '...')`, `->confirmationDialog('Title', isPresented: $this->confirmOpen, actions: [Button('Delete', role: ButtonRole::destructive, action: ...), Button('Cancel', role: ButtonRole::cancel)])`.

*(Load `references/*.md` ONLY when needed: `references/async.md` for `URLSession`/SQLite/Secrets, `references/pitfalls.md` when debugging, `references/windows.md` for multi-window desktop apps, `references/web.md` for Vite web frontends.)*

## 2. Project & Code Architecture

Start a project with `myphp init -template native <dir>`. Keep state & actions in `src/`, and pure closures in `view.php` and `pages/*.php` for instant hot reload (`Content::file()` binds `$this` to your state object):

```
my-app/
  myphp.json        {"name": "MyApp", "displayName": "我的应用", "identifier": "com.example.app", "entry": "app.php", "framework": "../myphp"}
  boot.php          loads MyPHP
  app.php           creates state, sizes Window via Device::isDesktop(), runs App::run()
  src/Todos.php     state properties + action methods (no UI nodes)
  view.php          returns fn() => View (closures only — never declare classes/functions here)
  pages/*.php       sub-pages for NavigationStack, each returning fn($param) => View
  tests/ui_test.php headless test driving views and actions
```

```php
<?php // src/Todos.php
declare(strict_types=1);

use MyPHP\{App, URLRequest, URLSession, Window};

final class Todos
{
    /** @var list<array{id: int, title: string, done: bool}> */
    public array $items = [];
    public string $draft = '';
    public ?Window $window = null;
    private int $next = 1;

    public function add(): void
    {
        $title = trim($this->draft);
        if ($title === '') { return; }
        $this->items[] = ['id' => $this->next++, 'title' => $title, 'done' => false];
        $this->draft = '';
    }

    public function remove(int $id): void
    {
        $this->items = array_values(array_filter($this->items, fn(array $i) => $i['id'] !== $id));
    }
}
```

```php
<?php // view.php — $this is Todos
declare(strict_types=1);

$row = fn(array $item, int $i) => Toggle($item['title'], isOn: $this->items[$i]['done'])
    ->swipeActions(Button('Delete', role: ButtonRole::destructive, action: fn() => $this->remove($item['id'])));

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    HStack(spacing: 8)(
        TextField('New to-do', text: $this->draft)->onSubmit(fn() => $this->add()),
        Button('Add', action: fn() => $this->add())->buttonStyle(ButtonStyle::borderedProminent)->disabled(trim($this->draft) === ''),
    ),
    $this->items === []
        ? ContentUnavailableView('No To-Dos', systemImage: 'checklist', description: 'Add a task above.')
        : ListView($this->items, id: 'id')($row),
)->padding(16);
```

```php
<?php // app.php
declare(strict_types=1);

use MyPHP\{App, Device, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/boot.php';
require __DIR__ . '/src/Todos.php';

$app = new Todos();
App::setName('Todos');
App::whenReady(function () use ($app) {
    [$w, $h] = Device::isDesktop() ? [1080, 760] : [402, 874];
    $app->window = new Window(new WindowOptions(title: 'Todos', width: $w, height: $h,
        stateKey: 'main', // remembers window size, position, maximized/fullScreen across runs
        content: Content::file(__DIR__ . '/view.php', $app)));
});
App::run();
```

## 3. Essential Rules (Avoid Common Bugs)

1. **SwiftUI / Foundation APIs first**:
   - **Networking**: Always use `URLSession::shared()->data(for: $req)`, `->json()`, `->decode()`, and `->upload(for: $req, from: ['file' => $path])` (native `multipart/form-data`, never base64-encode files into JSON) inside `App::go()` fibers, then update state via `$this->window->update(fn() => ...)`.
   - **Remote Images**: Always use `AsyncImage($url)` (built-in fiber download & memory cache), never manual `Image::fromBytes()` downloads.
   - **Custom SVGs**: `Image($svg)` without `->resizable()` renders as a monochrome template icon. For multi-color SVGs/illustrations, always call `Image(SVG::parse($xml))->resizable()->scaledToFit()->frame(width: 44, height: 44)`.
2. **Adaptive Multi-Platform Navigation**:
   - Use one `TabView(selection: $this->tab)` with `->tabViewStyle(TabViewStyle::sidebarAdaptable)` and `->tint($accent)`: it automatically becomes a left sidebar on macOS/Windows/Linux and landscape iPad, and a bottom tab bar on iPhone/Android.
   - Keep a separate `Router` property per tab (`$this->homePath`, `$this->mePath`).
   - Hide the floating phone tab bar on detail pages with `->toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::tabBar)`, and hide the top navigation bar on custom hero pages with `->toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::navigationBar)`.
3. **Bindings & Identity**:
   - Bind controls directly to state properties (`isOn: $this->items[$i]['done']`), never to a loop variable copy (`$item['done']`).
   - Always pass a stable key to `Each($items, id: 'id')` and `ListView($items, id: 'id')`.
   - Never mutate state or perform I/O inside `view.php` while building; only inside action/lifecycle closures (`action:`, `onSubmit`, `onAppear`, `task`, `onChange`).
4. **Packaging (`myphp.json`)**:
   - Mobile builds automatically exclude `tests/`, `test/`, and `*.local.php`. Put local environment overrides in `config.env.php` (gitignored). Use an ASCII slug for `"name"` and put Chinese/localized titles in `"displayName"`.

## 4. Verify Before Finishing

1. **Syntax check**: `php -l` on every modified PHP file.
2. **Headless UI test** (`php -d ffi.enable=1 tests/ui_test.php`):
   ```php
   $h = new Headless(Content::file(__DIR__ . '/../view.php', $app)->view, 402, 874);
   $h->rt->touch = true; $h->rt->strict = true;
   $h->frame();
   $h->clickText('New to-do'); $h->type('Milk'); $h->enter(); $h->frame();
   same(1, count($app->items), 'added');
   ```
   *(Note: when navigating between pages in `NavigationStack`, run `$h->frame(30)` so the slide transition completes.)*
3. **Visual screenshot check** (< 1s):
   Run `myphp screenshot -data /tmp/test-data -out /tmp/shot.png` (or via a `tests/preview.php` with `FakeApi` data; do not pass `-size` taller than ~800pt on a laptop screen) and inspect the PNG for layout, contrast, and alignment.
4. **Device / Simulator run**:
   Use `myphp devices` and `myphp run -d ios|android|macos .` (never raw `xcrun` or `adb` scripts).