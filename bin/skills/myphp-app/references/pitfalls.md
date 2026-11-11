# MyPHP Pitfalls & Troubleshooting

Read this when a view misbehaves, throws, or looks wrong in a screenshot.

## 1. Common Coding Mistakes

- **Binding to a closure copy**: `Each($this->items)(fn(array $item) => Toggle($item['title'], isOn: $item['done']))` mutates the temporary copy `$item`. Use the index `$i` (second argument of `Each`/`ListView`): `isOn: $this->items[$i]['done']`.
- **Missing second parentheses on containers**: `VStack(spacing: 8)` is an empty container; children go in the second call: `VStack(spacing: 8)(Text('A'), Text('B'))`.
- **Local state inside `view.php`**: `$open = false;` inside the view closure resets every frame. Put all state on the state class.
- **Named classes or functions in `view.php` / `pages/*.php`**: Hot reload re-includes view files; a named `class` or `function` will fatal with "Cannot redeclare". Keep view files as anonymous closures only.
- **Arrow functions capturing mutated arrays by value**: In PHP, `fn() => $arr` copies `$arr` when created. If a closure run later (`App::wait`, `use (&$part)`) must see updates to a local variable, use `function () use (&$v)` (`$this` is an object reference and is always current).
- **SVG rendered as a black/white square**: `Image($svg)` without `->resizable()` treats the SVG as a monochrome template icon at font size (`1.2 * fontSize`). For multi-color SVGs or 3D illustrations, always write:
  `Image(SVG::parse($xml))->resizable()->scaledToFit()->frame(width: 44, height: 44)`.
- **Full-width SVG stretching on wide screens**: `ImageAtlas` pages are `2048×2048` px. For wide hero headers (macOS / iPad landscape), use a GPU `LinearGradient` for the full-width background and place the fixed-size `SVG` illustration at `Alignment::topTrailing`.
- **Hardcoded `#hex` strings or non-adaptive colors**: Colors take `Color::primary`, `Color::secondary`, `Color::systemGroupedBackground`, `Color::secondarySystemGroupedBackground`, or `Color::rgb(0x00CAA4)` — never CSS strings.
- **Laptop screen height limit in `myphp screenshot`**: Passing `-size 402x874` on a laptop whose Dock limits window height to ~811pt causes `myphp screenshot` to time out waiting for 874pt. Set `width`/`height` in `WindowOptions` and run `myphp screenshot tests/preview.php -out /tmp/shot.png` without `-size`.

## 2. Mobile Packaging & Runtime Gotchas

- **Files excluded from mobile bundles**: `myphp run` / `myphp build` automatically exclude `tests/`, `test/`, `*.local.php`, and patterns in `myphp.json`'s `"exclude"`. Store local API URLs/keys needed on the phone in `config.env.php` (gitignored), not `config.local.php`.
- **Non-ASCII app names on iOS**: Keep `"name"` in `myphp.json` as an ASCII identifier (`"DrBearTeacher"`) and set `"displayName"` to the localized home-screen title (`"熊博士教师"`).
- **Unsupported desktop APIs on mobile**: Menu bar (`App::setMenu`), system tray (`Tray`), Dock (`Dock`), global shortcuts (`GlobalShortcut`), window placement (`setBounds`/`minimize`), and save/prompt/login/directory dialogs throw `MyPHP\Platform\Unsupported` on iOS and Android (`docs/mobile.md`).
- **PHP keywords**: SwiftUI's `ForEach` is `Each(...)` and `List` is `ListView(...)` because `foreach` and `list` are reserved keywords in PHP.