# MyPHP pitfalls

What agents get wrong most often, and what MyPHP does not have even though
mygo, its upstream, or other UI toolkits do. When the docs and this list
disagree, the docs of the checkout win: it may have grown since.

## Mistakes that compile and misbehave

- **State made in the view.** `new TextState()` or `new ListState()`
  inside the view resets the field or the list every frame: typing does
  nothing, selection never sticks. Make it once, as a property.
- **A missing `$c->end()`.** Everything after it nests in the open
  container; the layout looks shifted or squeezed rather than failing.
- **Unkeyed rows.** Without `$c->key($id)`, a deleted row hands its check
  box, focus or scroll to the next one. Keys are unique among siblings;
  `$h->rt->strict = true` in a headless test makes duplicates throw.
- **Index keys on lists that reorder.** `$c->key($i)` follows the slot,
  not the item. Use a stable ID.
- **Binding to a copy.** `foreach ($items as $item) $c->checkbox(..., $item['done'])`
  edits the loop's copy. Bind `$this->items[$i]['done']`, or commit the
  copy on `$c->changed()`.
- **Mutating the looped collection inline.** Removing an item from the
  array inside the `foreach` that builds it; use `->onClick(fn() => ...)`.
- **`fn()` that should see later changes.** Arrow functions copy scalars
  when made; use `function () use (&$v)`.
- **Colors as strings.** `'#ffffff'` or `'white'` is a type error;
  colors are `0xffffff`, alpha a separate argument.
- **Hard-coded light colors.** `0xffffff` backgrounds and `0x000000`
  text break in dark mode; use `$c->theme`.
- **Blocking calls in the view or a click handler.** `file_get_contents`
  of a URL, `sleep`, a slow query: the window freezes. Use `App::go()`
  and the plugins, and `$window->update()` to publish.
- **Durations in milliseconds.** Animations, `$c->after()`, timers and
  plugin intervals take seconds.
- **Shortcut argument order.** `$c->shortcut($key, $mods)` takes the key
  first; menu items take their accelerator as a string such as
  `'CmdOrCtrl+S'` (`docs/menus.md`).
- **Extra arguments vanish.** PHP drops arguments a method does not
  declare: `paddingY(0, 8)` is `paddingY(0)`. `paddingX()`, `paddingY()`,
  `marginX()` and `marginY()` take one value; there is no `marginTop()`,
  so one side is `margin($top, $right, $bottom, $left)`.
- **`Key::COMMAND` is Control on Linux.** Shortcuts written with
  `Key::COMMAND` are ⌘ on macOS and Ctrl on Linux, as `CmdOrCtrl` in
  menus; `Key::CONTROL` is Control on macOS and Super on Linux.
- **Chosen rows.** A list's chosen row is filled with the accent and its
  text inherits `accentText`. A color the row's text sets itself (as
  `textMuted` for a secondary line) stays, and may not read on the
  accent: color it by whether the row is chosen.
- **Primary and plain buttons differ by 2pt.** The primary has no
  border, so it is shorter; in a row of mixed buttons and fields,
  `alignItems(Node::STRETCH)` makes them one height.
- **Choosing a row of new data.** `ListState::choose($row)` checks the
  row against the count the list was last built with; right after the
  data changes, set `$list->selected = $row` instead.
- **An app that sets its own appearance.** `Theme::setSource()` called by
  the app wins over `myphp screenshot -theme`: read the setting from your
  own state so the screenshot run can start in either.
- **Asserting sizes in headless tests.** `Headless` measures text as fixed
  7×16 cells, so font sizes and exact text widths cannot be asserted
  there; check them in a screenshot.
- **A named class or function in `view.php`.** It loads once: saved,
  the log says it waits for a restart (R) and the window keeps the old
  view. Keep `view.php` closures only, the state's class in `src/`.
- **A part of the view as a method.** A method of the state's class is
  not hot reloaded; a part of the view is a closure in `view.php`
  (`$sidebar = function (Context $c): void { … };`, handed on with
  `use`), and `$this` in it is still the state.
- **A closure in `view.php` calling one made after it.** `use ($page)`
  copies what `$page` is when made (nothing yet); take it by reference,
  `use (&$page)`.
- **Select and switch.** `$c->select($labels, $index)` binds an int
  index into an array of labels; `$c->switch($label, $on)` takes its
  label first.

## Not in MyPHP (do not call these)

- **Controls.** Buttons and toggles take a label, not child content: build
  on `buttonBase` (`docs/ui/custom-widgets.md`) for custom content.
  `searchField` has no placeholder argument. Badges cannot be recolored.
  Date and time inputs and the calendar are en-US only.
- **Themes and focus.** No per-window theme (`App::setTheme()` is
  global) and no font field on `Theme`. `Node` has no `focus()`,
  `focusVisible()`, `description()` or `error()`: focus goes through a
  `Handle` (`docs/ui/input.md`).
- **Lists and text.** `List` has no `Rows`/`ListRow` or empty-state child
  (pass an `$empty` closure) and no `ListState` handle. `TextRange` has
  no weight; composition is `TextState::$marked`. Scrolling to a position
  sets only y.
- **Context and clipboard.** The context does not touch the clipboard:
  use `MyPHP\Clipboard`.
- **Windows.** No `cornerRadius`, `setIcon`, `skipTaskbar`,
  `autoHideMenuBar`, `titleBarHeight`, `setActiveAppearance`,
  `setWindowButtonsTheme`, or per-window menu (`Window::setMenu`); the
  menu bar is the app's (`App::setMenu`).
- **Images.** `Image::fromFile`, `Image::fromBytes` and GIF decoding work
  on macOS only. For icons use `$c->icon(SVG::fromFile(...))` (`docs/ui/icon.md`) or
  `Image::symbol('gearshape', 16)`, which maps SF Symbols to the GTK icon
  theme on Linux (some have no match there); `Image::fromPixels` works
  everywhere.
- **Painting.** No `Painter::accelerated`, font thickening or
  antialiasing switches.
- **Testing.** No public `Tester` with a clock, dark mode, scale or
  snapshots: use `tests/Headless.php` of the checkout, and
  `myphp screenshot` for the real window.
- **Platforms and packaging.** macOS and Linux only (no Windows, no
  browser/wasm build). No install scripts and no Linux auto-update; only
  the framework's own plugins' native libraries are bundled.
- **Plugins.** fetch and websocket take no client, cookie jar or
  timeout; sqlite queries cannot be canceled; the terminal plugin has no
  search, history or mirroring; game controllers work on macOS only.
