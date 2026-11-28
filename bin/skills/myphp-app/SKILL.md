---
name: myphp-app
description: Write new PHP desktop (macOS, Windows, Linux) and mobile (Android, iOS, iPadOS) apps, windows and screens with MyPHP, native UI in SwiftUI-style declarative views or a web frontend, from a blank project to a tested, packaged app. Use when creating a MyPHP app, adding a window, page, view or service to one, or turning a design (SwiftUI or otherwise) into MyPHP code.
---

# Writing MyPHP apps

MyPHP draws SwiftUI-style declarative views directly on the GPU (Metal on macOS/iOS, Direct3D 11 on Windows, OpenGL/GLES on Linux/Android) in pure PHP 8.4+ / 8.5. Write SwiftUI's view names, argument labels, and modifiers in PHP syntax; never guess React, HTML, or CSS APIs.

## 1. Hard Rules: Zero Hand-Drawn Chrome (MyPHP 1.207.4+)

Follow these rules strictly. Do **not** invent custom styling, decorative cards, gradients, extra banners, or unrequested tabs. Let MyPHP's native Apple HIG defaults do the styling:

| Don't (anti-pattern — NEVER write) | Do (native SwiftUI default) |
|---|---|
| `WindowOptions(vibrancy: 'sidebar', titleBarStyle: 'hiddenInset', backgroundColor: ...)` | Omit all three — `TabView(.sidebarAdaptable)` and `NavigationSplitView` auto-enable `hiddenInset` + `sidebar` vibrancy on macOS and Mica on Windows 11 |
| Custom `$card = fn(...) => VStack(...)->background(...)->cornerRadius(12)->border(Color::separator)` | `Form()(Section(header:, footer:)(...))` |
| Hand-drawn top header `HStack(Text(...)->font(Font::title2), Spacer(), Button(...))` | `->navigationTitle('Title')` + `->toolbar(Button(...))` |
| Wrapping `TextField` or segmented `Picker` in `HStack(Text, Spacer, ...)` inside a `Form` | Put `TextField('Name', text: $this->name)` and `Picker('Preset', selection: $this->preset)(...)->pickerStyle(PickerStyle::segmented)` directly in `Section(...)` — desktop `Form` renders leading labels automatically (`->labelsHidden()` opts out) |
| Using `Stepper("Count: {$this->n}", ...)` for editable numbers | Use `NumberField('Count', value: $this->n, in: [1, 100], step: 1)` (`[ 45 ] [ − ] [ + ]`); use `Stepper` (adaptive horizontal `[ − | + ]`) only when direct typing is unwanted |
| Manual `chartXScale`/`chartYScale` single-point guards, or huge empty placeholder boxes | Write `Chart($data)(...)` directly (single points auto-widen & draw a dot); hide empty metric `Section`s or show `ContentUnavailableView` |
| Swift syntax (`Color.blue`, `.secondary`, `.infinity`, `->foregroundColor()`) | Valid PHP syntax (`Color::blue`, `Color::secondary`, `INF`, `->foregroundStyle()`) |
| Declaring `class` or named `function` in `view.php` / `pages/*.php` | Closures only in `view.php` & `pages/*.php`; put all classes/methods in `src/` |

## 2. Canonical Project Template (Copy This Shape)

Start with `myphp create <dir>` (or `myphp create -template 3d <dir>` for a 3D `SceneView` app):

```
my-app/
  myphp.json        {"name": "MyApp", "displayName": "My App", "identifier": "com.example.myapp", "entry": "app.php", "myphp": "1.208.0"}
  boot.php          loads MyPHP
  app.php           creates state, opens Window with minimal WindowOptions, calls App::run()
  src/AppState.php  plain PHP properties + action methods (no UI code)
  view.php          returns fn() => View (closures only, $this bound to AppState)
  pages/*.php       sub-pages for NavigationStack, each returning fn($param) => View
  tests/ui_test.php headless test run via `myphp test`
```

```php
<?php // src/AppState.php
declare(strict_types=1);

use MyPHP\Window;

final class AppState
{
    public string $tab = 'main';
    public string $name = 'Untitled';
    public string $preset = 'balanced';
    public int $epochs = 45;
    public bool $autoSave = true;
    /** @var list<array{id: int, text: string}> */
    public array $logs = [];
    public ?Window $window = null;

    public function run(): void
    {
        $this->logs[] = ['id' => count($this->logs) + 1, 'text' => "Ran {$this->name} ({$this->preset}, {$this->epochs})"];
    }
}
```

```php
<?php // view.php — $this is AppState (closures only)
declare(strict_types=1);

$main = fn() => Form()(
    Section(header: 'Settings', footer: 'Changes apply immediately.')(
        TextField('Name', text: $this->name, prompt: 'Untitled'),
        Picker('Preset', selection: $this->preset)(
            Text('Fast')->tag('fast'),
            Text('Balanced')->tag('balanced'),
            Text('Quality')->tag('quality'),
        )->pickerStyle(PickerStyle::segmented),
        NumberField('Epochs', value: $this->epochs, in: [5, 200], step: 5),
        Toggle('Auto-save', isOn: $this->autoSave)->toggleStyle(ToggleStyle::switch),
    ),
)->navigationTitle('Workspace')->toolbar(
    Button('Run', action: fn() => $this->run())->buttonStyle(ButtonStyle::borderedProminent),
);

$logs = fn() => ($this->logs === []
    ? ContentUnavailableView('No Logs', systemImage: 'terminal', description: 'Run a task to see output here.')
    : ListView($this->logs, id: 'id')(fn(array $row) => Text(verbatim: $row['text'])->monospaced())->listStyle(ListStyle::plain)
)->navigationTitle('Logs');

return fn() => TabView(selection: $this->tab)(
    Tab('Workspace', systemImage: 'slider.horizontal.3', value: 'main')($main()),
    Tab('Logs', systemImage: 'terminal', value: 'logs')($logs())->badge(count($this->logs)),
)->tabViewStyle(TabViewStyle::sidebarAdaptable);
```

```php
<?php // app.php
declare(strict_types=1);

use MyPHP\{App, Device, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/boot.php';
require __DIR__ . '/src/AppState.php';

$state = new AppState();
App::setName('My App');
App::whenReady(function () use ($state): void {
    [$w, $h] = Device::isDesktop() ? [960, 680] : [402, 874];
    // Leave vibrancy, titleBarStyle and backgroundColor unset — MyPHP configures them automatically.
    $state->window = new Window(new WindowOptions(
        title: 'My App',
        width: $w,
        height: $h,
        stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $state),
    ));
});
App::run();
```

## 3. SwiftUI -> MyPHP Reference

| SwiftUI | MyPHP |
|---|---|
| `VStack(spacing: 12) { … }` | `VStack(spacing: 12)( … )` — second `()` holds comma-separated children |
| `if cond { Text("a") }` | `$cond ? Text('a') : null` (`null` renders nothing; arrays flatten) |
| `ForEach(items, id: \.id) { … }` | `Each($items, id: 'id')(fn(array $item, int $i) => …)` |
| `List { … }` | `ListView($items, id: 'id')(fn($item) => …)` or `ListView()(Section()(…))` |
| `Link("Docs", destination: url)` | `WebLink('Docs', destination: $url)` |
| `@State` / binding | Direct state property (`isOn: $this->flag`), `State(false)`, or `new Binding(get:, set:)` |
| Fine-grained subtree | Child closure `fn() => Text("Count: {$this->count}")` (auto-tracks `$this->prop`) |
| `URLSession.shared` | `URLSession::shared()->data(for: $req)`, `->json()`, `->decode()`, `->upload()` |
| `AsyncImage(url:)` | `AsyncImage($url, content: fn($img) => $img->resizable()->scaledToFill(), placeholder: fn() => …)` |

### Core Views & Exact Named Signatures
- **Layout**: `VStack(alignment: HorizontalAlignment::leading, spacing: 12)(...)`, `HStack(alignment: VerticalAlignment::center, spacing: 8)(...)`, `ZStack(alignment: Alignment::center)(...)`, `LazyVStack`, `LazyHStack`, `Grid` + `GridRow` (`->gridCellColumns($n)`), `LazyVGrid` / `LazyHGrid` (`GridItem::adaptive(minimum:)`, `::flexible()`, `::fixed()`), `FlowLayout(spacing:)`, `ScrollView(Axis::vertical)(...)`, `ScrollViewReader`, `GeometryReader`, `HSplitView`, `VSplitView`, `Spacer`, `Divider`, `EmptyView`.
- **Navigation**: `TabView(selection: $this->tab)(Tab('Title', systemImage: 'house', value: 'home')(...))->tabViewStyle(TabViewStyle::sidebarAdaptable)` (or `TabViewStyle::page`), `NavigationStack(path: $this->path)(...)->navigationDestination(for: '/item/{id}', destination: __DIR__ . '/pages/item.php')`, `NavigationLink('Title', value: '/item/1')`, `NavigationSplitView(sidebar:, detail:)`, `->navigationTitle()`, `->toolbar(...)`, `->toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::tabBar | navigationBar)`, `->searchable(text: $this->query)`.
- **Form & Controls**: `Form`, `Section(header:, footer:)`, `LabeledContent('Key', $val)`, `Text` (`Text('Hi {name}', name: $n)` for i18n, `Text(verbatim: $raw)` for user/dynamic strings), `Label`, `Button('Save', action: ..., role: ButtonRole::destructive|cancel)` (`->buttonStyle(ButtonStyle::borderedProminent|bordered|plain|borderless)`), `Toggle('Title', isOn: $this->on)->toggleStyle(ToggleStyle::switch)`, `Picker('Title', selection: $this->x)(Text('A')->tag('a'))` (`PickerStyle::automatic|segmented|menu|radioGroup|inline`), `NumberField('Title', value: $this->n, in: [0, 100], step: 1)`, `DecimalField('Price', value: $this->p, in: ['0', '999'], step: '0.01')`, `Stepper('Title', value: $this->n, in: [0, 10], step: 1)`, `Slider(value: $this->v, in: [0.0, 1.0], step: 0.1)`, `RangeSlider`, `DatePicker`, `ColorPicker`, `TextField('Title', text: $this->s, prompt: 'Hint')` (`->onSubmit()`, `->keyboardType()`, `->labelsHidden()`), `SecureField`, `SearchField`, `TextEditor`, `EditableText`, `CodeEditor`, `Markdown`, `ProgressView(title:, value:, total:)`, `Gauge`, `ContentUnavailableView($title, systemImage:, description:)`, `DisclosureGroup`, `Accordion`, `GroupBox`, `Menu`, `ShareLink`, `PhotosPicker`, `Avatar`, `Chart($data)(fn($row) => [AreaMark(...), LineMark(...)])` (`BarMark`, `PointMark`, `RuleMark`, `SectorMark`).
- **Sizing, Modals & Accents**: `->frame(width:, height:, maxWidth: INF, alignment: Alignment::leading)`, `->padding(16)` or `->padding(Edge::horizontal, 16)`, `->sheet(isPresented: $this->open, content: fn() => ...)` (`->presentationDetents([PresentationDetent::medium(), PresentationDetent::large()])`), `->fullScreenCover()`, `->popover()`, `->alert('Title', isPresented: $this->open, actions: [Button('OK')], message: '...')`, `->confirmationDialog(...)`, `->contextMenu()`, `->swipeActions()`, `->refreshable()`, `->toast($this->toast)`.
- **System Colors & Fonts** (use sparingly — never hand-draw Form cards with `->background()`/`->border()`): `Color::primary`, `Color::secondary`, `Color::accentColor`, `Color::green`, `Color::orange`, `Color::red`, `Color::blue`; `Font::largeTitle`, `Font::title`, `Font::title2`, `Font::title3`, `Font::headline`, `Font::subheadline`, `Font::body`, `Font::callout`, `Font::footnote`, `Font::caption`, `Font::caption2`, `Font::system(size: 24, weight: Weight::bold)`.

*(Load `references/*.md` ONLY when needed: `references/async.md` for `URLSession`/SQLite/Secrets, `references/pitfalls.md` when debugging, `references/windows.md` for multi-window desktop apps.)*

## 4. Essential Rules (Avoid Common Bugs)

1. **Foundation & State**:
   - Use `URLSession::shared()` (`->data()`, `->json()`, `->decode()`, `->upload()`) inside `App::go()` fibers. State bound via `Content::file()` is auto-observed across fibers and timers — assign `$this->prop = ...` directly (no manual `$window->update()`).
   - Use `AsyncImage($url)` for remote images; for local images decoded with `MyPHP\UI\Image::fromBytes()`, decode once in state (never inside `view.php`).
   - For multi-color SVGs: `Image(SVG::parse($xml))->resizable()->scaledToFit()->frame(width: 44, height: 44)`.
   - Never mutate state or perform disk/network I/O while building `view.php`; only inside `action:`, `onSubmit`, `onAppear`, `task`, `onChange`.
2. **Bindings & Identity**:
   - Bind controls directly to state properties (`isOn: $this->items[$i]['done']`), never to a loop copy (`$item['done']`).
   - Always pass a stable `id:` to `Each($items, id: 'id')` and `ListView($items, id: 'id')`.
   - Keep a separate `Router` property per tab (`$this->homePath`, `$this->mePath`).
3. **3D, Games & Native Interop** (only when the app actually needs them):
   - 3D/2D games: use `SceneView` (`MyPHP\Plugins\Scene\*`) or `SpriteView` (`MyPHP\Plugins\Game\*`) — never re-implement camera/audio/transitions by hand.
   - Native C / ObjC / Android Java bindings: generate typed wrappers with `myphp ffigen` or `myphp jnigen` instead of hand-writing FFI/JNI glue.
4. **Assets & Packaging (`myphp build`)**:
   - Build with `myphp build -platform darwin/arm64|windows/amd64|linux/amd64|ios/arm64|android/arm64` (never run `scripts/*.sh` directly).
   - Resolve bundled files via `App::path(PathName::Resources) . '/file.png'` (with fallback to `dirname(__DIR__) . '/resources/file.png'`).
   - Exclude scratch directories via `"exclude": ["docs", "tests"]` in `myphp.json`; keep `resources/icon.png` `<= 1024×1024`.

## 5. Verify Before Finishing

1. **Static analysis**: run `myphp analyze` (checks `php -l`, view hot-reload rules, Swift/PHP typos, and SwiftUI anti-patterns — fix all errors and hints).
2. **Headless UI test**: run `myphp test` (runs `tests/*_test.php` on the installed MyPHP):
   ```php
   $h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 960, 680);
   $h->frame();
   $h->clickText('Run'); $h->frame();
   same(1, count($state->logs), 'ran');
   ```
   *(For `NavigationStack` page transitions, run `$h->frame(30)`.)*
3. **Visual screenshot check** (< 1s):
   Run `myphp screenshot -size 960x680 -out /tmp/shot.png .` and verify against this checklist:
   - Zero hand-drawn bordered cards (`background` + `cornerRadius` + `border(Color::separator)`) where `Form`/`Section` belongs;
   - Window title and toolbar buttons sit on the same unified titlebar row as the `sidebar.left` toggle;
   - `Form` controls (`TextField`, `Picker`, `NumberField`, `Stepper`, `Toggle`) use automatic leading labels with right-aligned controls — zero duplicate labels;
   - No large empty dark placeholder boxes when data/charts are empty (hide the `Section` or show `ContentUnavailableView`);
   - Only requested features and controls exist — zero unrequested decorations, gradients, or extra chrome.
4. **4-Platform Verification (`macOS`, `Windows`, `iOS`, `Android`)** when releasing:
   - **macOS**: `myphp screenshot -size 960x680 -out /tmp/macos.png .` and `myphp run -d macos .`.
   - **Windows**: sync to a Windows host/VM with PHP 8.4/8.5 (`ext-ffi`) + MinGW, run `myphp.cmd test`, `myphp.cmd build -skip-installer .`, and `myphp.cmd screenshot` inside an interactive desktop session (`schtasks /IT`).
   - **iOS / iPadOS**: `myphp devices` and `myphp run -d ios .`.
   - **Android**: `myphp emulators --launch <id>` (`Enforcing` SELinux) and `myphp run -d android .`.
