# MyPHP pitfalls

What agents get wrong most often, and what MyPHP does not have even though
SwiftUI or other UI toolkits do. When the docs and this list
disagree, the docs of the checkout win: it may have grown since.

## Mistakes that compile and misbehave

- **State in the view.** A variable set in the view's closure (`$open =
  false;`) is new every frame: a toggle bound to it never sticks. What the
  app keeps is a property of the state class.
- **Binding to a copy.** `Each($this->items)(fn(array $item) =>
  Toggle($item['title'], isOn: $item['done']))` edits the closure's copy.
  Bind the property: `isOn: $this->items[$i]['done']`, `$i` the row's
  index, the second argument `Each` and `ListView` give.
- **Rows without an id.** `Each($items)` keys rows by their place: a
  deleted row hands its field, focus or scroll to the next one. Give an
  id, `Each($items, id: 'id')`, unique among the rows;
  `$h->rt->strict = true` in a headless test makes duplicates throw.
- **Changing the state while building.** The view only reads; a
  `$this->count++` outside an action runs every frame. Change it in
  `action:`, `onSubmit`, `onChange(of:)`, `onAppear`, `task`.
- **A missing second pair of parentheses.** `VStack(spacing: 8)` alone is
  an empty stack; its children go in `VStack(spacing: 8)( … )`.
- **`if` in a list of children.** PHP has no statements there: write
  `$cond ? Text('a') : null`, or build an array before.
- **`fn()` that should see later changes.** Arrow functions copy local
  variables when made; the state, `$this`, is an object and always
  current. A closure in `view.php` calling one made after it takes it by
  reference, `use (&$part)`.
- **Colors as strings or hard-coded.** `'#ffffff'` is a type error, and
  `Color::rgb(0xffffff)` backgrounds break in dark mode: use the theme's
  colors, `Color::background`, `Color::secondarySystemBackground`,
  `Color::primary`, `Color::secondary`.
- **Blocking calls in an action.** `file_get_contents` of a URL, `sleep`,
  a slow query: the window freezes. Use `App::go()` and the plugins, and
  `$window->update()` to publish.
- **Durations in milliseconds.** Animations, timers and plugin intervals
  take seconds.
- **Shortcuts.** `->keyboardShortcut('s')` is ⌘S on macOS and Ctrl+S on
  Linux; `->keyboardShortcut(KeyEquivalent::return, modifiers: [])` is
  Return alone. Menu items take their accelerator as a string such as
  `'CmdOrCtrl+S'` (`docs/menus.md`).
- **Extra arguments vanish, labels matter.** PHP drops arguments a function
  does not declare and takes named arguments by their exact names:
  check the signature in `src/UI/Declarative/functions.php` rather than
  guessing SwiftUI's from memory.
- **An app that sets its own appearance.** `->preferredColorScheme()` or
  `Theme::setSource()` set by the app win over `myphp screenshot -theme`:
  read the setting from your own state so the screenshot run can start in
  either.
- **Memory that grows.** A dev run logs what the process holds every 10
  s (`[memory]`, debug) and warns near `memory_limit`, when it grows a
  whole minute, and when many cycles are collected: act on the warning
  then. The usual cause is a closure kept by what it holds, such as a
  `Canvas` or `Representable` closure that keeps a node it was given.
- **Asserting sizes in headless tests.** `Headless` measures text as fixed
  7×16 cells, so font sizes and exact text widths cannot be asserted
  there; check them in a screenshot.
- **A named class or function in `view.php`.** It loads once: saved,
  the log says it waits for a restart (R) and the window keeps the old
  view. Keep `view.php` closures only, the state's class in `src/`.
- **A part of the view as a method.** A method of the state's class is
  not hot reloaded; a part of the view is a closure in `view.php`
  (`$sidebar = fn() => ListView(…)(…);`), and `$this` in it is still the
  state.
- **The engine's builder outside a Representable.** `$c->column()`,
  `$c->button()`, `$c->clicked()` are the engine's own, `@internal`: an
  app reaches them only inside `Representable(fn(Context $c) => …)`, for
  a control no view gives.
- **Custom SVG icons without `->resizable()` or with giant dimensions.**
  `Image($svg)` without `->resizable()` draws as a monochrome template icon
  tinted with the text color at font size (`1.2 * fontSize`). To draw a
  multi-color SVG illustration or 3D icon in its own colors and gradients,
  call `Image(SVG::parse($xml))->resizable()->scaledToFit()->frame(width: 44, height: 44)`.
  For full-bleed hero backgrounds that stretch across wide desktop/iPad
  screens, use a GPU `LinearGradient` for the sky/fill and place the
  fixed-size SVG illustration at `Alignment::topTrailing`.
- **Packaging exclusions (`myphp.json`).** Mobile builds (`myphp run`/`build`
  for iOS and Android) automatically exclude `tests/`, `test/`, and
  `*.local.php`. Keep local environment overrides that should be bundled on
  a phone in `config.env.php` (gitignored) rather than `config.local.php`,
  and use `"exclude": ["docs", ...]` in `myphp.json` for extra dev folders.
  If `"name"` in `myphp.json` is non-ASCII, or if you want a localized home
  screen title, set `"name"` to an ASCII slug (`"DrBearTeacher"`) and
  `"displayName"` to the localized title (`"熊博士教师"`).

## Not in MyPHP (do not call these)

- **Controls.** Badges cannot be recolored. For tree data use `OutlineGroup($data, children: 'children')` or nested `DisclosureGroup`s.
- **Themes and focus.** No per-window theme (`App::setTheme()` is
  global). Focus is `->focused($this->editing)` and `->defaultFocus()`;
  there is no `@FocusState` enum binding.
- **Lists.** `ListView` has no empty-state child: with no rows, show a
  `ListView()(Text('No notes'))` in its place, with the same modifiers
  (`searchable` above all, or its field goes with the rows). Scrolling to
  a position sets only y.
- **Context and clipboard.** The context does not touch the clipboard:
  use `MyPHP\Clipboard`.
- **Windows.** No `cornerRadius`, `setIcon`, `skipTaskbar`,
  `autoHideMenuBar`, `titleBarHeight`, `setActiveAppearance`,
  `setWindowButtonsTheme`, or per-window menu (`Window::setMenu`); the
  menu bar is the app's (`App::setMenu`).
- **Networking and images (SwiftUI first).** Always use `URLSession::shared()`
  + `URLRequest` (`data()`, `json()`, `decode()`, `upload()`, `download()`)
  instead of low-level `MyPHP\Http` methods, and upload files via
  `URLSession::shared()->upload(for: $req, from: ['file' => $path])` (native
  `multipart/form-data`, never base64-encoded JSON). For remote images use
  `AsyncImage($url)` rather than manual `Image::fromBytes()` downloads. For
  icons use `Image(systemName: 'gearshape')` or an SVG `Image($svg)->resizable()->scaledToFit()`.
- **Painting.** No `Painter::accelerated`, font thickening or
  antialiasing switches.
- **Testing.** No public `Tester` with a clock, dark mode, scale or
  snapshots: use `tests/Headless.php` of the checkout, and
  `myphp screenshot` for the real window.
- **Platforms and packaging.** macOS, Linux, Windows, Android and iOS (no
  browser/wasm build); `Device::type()` (`DeviceType::Desktop`, `Mobile`,
  `Pad`, `Tv`) and `Device::isMobile()`, `isPad()`, `isDesktop()` tell the
  form factor. No Linux auto-update; only the framework's own plugins'
  native libraries are bundled.
- **Plugins.** fetch and websocket take no client, cookie jar or
  timeout; sqlite queries cannot be canceled; the terminal plugin has no
  search, history or mirroring; game controllers work on macOS only.
