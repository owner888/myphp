---
name: myphp-app
description: Write PHP desktop (macOS, Windows, Linux) and mobile (iOS, iPadOS, Android) apps with MyPHP's native GPU SwiftUI-style declarative views, from project creation to headless tests, screenshots, and packaging.
---

# Writing MyPHP Apps

MyPHP renders SwiftUI-style declarative views directly on the GPU in pure PHP 8.4+/8.5 (`src/UI/Declarative/functions.php`). Use SwiftUI's view names and modifiers in **valid PHP syntax** — never invent React, HTML, or CSS APIs.

## 1. Canonical App Shape (Copy This First)

Create a project with `myphp create <dir>` (or `-template 3d` for 3D `SceneView`):

```
my-app/
  myphp.json        {"name":"MyApp","displayName":"My App","identifier":"com.example.myapp","entry":"app.php","myphp":"1.208.3"}
  boot.php          loads MyPHP
  app.php           creates state, opens Window, calls App::run()
  src/AppState.php  plain PHP state properties & actions (no UI code)
  view.php          returns fn() => View (closures ONLY, $this = AppState)
  pages/*.php       NavigationStack sub-pages, each returning fn($param) => View
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
    public int $count = 10;
    public bool $autoSave = true;
    /** @var list<array{id: int, text: string}> */
    public array $logs = [];
    public ?Window $window = null;

    public function run(): void
    {
        $this->logs[] = ['id' => count($this->logs) + 1, 'text' => "Ran {$this->name} ({$this->preset}, {$this->count})"];
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
        )->pickerStyle(PickerStyle::segmented),
        NumberField('Count', value: $this->count, in: [1, 100], step: 1),
        Toggle('Auto-save', isOn: $this->autoSave)->toggleStyle(ToggleStyle::switch),
    ),
)->navigationTitle('Workspace')->toolbar(
    Button('Run', action: fn() => $this->run())->buttonStyle(ButtonStyle::borderedProminent),
);

$logs = fn() => ($this->logs === []
    ? ContentUnavailableView('No Logs', systemImage: 'terminal', description: 'Run a task to see output.')
    : ListView($this->logs, id: 'id')(fn(array $r) => Text(verbatim: $r['text'])->monospaced())->listStyle(ListStyle::plain)
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
    $state->window = new Window(new WindowOptions(
        title: 'My App', width: $w, height: $h, stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $state),
    ));
});
App::run();
```

## 2. Hard Rules (Never Violate)

1. **Zero hand-drawn chrome**: Never write `$card` helpers (`->background()->cornerRadius()->border(Color::separator)`) or manual top headers (`HStack(Title, Spacer, Button)`). Use `Form()(Section(header:, footer:)(...))`, `->navigationTitle()`, and `->toolbar(...)`.
2. **Minimal `WindowOptions`**: Never pass `vibrancy: 'sidebar'`, `titleBarStyle: 'hiddenInset'`, or `backgroundColor` for standard apps — `TabView(.sidebarAdaptable)` and `NavigationSplitView` enable native macOS vibrancy/titlebar and Win11 Mica automatically.
3. **Automatic `Form` labels**: Put `TextField('Label', text: ...)`, `Picker('Label', ...)->pickerStyle(PickerStyle::segmented)`, `NumberField(...)`, `Stepper(...)`, and `Toggle(...)` directly in `Section(...)`. Never wrap them in `HStack(Text, Spacer, ...)` (use `->labelsHidden()` to opt out).
4. **PHP syntax only**: Use `Color::secondary`, `Font::headline`, `INF`, `->foregroundStyle()` — **never** Swift syntax (`Color.blue`, `.secondary`, `.infinity`, `->foregroundColor()`).
5. **Closures only in views**: Never declare `class` or named `function` inside `view.php` or `pages/*.php` (breaks hot reload). Put classes/methods in `src/`.
6. **Direct bindings & stable keys**: Bind directly to state (`isOn: $this->items[$i]['done']`, never a loop copy `$item['done']`). Always pass `id:` to `Each($items, id: 'id')` and `ListView($items, id: 'id')`.
7. **Pure view build**: Never mutate state or do disk/network I/O while building `view.php`; only inside callbacks (`action:`, `onSubmit`, `onAppear`, `task`, `onChange`).
8. **Built-in I/O & images**: Use `URLSession::shared()` inside `App::go()` fibers (state bound via `Content::file()` auto-updates UI — no manual refresh). Use `AsyncImage($url)` for remote images; decode local `MyPHP\UI\Image::fromBytes()` once in state. Resolve bundled assets via `App::path(PathName::Resources) . '/file.png'`.
9. **Empty states & charts**: Write `Chart($data)(...)` directly (single points auto-scale). Hide empty metric `Section`s or show `ContentUnavailableView` — never draw big empty placeholder boxes.
10. **Minimal scope**: Build **only** the screens, controls, and actions requested. Never add unrequested gradients, decorative banners, custom colors, or extra tabs.

## 3. Common Views & Exact Signatures

- **Syntax**: `Container(args)(child1, child2, ...)` — `$cond ? View : null` (`null` ignored; arrays flatten). Use `Text(verbatim: $raw)` for user/dynamic strings.
- **Layout**: `VStack(alignment: HorizontalAlignment::leading, spacing: 12)(...)`, `HStack(alignment: VerticalAlignment::center, spacing: 8)(...)`, `ZStack(alignment: Alignment::center)(...)`, `ScrollView(Axis::vertical)(...)`, `LazyVGrid(columns: [GridItem::adaptive(minimum: 160)])(...)`, `HSplitView`, `Spacer`, `Divider`.
- **Navigation**: `TabView(selection: $this->tab)(Tab('Title', systemImage: 'house', value: 'home')(...))->tabViewStyle(TabViewStyle::sidebarAdaptable)`, `NavigationStack(path: $this->path)(...)->navigationDestination(for: '/item/{id}', destination: __DIR__ . '/pages/item.php')`, `NavigationLink('Title', value: '/item/1')`, `NavigationSplitView(sidebar:, detail:)`, `->navigationTitle()`, `->toolbar(...)`, `->searchable(text: $this->query)`.
- **Form & Controls**: `Form`, `Section(header:, footer:)`, `LabeledContent('Key', $val)`, `Text`, `Label('Title', systemImage: 'star')`, `Button('Save', action: ..., role: ButtonRole::destructive|cancel)` (`->buttonStyle(ButtonStyle::borderedProminent|bordered|plain|borderless)`), `TextField('Title', text: $this->s, prompt: 'Hint')` (`->onSubmit()`), `SecureField`, `TextEditor`, `NumberField('Qty', value: $this->n, in: [0, 100], step: 1)`, `DecimalField`, `Stepper`, `Slider(value: $this->v, in: [0.0, 1.0], step: 0.1)`, `Toggle('On', isOn: $this->on)->toggleStyle(ToggleStyle::switch)`, `Picker('Mode', selection: $this->m)(Text('A')->tag('a'))` (`PickerStyle::automatic|segmented|menu|radioGroup`), `DatePicker`, `ProgressView(title:, value:, total:)`, `ContentUnavailableView($title, systemImage:, description:)`, `ListView($items, id: 'id')(fn($item, $i) => ...)`, `Each($items, id: 'id')(...)`, `Image($img)->resizable()->scaledToFit()`, `AsyncImage($url)`, `Chart($rows)(fn($r) => [AreaMark(...), LineMark(...)])`.
- **Modals & Modifiers**: `->frame(width:, height:, maxWidth: INF, alignment: Alignment::leading)`, `->padding(16)`, `->disabled($bool)`, `->sheet(isPresented: $this->open, content: fn() => ...)`, `->alert('Title', isPresented: $this->open, actions: [Button('OK')], message: '...')`, `->confirmationDialog(...)`, `->swipeActions(...)`, `->contextMenu(...)`, `->refreshable(...)`, `->toast($this->toast)`, `Dialog::open(new OpenDialogOptions(directory: true))`.
- **Colors & Fonts**: `Color::primary|secondary|accentColor|green|orange|red|blue`; `Font::largeTitle|title|title2|title3|headline|subheadline|body|caption`.

*(Load `references/*.md` ONLY if needed: `references/widgets.md` for Table/3D SceneView/Game/ffigen/all controls, `references/async.md` for URLSession/SQLite/Secrets, `references/windows.md` for multi-window/menus/tray, `references/pitfalls.md` for debugging.)*

## 4. Verify & Package

1. `myphp analyze` — checks `php -l`, view hot-reload rules, Swift/PHP typos, and SwiftUI hints (must report **0 errors, 0 hints**).
2. `myphp test` — runs headless `tests/*_test.php`:
   ```php
   $h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 960, 680);
   $h->frame();
   $h->clickText('Run'); $h->frame();
   same(1, count($state->logs), 'ran');
   ```
3. `myphp screenshot -size 960x680 -out /tmp/shot.png .` — inspect PNG: no bordered hand-drawn cards, title/toolbar aligned with `sidebar.left`, automatic Form labels (no duplicates), no empty dark placeholder boxes, no unrequested extras.
4. Package with `myphp build -platform darwin/arm64|windows/amd64|linux/amd64|ios/arm64|android/arm64` (Windows builds run on Windows via `myphp.cmd build`). Mobile dev: `myphp run -d ios|android .`, live logs: `myphp logs`.
