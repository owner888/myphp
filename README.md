# MyPHP

[English](#english) · [中文](#中文)

---

## English

**Desktop apps in PHP, with native UI or a web frontend.**

MyPHP is a port of the ideas in [mygo](https://github.com/egoist/mygo) to PHP 8:
one process, one language, no C extension of its own. The operating system's
libraries are reached through `ext-ffi`.

Each window shows one of two kinds of interface:

- **A web page** in the webview the OS already has (WKWebView on macOS). The
  page calls PHP services through a typed bridge.
- **Native UI**, written in PHP alone and drawn by MyPHP on the GPU: no HTML,
  no JavaScript, no webview. The view is a function of the app's state and
  runs every frame (immediate mode), as in mygo's `ui` package.

A window with native UI, whose view is a function of the app's state:

```php
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

$n = 0;
App::whenReady(function () use (&$n) {
    new Window(new WindowOptions(title: 'Counter', width: 320, height: 200,
        content: Content::view(function (Context $c) use (&$n) {
            $c->column(24, 12, 1);
              $c->text("Count: $n", 20);
              if ($c->clicked($c->button('Increment'))) { $n++; }
            $c->end();
        })));
});
App::run();
```

### Status

Early. What works today, on macOS / Apple Silicon:

| piece | state |
|---|---|
| Objective-C bridge (`objc_msgSend`, classes with PHP methods) | works |
| WKWebView window, JS → PHP → JS | works (`examples/webview`) |
| Native UI: Metal renderer, CoreText text, flex layout, scrolling on either axis, clicks, hover | works (`examples/inbox`, `form`) |
| mygo's widgets and context, v1.08: toasts with actions, ids and `closeToast`; `openURLThen`; `onClick`, `onChange`, `onSubmit`, `onShortcut`; `Handle` and `focusBind`; toggle groups, step sliders with ticks, outlines and outline tables; a list's `followAnimation`, a table's `roundRows` and `headerMenu`; path gradients; `Preferences` (accent, Reduce Motion, Increase Contrast); registered fonts and `Font::families`; `Oklch`; `Image::update`; `TextLayout` for text controls of one's own; `TextBuffer`; every base widget without a look (`checkboxBase` … `tooltipBase`, `selectBase`, `comboboxBase`, the text inputs) | works |
| mygo's app, window, page and menus, v1.09: `Page` (`Window::page()`: loadFile, reload, back and forward, zoom, user agent, `eval`, window.open() handler, title, navigation, load and crash events, JavaScript panels as sheets); `Event` (`emit`, `emitPage`, `broadcast`); web views in native UI (`Window::newWebView`, `Context::webView`); the page's preferences, language and title bar room; `App` lifecycle events, activation policy, about panel, `path`, `exit`; `Dock`, `Shell`, `Screen`, `Theme`; `Power::keepAwake`; window state with its store; menu radio items, roles of the View, Format, Speech, Services, Window and Help menus; `Tray::popUpMenu` | works |
| mygo's element API: CSS flexbox (gaps, `alignContent`, `wrapReverse`, automatic margins, `justify`'s spaces, least and largest sizes, percentages, aspect ratios), absolute and relative insets, `attach`/`attachTo` anchors, grid tracks; per-corner radii, gradients at any angle (sRGB or Oklab), stripes, dashed borders, box shadows inside and out with spread, dividers, `debug`, `invisible`, `passThrough`, inherited `disabled`, `focusRing(false)`, scroll bars' insets, every cursor; `pointerPosition`, `dragVelocity`, `dragEnded`, `pressedOutside`, `overlayShortcut`, `dismissed`, a list's `rowWidth` with `scrollWith`, `TextLines`, `textRanges`, `setTextSelection` | works |
| Lists over a million rows: scroll bars, `scrollTo` glides, keyed rows, selection with keyboard navigation | works (`examples/inbox`) |
| Images (PNG, JPEG, HEIC, ICNS…) in native UI, rounded and faded, fitted (contain, cover, fill, scale-down, natural size), in grayscale | works (`examples/inbox`, `widgets`) |
| Bold, italic and monospace text; rich text spans with color, underline, highlight, wrapping | works (`examples/inbox`, `logview`) |
| Text styles inherited from the nodes around (font family, size, weight, italics, line height, alignment, letter spacing, OpenType features, language, underlines, strikethrough, background), mygo's text system (CoreText typesetter, carets, selection geometry, bidi, ellipsis), paragraphs with links and styled text inside them | works |
| Selectable text (mygo's Selectable): drag, double and triple clicks, Shift with the arrows across the paragraphs of a container, Copy and Select All, `selectedTexts()`, `copyData()` with data in several formats | works |
| Soft shadows, rings; materials (`UI\Material`); the glass plugin (mygo's plugins/glass, `Plugins\Glass`): Liquid Glass (blur, lens bend at the edge, lit rim, tint, interactive), progressive backdrop blur along a mask, scroll edges | works (`examples/glass`) |
| Text fields and text areas: input methods, selection, wrapping, undo, Edit menu, Tab focus; checkbox, radio, slider, select, link, progress, tooltips | works (`examples/form`) |
| Switch, stepper, segmented control, tabs, collapsible section, split panes, table (sorted by one column or several, columns resized, fitted to their cells, moved, scrolled sideways), sidebar with sections, tree, accordion, rating, range slider, combobox, autocomplete, token field, date input, calendar, time input, color picker and well, find bar, form with fields and fieldsets, checkbox group, breadcrumbs, alert dialog, grid view (virtualized), multiple selection (Cmd/Shift-click, Shift+arrows, Cmd+A), rows and grid items dragged to reorder, grid layout (fixed/fr/fit tracks), SVG icons, avatars, hidden title bar (titleBar + dragWindow), toolbar with toggles, modal dialogs, popovers, menu buttons, toasts, search field, number input, meter, spinner, badge, divider, spacer; radio groups, editable text in place (a table's names), lists whose section headers stay at the top and whose rows typing their labels chooses, trimmed paths, tinted bitmaps, glyphs shaped and drawn one by one | works (`examples/widgets`, `markdown-notes`, `http`, `gallery`) |
| Theme: light/dark after the system, its accent color, live switching | works |
| `myphp init / dev / build`: scaffold, run with restart, .app bundle (exec or static micro launcher) | works |
| Packaging as mygo's (v1.12): `-platform darwin/arm64,darwin/amd64,darwin/universal`, the resources directory and its platform directories (universal binaries combined), the icon from a PNG or an asset catalog, code signing inside out, the .dmg with its window laid out, notarization, `-upload` to GitHub releases or S3; `myphp doctor`, `install-skills`, `version`, `help` | works (signing, the .dmg and notarizing need the developer's Mac) |
| Development as mygo's (v1.13): `myphp dev` runs "<name> Dev", a bundle with the app's icon and resources, built again on changes, `r`/`c`/`q`/`h` on the terminal; SIGINT and SIGTERM quit the app as Quit; `myphp.config.ts`; the pages' packages `myphp-runtime` (`packages/runtime`, which the generated client and the plugins import) and `myphp-cli` (`packages/cli`); the web template with Vite and TypeScript | works (the development app needs the developer's Mac) |
| Widgets and keys as mygo's (v1.14): password fields; a dialog keeps the window's shortcuts; lists whose place, choice and rows' state follow their items' keys, and scroll by the step into rows never measured; the keys scroll what the pointer is over, Page Up/Down in a list scroll as AppKit's; stepper Left/Right, slider Page Up/Down; tooltips for the keyboard focus, hidden by a press or Escape; Control-K in text fields; JPEG photos upright; a sidebar's typeahead; shortcuts by the key's place on other layouts; `MYPHP_FRAME_STATS` | works (decoding photos, the frame statistics and other layouts' keys need the developer's Mac) |
| Lists as mygo's tests have them (v1.15): rows of any height without gaps, a step of the wheel or a key moving them by the step, `scrollTo` at the top, middle or bottom as measured, the end followed as rows come or grow (gliding with a FollowAnimation), the focused row kept out of view, Tab showing the next row, the scroll bar's thumb as far through the rows as along its track, rows under a pinned header, a list as high as its rows in a scrolling container | works |
| Text fields and toasts as mygo's tests have them (v1.16): undo step by step (a caret move ends a step), the app's changes undone with the typing, the view kept where the wheel put it as the app adds text, fields showing their start without the focus and aligned with `textAlign()`, text input clients unmarked as the focus leaves them, toasts' time stopping under the pointer and in the background, Escape closing the toast the focus is in | works |
| Primitives, styles and the window as mygo's tests have them (v1.17): the focus into a modal overlay of the app's own, disabled sliders, `onTextKey` for the keys that type no text, the hover held by a press, grids aligning their cells, text areas' placeholders wrapping, form labels on a text area's first line | works |
| Right to left, indicators, dates and comboboxes as mygo's tests have them (v1.18): texts aligned across their column, range sliders, splits, inputs, popovers and menus mirrored, word arrows the way they point in a right-to-left paragraph, steppers and range sliders taking quick clicks, color swatches named in the app's language, combobox popups read and scrolled as AppKit's | works |
| Scopes, transforms, SVGs and web views as mygo's tests have them (v1.19): selects with mygo's popup in the window, popovers in the Tab order after their anchors, clips that cut off the pointer through transforms, carets and input clients through transforms, nodes taking text of their own (`textCaret`), icons in the font size and color around them | works |
| Choosing, tables, widgets and inline text as mygo's tests have them (v1.20): lists reporting their choice, typing rows' letters as AppKit does, Shift extending from its item, presses showing in their frame, `clickModifiers()`, tables' section headers across and chosen rows rounded as one block, calendars and color pickers at their own width | works |
| Bases, forms, feedback, toggles and themes as mygo's tests have them (v1.21): Enter at a check box left to the window's shortcuts, Escape keeping the focus, an accordion's headers each a stop that the arrows move between, a toolbar's overflow menu with its toggles checked, fieldsets disabled after their build, accent colors as mygo's, text in the app's language, tooltips in the headless paint | works |
| Outlines, repaints, scrolling, selections and following as mygo's tests have them (v1.22): frames asked for by the frame's clock, spinners painted again for their next spoke only, transformed and absolute children of containers out of view painted, inputs' text backgrounds, selection and text colors and accent carets, content transitions of rich text, scroll offsets kept within their content and `scrollTo` with an animation, lists following their end through resizes and rows going | works |
| Pastes, text lines, inline objects and buffers as mygo's tests have them (v1.23): the Edit menu's commands through the runtime (`onPaste` by the menu and Cmd+V alike, Undo and Redo of fields), inline objects as wide as they are when the text is shaped, text lines placed before their layout, malformed UTF-8 counted right in text buffers, lists reordering without a choice | works |
| Text wrapping at the width layout gives it, as mygo's (v1.24): in columns, beside what takes a row's room down to its widest word, Chinese between its characters, up to `maxLines()`, anew at each width in the frame of a resize | works |
| Wide colors, as mygo's (v1.25): `Oklch::rgb` outside sRGB keeps its Display P3 color, drawn on a P3 screen in float16 drawables for fills, shadows, text and decorations, its nearest sRGB color elsewhere; `WideColor::srgb` for the sRGB twin | works (`examples/gallery`, Wide colors) |
| Go's random numbers (v1.27): `Random\Rand::pcg($a, $b)` draws what Go's `rand.New(rand.NewPCG(a, b))` draws (IntN, Float64, Shuffle, Perm, NormFloat64…), so seeded examples match mygo's; mygo's `native`, `notes` and `drag-drop` examples; text drawn with font smoothing (bolder the lighter it is, as AppKit's); borders taking room in layout; a button's label in its own style; dates in the system's time zone whatever php.ini names | works (`examples/farm`, `notes`, `native`, `drag-drop`) |
| The examples beside mygo's (v1.26): color emoji, tinted images, text areas wrapping a paragraph at a time (a document of 100 000 lines opens at once), padding of text, borders without a background, sliders, disabled controls dimmed, local time | works (`examples/farm`, `examples/text-buffer`, `examples/text-input`) |
| App / Window API: mygo's window methods (showing, focus, minimize, maximize, full screen, bounds and sizes, limits, controls, on top, opacity, shadow, represented file, edited, Dock progress, attention, all Spaces, child windows and sheets) and events | works, with vibrancy, transparency and background colors, panels, flyouts (anchored to a rectangle or a tray icon, flipping and sliding into the screen, closing on a press outside or Escape) or in the system's popovers |
| Router (mygo's): a history of pages with paths and queries, `{name}` and `{name...}` patterns, layouts, pages keeping their state and the focus, sliding and fading in, Back and Forward buttons, Cmd+[ and Cmd+], a mouse's back and forward buttons, links to paths | works (`examples/router`) |
| Draggable nodes (mygo's Draggable): following the pointer in the parent's axes, thrown to snap points at the pointer's speed, bounds rubber-banded or rigid, caught moving, lifting with a pose; values dragged within a window to nodes taking their type, with a copy under the pointer, scrolling at the edges, Escape giving up | works (`examples/drag`) |
| Native drag and drop (mygo's data_drag): data dragged out to other apps in several formats, made lazily; text, URLs, Markdown and other formats dragged in, offered unread and read once dropped; copy and move negotiated; files from the Finder to nodes, then the window's `fileDrop`; a value dropped as it is inside the app; `Window::startDataDrag` | works (`examples/transfer`) |
| Markdown parser (mygo's ui/markdown/parser): CommonMark 0.31.2 and GitHub's extensions (all their examples), footnotes, math; text appended or edited parsed again only where it changed; a streaming reply's half-written end healed | works |
| Markdown view and stream (mygo's ui/markdown): headings, paragraphs with links and footnote references, lists and task lists, quotes, highlighted code blocks with a copy button, tables, images, math and HTML as text or by hooks; selectable, copied as text, HTML and Markdown, or as Markdown with Shift+Cmd+C; a reply being written revealed at an even pace, fading in | works |
| Rich text (mygo's ui/richtext): `Text` of attributed runs in styled paragraphs (headings, lists, task lists, quotes, code blocks, alignment, links, tags, attachments), persistent; `Document` with selection, typing attributes, undo and redo, and the Format commands; Markdown, HTML, RTF and JSON in and out; the editor (`RichText::editor`): word processors' shortcuts, Markdown shortcuts as you type, lists and tasks, undo of formats with the text, input methods, rich copy and paste | works (`examples/richtext`) |
| Terminal (mygo's plugins/terminal): the user's shell, or any program, in a pseudo-terminal, emulated by Ghostty's libghostty-vt (mygo's build, loaded through FFI, downloaded into the cache once); colors, Unicode, the scrollback, full-screen programs, the Kitty keyboard protocol, the mouse, bracketed paste, hyperlinks, titles, OSC 52, pastes a program reads itself; selecting as Ghostty does, keybinds, Ghostty's themes, links opened with Command+click | works (`examples/terminal`) |
| Updates (mygo's Updater): `myphp keygen`, then `myphp build` signs an archive of the app (Ed25519), makes delta updates from the published versions (bsdiff) and writes the manifest, for GitHub releases or a server; `Updater::check()` and `Update::install()` in the app, the bundle replaced, `App::relaunch()`; `App::go()` runs waiting work in a fiber | works |
| updater (mygo's plugins/updater): the update window of Sparkle: background checks once a day, the release notes of a new version with Install, Remind Me Later and Skip This Version, the download's progress, Relaunch; "Check for Updates…" (`Updater::menuItem()`), the user's choices in updater.json, 15 languages; the window as a web page or in native UI (`Native::plugin()`) | works (`examples/updater`) |
| game (mygo's plugins/game): `Game::view()` runs a game in fixed steps (60 a second, or its `rate()`) with its player's keys by position, pointer and wheel, and draws it every frame through a `Painter`; `Camera` (zoom, rotation, whole-pixel panning, `toWorld`, `visible`), `Game::integerFit()` for pixel art; `Gamepad\Gamepad::pads()` reads controllers through GameController.framework in the standard layout | works (`examples/game`) |
| `UI\Canvas` (mygo's): a bitmap a painter draws on the GPU, kept until drawn anew, shown as any image (pixelated for pixel art); `UI\Chart` (mygo's ui/chart): `Linear`, `Band` and `Time` scales with d3's ticks, `Chart::plot` with axes, marks that are nodes (`rect`, `point`, `ruleX`, `ruleY`, `anchor`), lines, areas and pie sectors as paths, stacks, a selection for the pointer and the keys | works (`examples/charts`, `examples/game`) |
| `UI\Direction` (mygo's): right to left for windows in such a language or a subtree of its own (`Node::direction`), logical edges (`paddingStart`, `marginEnd`, `borderStartWidth`…), rows, columns, grids and sideways scrolling mirrored, text from the right, the arrow keys of sliders, ratings, trees, calendars and the like along the line; text fields not yet | works (`MYPHP_DIRECTION=rtl`) |
| `UI\Inspector` (mygo's): developer tools for native UI, docked beside the content: the tree of nodes as markup, the styles, box model and properties of the node chosen or picked in the window, highlighted over it, frame times against the display's, issues such as duplicate keys, a right-to-left toggle; F12, Alt+Cmd+I, the menu role `toggleDevTools`; `PageOptions::$devTools` (`DevTools`), which opens a page's web inspector too; see docs/inspector.md | works |
| `Audio\Audio` (mygo's package audio): sounds decoded into memory that play over themselves, streams decoded a second ahead, `Decoder`s an app writes (a synthesizer); players with volume, pan, speed, loops and loop ranges without a gap, seeking, 5 ms ramps; mixed in PHP into an Audio Queue; WAV and Ogg Vorbis decoded in PHP, MP3, AAC, ALAC, FLAC, AIFF and CAF by the system; see docs/audio.md | works (`examples/piano`) |
| Plugins (mygo's Use), in PHP: `App::use(Plugin)` binds a plugin's service as `plugin:<name>`, its page side a TypeScript client of its own; a `CallContext` parameter tells the calling page and ends as it goes; `App::onQuit` | works |
| File watches (mygo's WatchFiles): `App::watchFiles` on a file or a directory, recursive or not, `Ignore` leaving out what is never opened; batches of create, write, remove and rename once changes stop, a file saved as editors do a write, the end when the root goes away; kqueue through FFI | works |
| watch (mygo's plugins/watch): pages watch the directories and files the app names (`src/Plugins/Watch/client/index.ts`, `@myphp-plugins/watch`), recursive only where the app allows, `Resolve` and `Allow`, quotas per page and in all, failures as codes that never say a path | works (`examples/watch`) |
| WebSocket (mygo's plugins/websocket): pages open WebSocket connections from PHP with the browser's API (`src/Plugins/WebSocket/client/index.ts`, `@myphp-plugins/websocket`): any header in the handshake (Authorization, Cookie, Origin), subprotocols, text and binary messages in order, `bufferedAmount`, closing either way, connections ending with their page; libcurl, the system's, through FFI | works (`examples/websocket`) |
| fetch (mygo's plugins/fetch): pages make HTTP requests from PHP with a standard `fetch` (`src/Plugins/Fetch/client/index.ts`): no CORS, any header (Origin, Cookie, Host…), the body streamed as it arrives, aborting stops the request; libcurl, the system's, through FFI | works |
| SQLite (mygo's plugins/sqlite): mygo's build of SQLite 3.53.4 and its shim through FFI; `Sqlite::open`, one statement at a time checked whole before it runs, parameters, `RETURNING`, transactions rolled back on any error, read-only connections, busy timeouts, FTS5, R*Tree and JSON; for pages, `App::use(Sqlite::plugin())` and `src/Plugins/Sqlite/client/index.ts` (`@myphp-plugins/sqlite`), connections owned by their page | works |
| Web pages: `Ipc::bind()` a PHP service, call it from the page, events back, streaming channels, page-side window controls, preload script, generated TypeScript client | works (`examples/webview`) |
| Menu bar with roles and accelerators, context menus, open/save/message dialogs | works (`examples/desktop`) |
| App services (mygo's): deep links (`App::onOpenURL`, `registerURLScheme`, myphp.json's `urlSchemes`) and files opened with the app (`onOpenFile`, `fileAssociations`), a single instance (`requestSingleInstanceLock`, `onSecondInstance`), recent documents, opening at login, `Network` (online, metered), `Clipboard` (data in several formats, files, flush), `Secrets` (the Keychain), `Cookies`; pages: find in page, print, print to PDF, permissions (camera, microphone), downloads | works (`examples/services`) |
| Documents (mygo's): `Documents`, `Document` — a window per file, the File menu's roles (new, open, openRecent, save, saveAs, duplicate, rename, moveTo, revert), autosave in place, recovery copies after a crash, asking before closing and quitting, files changed by other apps, a `DocumentService` for pages; see docs/documents.md | works (`examples/documents`) |
| Custom schemes served by PHP (mygo's `Protocol`): `Protocol::handle`, `ProtocolRequest`/`ProtocolResponse` (streamed bodies), `Protocol::fileServer`; the frontend at myphp://localhost/ (`App::setFrontend`, myphp.json's `devUrl`, `devCommand`, `buildCommand`, `frontendDist`); only trusted pages call PHP (`PageOptions::$trustedOrigins`) | works (`examples/protocol`) |
| Scrolling a node into view (mygo's ScrollIntoView), and the focus Tab brings into view, through nested scrolling containers | works |
| Context menus and menu buttons of the native UI (mygo's ContextMenu, Menu, MenuButton): items, check marks, submenus, shortcuts, the innermost menu, the menu key and Shift+F10, text inputs' and selectable text's editing items, `editItems()` | works |
| Tray icon, global shortcuts, notifications (from a built .app), window events, file drops, remembered window frames | works (`examples/desktop`) |
| Child processes without blocking the UI (`Process`): curl, scripts, tools | works (`examples/http`) |
| SVG: icons as masks in any color (turned by `rotate`), pictures in their own colors — paths, shapes, transforms, gradients, strokes and dashes, opacity, clip paths, masks, style sheets — drawn by a renderer in PHP, which also rasterizes path masks (no CoreGraphics) | works (`examples/widgets`, `files`) |
| Languages and regional formats (mygo's i18n, v1.10): the app's language from the user's (or `App::setLocale`, kept in its defaults); dates, numbers, months, weekdays and day periods in a locale (`I18n`, `DateFormatter`, `NumberFormatter`, ICU through `ext-intl`); CLDR's parents and plural categories; ICU messages (`Message`: plural, selectordinal, select, offsets, number and date styles); the app's catalogs (`Catalog`, `myphp.json` `i18n`), `myphp generate` writing their PHP class and TypeScript module, `myphp i18n check`, `myphp dev --lang`, the pseudo locales en-XA and ar-XB, the bundle's localizations; MyPHP's own strings and menus (en, zh-Hans, zh-Hant) changed by `myphp.<key>` | works (`examples/widgets`) |
| A file manager: sidebar of places, breadcrumbs, the folder as icons or a sortable table sharing one selection, find bar, image preview, Trash through Finder | works (`examples/files`) |
| Code editor (mygo's ui/editor): rope text, undo, several carets and selections, soft wraps, folding, find and replace (PCRE), go to line, completions, hovers, diagnostics, inlay hints and suggestions, the changes against a base in the gutter, Zed's commands and key bindings, input methods at every caret; 28 languages highlighted incrementally; only the lines in view laid out. Diff viewer: split or unified, incremental, words or characters changed, collapsed unchanged lines, Revert and Keep, line selection, annotations, git patches | works (`examples/code-editor`) |
| Accessibility: VoiceOver reads and works every widget, the code editor and the diff viewer (mygo's tree of roles, names, states and actions over `NSAccessibilityElement`s) | new, to try with VoiceOver |
| Transforms (translate, scale, rotate, skew, and in 3D rotateX/rotateY/perspective, backface hidden) and opacity of a node and its children, drawn by the GPU through each quad's map, hit-tested through its inverse (mygo's transform.go) | new |
| Poses (a node moved, scaled, turned and faded after its transform) and content transitions (text rolling as a counter's digits or fading across, icons replaced, pictures crossfading) (mygo's pose.go and content.go) | new |
| Transitions: nodes moving and resizing from where the last frame showed them, entering and leaving (moved, faded, collapsed, scaled, turned), colors fading, matched nodes coming from where their match was (mygo's transition.go) | new |
| Animation: curves, springs, delays, jumps, colors, keyframes and loops by node and key (mygo's motion), timelines of tweens placed along one playhead (paused, sought, reversed, repeated), Reduce Motion; the switch, sliders, collapsibles, accordions and sidebar sections move as mygo's | new |
| Linux and Windows | next |
| Linux (GTK/WebKitGTK), Windows (Win32/WebView2) | planned |

Measured in `examples/inbox 1000` on an M-series Mac: 9 000 nodes rebuilt,
laid out and painted per frame in about 6 ms with real text, 60 fps while
scrolling; frames are only built when something changes.

### The command line

```sh
bin/myphp init my-app              # a native-UI project (-template web for a page calling PHP)
cd my-app && ../myphp/bin/myphp dev   # runs it, restarts on changes, regenerates the TS client
../myphp/bin/myphp build           # build/darwin-arm64/my-app.app and its .dmg, a launcher that execs this machine's php; the plugins' native libraries (the terminal's libghostty-vt) in its Resources
../myphp/bin/myphp build -launcher micro    # one static binary: fetches static-php-cli and builds phpmicro the first time
```

### Requirements

- PHP ≥ 8.3 with `ext-ffi` and `ext-intl` (Homebrew's `php` has both), OPcache JIT recommended
- macOS on Apple Silicon for now

A window with a web page, whose frontend calls PHP:

```php
/** Greeter says hello. */
final class Greeter { public function greet(string $name): string { return "Hello, $name"; } }

Ipc::bind(new Greeter());
App::whenReady(fn() => new Window(new WindowOptions(title: 'Hello', html: '<script>
    myphp.call("Greeter.greet", "Ada").then(s => document.body.textContent = s)</script>')));
App::run();   // MYPHP_GENERATE=src/myphp.ts php app.php writes the typed client instead
```

### Try it

```sh
./run.sh webview          # Notes: a page calling a bound PHP service, with events
./run.sh gen              # writes examples/webview/myphp.ts, the TypeScript client
./run.sh desktop          # menus and dialogs
./run.sh form             # text fields, input methods
./run.sh logview /var/log/system.log   # a log viewer: a table that follows the file, filters, highlights, Cmd+F finds
./run.sh titlebar [hidden|hiddenInset] [x,y]   # a hidden title bar: the view draws its own under the traffic lights (moved to x,y) and drags the window by it
./run.sh files [folder]                # a file manager: places, breadcrumbs, icons or a sortable table, Cmd+F finds, Space previews, Delete… trashes
./run.sh code-editor                   # a code editor: files of several languages, completions of their words, hovers, TODO diagnostics, changes in the gutter, and their diff
./run.sh drag                          # draggable nodes: a card, a puck thrown to stops, a sheet; words dragged into a basket
./run.sh transfer                      # native drag and drop: a note dragged out to other apps; text, links and files dropped in; cards between lists
./run.sh router                        # a router: a sidebar, Back and Forward, links, notes sliding in, a settings layout
./run.sh flyout                        # flyouts: a menu under its button, the same in a system popover, a hover card, a tray icon's flyout; a panel above every app
php tests/run.php                      # the headless tests of the UI (no window): layout, keys, focus, the widgets and files examples
php tests/bench.php                    # the cost of a frame, headless: build, layout, record, paint, for the examples' views
./run.sh markdown-notes   # Markdown notes: search, toolbar, resizable panes, live preview, autosave
./run.sh notes            # mygo's notes: rich text notes, a toolbar of their formats, the Format menu, links, Copy Markdown
./run.sh native           # mygo's native: a page calling PHP for dialogs, the clipboard, notifications, a context menu, the tray, a global shortcut, dark mode
./run.sh drag-drop        # mygo's drag-drop: notes, a URL and a file dragged between two windows and other apps
./run.sh richtext         # a rich text editor with a toolbar of its formats
./run.sh terminal         # a terminal: your shell, emulated by Ghostty's libghostty-vt
./run.sh sqlite           # SQLite from a web page through the PHP plugin; Reload shows a connection ends with its page
./run.sh watch [folder]   # a web page watching a folder through the PHP plugin; the buttons change files
./run.sh websocket [url]  # a WebSocket from a web page through the PHP plugin, to an echo server
./run.sh updater --demo   # the update window, with a made-up update to install (--native: in native UI)
./run.sh game             # a little game: WASD, the arrows or a gamepad, the wheel zooms
./run.sh charts           # charts: bars, a live line, stacked areas, a scatter plot, a donut
./run.sh piano            # a piano of synthesized keys: the pointer, the keyboard, a sustain pedal, tunes
./run.sh services         # the app's services from a page: network, clipboard, Keychain, cookies, find, PDF…
./run.sh documents [x.md] # document notes: a window per file, the File menu, autosave, crash recovery
./run.sh protocol         # a frontend at myphp://localhost/, images a scheme draws, a streamed body, a download
./run.sh photos ~/Pictures # a photo browser: thumbnail grid, one full size, arrow keys
./run.sh http             # an HTTP client over curl: requests kept in a list, JSON colored
./run.sh glass            # Liquid Glass over a photo
./run.sh widgets          # the small controls on one page
./run.sh inbox            # native UI, 300-row list, prints fps once a second
./run.sh inbox 1000       # 9 000 nodes per frame
./run.sh hello            # mygo's examples, ported: a page greeting through a bound service
./run.sh counter-native   # a counter in native UI, its buttons and the arrow keys
./run.sh text-buffer 100000 # a text area over a TextBuffer of 100 000 lines
./run.sh text-input       # a text field of the app's own drawing, through TextInputClient
./run.sh frameless        # a frameless, transparent window with vibrancy, its page moving it
./run.sh clipboard        # the clipboard in several formats, one made lazily; data dragged out and dropped in
./run.sh timeline         # a timeline of tweens, placed after each other, at times and labels
./run.sh markdown-chat    # replies streamed token by token as Markdown, revealed word by word
./run.sh embed-webview    # web views inside native UI, and native popovers, selects and modals over them
./run.sh menubar-flyout   # a tray icon's flyout in a system popover
./run.sh menubar-native   # a menu bar app: a window under its tray icon that hides as it loses the focus
./run.sh launcher         # a launcher: a global shortcut, the installed apps ranked as you type, their icons
./run.sh vibrancy         # a window of vibrancy: a sidebar of materials sliding out
./run.sh todo             # a todo list in a Vite page, its typed PHP service and events (needs bun)
./run.sh confetti         # canvas-confetti's demos, every piece moved by poses that only paint
./run.sh effort-slider    # an effort slider stepping into Galaxy, letters blurring as they change
./run.sh farm             # a farming game: till, plant, water, harvest, day and night, music
./run.sh mayoi            # a survivor game: sing at stage fright, gather stones, find your band
./run.sh gallery          # the tour of the toolkit: every widget, lists, charts, motion, languages
```

### Layout

```
bin/myphp     the command line: init, dev, build, micro
src/          App, Window, WindowOptions, Menu, Dialog, Ipc, TypeScript
src/Native/   OS bridges: ObjC runtime, Metal, CoreText, the event view
src/UI/       the toolkit: Context (tree building), Layout (flex), Text, Runtime (input), Painter
examples/     runnable apps
docs/         design notes
AGENTS.md     entry point for coding agents and contributors
```

### License

MIT.

---

## 中文

**用 PHP 写桌面应用，原生 UI 或网页前端二选一。**

MyPHP 把 [mygo](https://github.com/egoist/mygo) 的思路搬到 PHP 8 上：
一个进程、一种语言、不写自己的 C 扩展，系统库全部通过 `ext-ffi` 调用。

每个窗口显示两种界面之一：

- **网页**：用系统自带的 webview（macOS 上是 WKWebView），页面通过类型化的桥接调用 PHP 服务。
- **原生 UI**：纯 PHP 编写，由 MyPHP 自己画到 GPU 上，没有 HTML、JavaScript 和 webview。
  视图是应用状态的函数，每帧重跑（immediate mode），与 mygo 的 `ui` 包同一思路。

一个原生 UI 窗口，视图是应用状态的函数：

```php
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

$n = 0;
App::whenReady(function () use (&$n) {
    new Window(new WindowOptions(title: 'Counter', width: 320, height: 200,
        content: Content::view(function (Context $c) use (&$n) {
            $c->column(24, 12, 1);
              $c->text("Count: $n", 20);
              if ($c->clicked($c->button('Increment'))) { $n++; }
            $c->end();
        })));
});
App::run();
```

### 现状

早期。目前在 macOS / Apple Silicon 上能跑的：

| 部分 | 状态 |
|---|---|
| Objective-C 桥（`objc_msgSend`、方法为 PHP 闭包的类） | 可用 |
| WKWebView 窗口，JS → PHP → JS 往返 | 可用（`examples/webview`） |
| 原生 UI：Metal 渲染、CoreText 文字、flex 布局、横竖滚动、点击、hover | 可用（`examples/inbox`、`form`） |
| mygo 的部件和上下文（v1.08）：带操作、ID 和 `closeToast` 的 toast；`openURLThen`；`onClick`、`onChange`、`onSubmit`、`onShortcut`；`Handle` 和 `focusBind`；切换组、带刻度的步进滑块、大纲和大纲表格；列表的 `followAnimation`、表格的 `roundRows` 和 `headerMenu`；路径渐变；`Preferences`（强调色、减少动态效果、增强对比度）；注册字体和 `Font::families`；`Oklch`；`Image::update`；自绘文字控件用的 `TextLayout`；`TextBuffer`；全部无外观的基础部件（`checkboxBase` … `tooltipBase`、`selectBase`、`comboboxBase`、文本输入） | 可用 |
| mygo 的 app、窗口、页面与菜单，v1.09：`Page`（`Window::page()`：loadFile、reload、前进后退、缩放、user agent、`eval`、window.open() 处理、标题/导航/加载/崩溃事件、JavaScript 弹窗以 sheet 显示）；`Event`（`emit`、`emitPage`、`broadcast`）；原生 UI 中的 web view（`Window::newWebView`、`Context::webView`）；页面的偏好设置、语言和标题栏空间；`App` 生命周期事件、激活策略、关于面板、`path`、`exit`；`Dock`、`Shell`、`Screen`、`Theme`；`Power::keepAwake`；可自定义存储的窗口状态；菜单单选项，View/Format/Speech/Services/Window/Help 菜单角色；`Tray::popUpMenu` | 可用 |
| mygo 的元素 API：CSS flexbox（间距、`alignContent`、`wrapReverse`、自动外边距、`justify` 的分布方式、最小最大尺寸、百分比、宽高比），绝对和相对定位的 inset，`attach`/`attachTo` 锚点，网格轨道；每角圆角、任意角度的渐变（sRGB 或 Oklab）、条纹、虚线边框、带扩展的内外阴影、分隔线、`debug`、`invisible`、`passThrough`、可继承的 `disabled`、`focusRing(false)`、滚动条内缩、全部光标；`pointerPosition`、`dragVelocity`、`dragEnded`、`pressedOutside`、`overlayShortcut`、`dismissed`、列表的 `rowWidth` 配合 `scrollWith`、`TextLines`、`textRanges`、`setTextSelection` | 可用 |
| 百万行列表：滚动条、`scrollTo` 平滑滚动、按行 key、选中与键盘导航 | 可用（`examples/inbox`） |
| 原生 UI 里的图片（PNG、JPEG、HEIC、ICNS…），可圆角、可透明、五种适配方式（contain/cover/fill/scale-down/natural）、可灰度 | 可用（`examples/inbox`、`widgets`） |
| 粗体、斜体、等宽文字；富文本 span（颜色、下划线、高亮、换行） | 可用（`examples/inbox`、`logview`） |
| 文字样式沿节点继承（字体族、字号、字重、斜体、行高、对齐、字距、OpenType 特性、语言、下划线、删除线、背景），移植 mygo 的文字系统（CoreText 排版器、光标、选区几何、双向文字、省略号），段落内可嵌链接和带样式的文字 | 可用 |
| 可选中文字（mygo 的 Selectable）：拖选、双击选词、三击选行、Shift 加方向键跨段落扩展选区、复制与全选、`selectedTexts()`、`copyData()` 以多种格式复制 | 可用 |
| 把节点滚动到可见（mygo 的 ScrollIntoView），Tab 移到的焦点也会滚动到可见，嵌套滚动容器逐层处理 | 可用 |
| 原生界面的右键菜单和菜单按钮（mygo 的 ContextMenu、Menu、MenuButton）：菜单项、勾选、子菜单、快捷键提示、最内层菜单优先、菜单键和 Shift+F10、输入框和可选中文字的编辑项、`editItems()` | 可用 |
| 软阴影、描边环；材质（`UI\Material`）；glass 插件（mygo 的 plugins/glass，`Plugins\Glass`）：Liquid Glass（模糊、边缘透镜折射、高光边、着色、按压放大）、沿遮罩渐变的背景模糊、滚动边缘 | 可用（`examples/glass`） |
| 文本框和多行文本区：输入法、选区、自动换行、撤销、Edit 菜单、Tab 焦点；复选框、单选、滑块、下拉选择、链接、进度条、提示 | 可用（`examples/form`） |
| 开关、步进器、分段控件、标签页、折叠区、可拖分栏、表格（按一列或多列排序，列可拖宽、双击适配内容、拖动换位，过宽时横向滚动）、分组侧栏、树、手风琴、星级、范围滑块、工具栏（含开关按钮）、模态对话框、弹出面板、菜单按钮、toast 提示、搜索框、数字输入、水平指示条、加载指示、徽标、分隔线、占位；单选组、原地编辑的文字（表格里的文件名）、分节标题吸顶且可输入首字母选行的列表、截取部分长度的路径、着色位图、逐个排版和绘制的字形 | 可用（`examples/widgets`、`markdown-notes`、`http`、`gallery`） |
| 主题：跟随系统明暗和强调色，实时切换 | 可用 |
| `myphp init / dev / build`：脚手架、改动重启、.app 打包（exec 或静态 micro 启动器） | 可用 |
| 与 mygo 一致的打包（v1.12）：`-platform darwin/arm64,darwin/amd64,darwin/universal`，resources 目录及按平台的子目录（通用二进制自动合并），PNG 或 asset catalog 生成图标，由内而外代码签名，排好窗口布局的 .dmg，公证，`-upload` 到 GitHub Releases 或 S3；`myphp doctor`、`install-skills`、`version`、`help` | 可用（签名、.dmg、公证要在开发者的 Mac 上跑） |
| 与 mygo 一致的开发流程（v1.13）：`myphp dev` 运行 "<name> Dev"，一个带应用图标和资源的 bundle，改动后自动重建，终端里 `r`/`c`/`q`/`h`；SIGINT、SIGTERM 让应用像 Quit 一样退出；`myphp.config.ts`；页面用的包 `myphp-runtime`（`packages/runtime`，生成的 client 和插件都从这里 import）和 `myphp-cli`（`packages/cli`）；Vite + TypeScript 的 web 模板 | 可用（开发版应用要在开发者的 Mac 上跑） |
| 与 mygo 一致的控件和按键（v1.14）：密码框；对话框打开时窗口快捷键不生效；列表按条目的 key 保持位置、选中项和行状态，滚进未测量的行时按步长精确滚动；按键滚动指针下的容器，列表里 Page Up/Down 像 AppKit 一样只滚动；步进器左右键、滑块 Page Up/Down；键盘焦点也显示提示，按下或 Esc 隐藏；文本框 Control-K；JPEG 照片按 EXIF 方向摆正；侧栏输入首字母选择；其他键盘布局下快捷键按键位匹配；`MYPHP_FRAME_STATS` | 可用（照片解码、帧统计和其他布局的按键要在开发者的 Mac 上验证） |
| 按 mygo 测试补齐的列表（v1.15）：任意行高无缝隙，滚轮或按键按步长精确滚动，`scrollTo` 按实测高度对齐到顶部、居中或底部，跟随末尾（新行或行变高时，可配 FollowAnimation 平滑），焦点所在行滚出视野仍保留，Tab 会把下一行滚进来，滚动条拖动与行的位置一致，行停在吸顶分节标题之下，滚动容器里的列表高度等于所有行 | 可用 |
| 按 mygo 测试补齐的文本框和 toast（v1.16）：逐步撤销（移动光标结束一步），撤销连同应用对输入的改写，应用追加文本时视图停在滚轮所在处，文本框失焦时显示开头、按 `textAlign()` 对齐，焦点离开时 text input client 取消标记，toast 在指针悬停和窗口转入后台时暂停计时，焦点在 toast 里时 Esc 关闭它 | 可用 |
| 按 mygo 测试补齐的基础控件、样式和窗口（v1.17）：焦点进入应用自建的模态层，禁用的滑块不响应，`onTextKey` 只接收不产生文字的按键，按住时保持悬停，网格按 align 对齐单元格，文本区占位符自动换行，表单标签对齐文本区第一行 | 可用 |
| 按 mygo 测试补齐的从右到左布局、指示器、日期和组合框（v1.18）：文字在列中按对齐方式铺满，范围滑块、分栏、输入框、弹出层和菜单镜像，从右到左段落里按词移动跟随箭头方向，步进器和范围滑块响应快速点击，颜色色板按应用语言命名，组合框弹出列表的辅助功能和滚动与 AppKit 一致 | 可用 |
| 按 mygo 测试补齐的焦点作用域、变换、SVG 和网页视图（v1.19）：下拉选择改用 mygo 的窗口内弹出列表，弹出层在 Tab 顺序中紧跟锚点，裁剪区域经变换后也限制指针，光标和文本输入客户端的几何经变换换算，自定义节点可接收输入法文字（`textCaret`），图标继承周围的字号和颜色 | 可用 |
| 按 mygo 测试补齐的多选、表格、控件和行内文字（v1.20）：列表报告选择变化，按字母选行与 AppKit 一致，Shift 扩选跟随原条目，按下当帧即显示结果，`clickModifiers()`，表格分组标题横跨各列、连续选中行圆角连成一块，日历和取色器弹层保持自身宽度 | 可用 |
| 按 mygo 测试补齐的基础组件、表单、反馈、开关和主题（v1.21）：焦点在复选框时 Enter 先交给窗口快捷键，Esc 不再丢失焦点，手风琴每个标题都是一个 Tab 停靠点、方向键在标题间移动，工具栏溢出菜单标出已按下的开关，构建后才禁用的 fieldset 也禁用其内容，强调色计算与 mygo 一致，未指定语言的文字按应用语言排版，无头测试绘制工具提示 | 可用 |
| 按 mygo 测试补齐的大纲、重绘、滚动、选区和跟随末尾（v1.22）：after() 按帧时钟计时，转圈指示器只在下一根辐条时重绘，容器在视口外时其绝对定位或变换后的子节点仍会绘制，输入框支持文字背景色、选区色和文字颜色、光标用强调色，富文本也有内容过渡动画，滚动偏移始终限制在内容范围内、`scrollTo` 可指定动画，跟随末尾的列表在改变高度和删行时不再错位 | 可用 |
| 按 mygo 测试补齐的粘贴、文本行、行内对象和文本缓冲（v1.23）：编辑菜单命令统一经由运行时处理（菜单和 Cmd+V 都会触发 `onPaste`，输入框支持撤销和重做），行内对象在排版时就占据自身宽度，文本区排版前也能估算行位置，文本缓冲正确计算非法 UTF-8，未启用选择的列表也能拖动排序 | 可用 |
| 文字按布局分配的宽度自动折行，与 mygo 一致（v1.24）：在列中折行；在行中可收缩到最长的单词再折行；中文可在字间断行；配合 `maxLines()` 限制行数；窗口改变大小时当帧重新折行 | 可用 |
| 广色域颜色，与 mygo 一致（v1.25）：超出 sRGB 的 `Oklch::rgb` 颜色保留 Display P3 色值，在 P3 屏幕上以 float16 绘制填充、阴影、文字和装饰线，其他情况绘制最接近的 sRGB 颜色；`WideColor::srgb` 取 sRGB 近似色 | 可用（`examples/gallery` 的 Wide colors） |
| Go 的随机数（v1.27）：`Random\Rand::pcg($a, $b)` 与 Go 的 `rand.New(rand.NewPCG(a, b))` 产生相同序列（IntN、Float64、Shuffle、Perm、NormFloat64…），带种子的例子与 mygo 一致；移植 mygo 的 `native`、`notes`、`drag-drop` 例子；文字按字体平滑绘制（颜色越浅越粗，与 AppKit 一致）；边框在布局中占位；按钮文字可单独设样式；无论 php.ini 怎么设，日期都用系统时区 | 可用（`examples/farm`、`notes`、`native`、`drag-drop`） |
| 对照 mygo 逐个运行例子后修正（v1.26）：彩色 emoji、带着色的图片、文本区按段落折行（10 万行文档即时打开）、文字内边距、无背景的边框、滑块、禁用控件变暗、本地时区 | 可用（`examples/farm`、`examples/text-buffer`、`examples/text-input`） |
| App / Window API：mygo 的窗口方法（显示、焦点、最小化、最大化、全屏、位置与尺寸、尺寸限制、窗口按钮、置顶、透明度、阴影、关联文件、已编辑标记、Dock 进度、请求注意、所有桌面、子窗口与 sheet）和事件 | 可用，含 vibrancy、透明窗口和背景色、面板、flyout（锚定到矩形或托盘图标，空间不够时翻转、滑回屏幕内，点外部或 Esc 关闭），也可以用系统 popover 样式 |
| 路由（mygo 的 Router）：带路径和查询的页面历史、`{name}` 与 `{name...}` 模式、嵌套布局、页面保留状态和焦点、滑入淡入、前进后退按钮、Cmd+[ 与 Cmd+]、鼠标侧键、指向路径的链接 | 可用（`examples/router`） |
| 可拖动节点（mygo 的 Draggable）：按父节点坐标跟随指针、按松手速度甩到吸附点、边界橡皮筋或硬边界、移动中可接住、配合 pose 抬起；窗口内拖拽值到接收该类型的节点，指针下有半透明副本，靠近边缘自动滚动，Esc 取消 | 可用（`examples/drag`） |
| 系统拖放（mygo 的 data_drag）：把数据以多种格式拖到其他应用，格式按需生成；从其他应用拖入文本、链接、Markdown 等格式，悬停时只看格式不读内容，放下才读取；复制/移动协商；Finder 文件先给节点，再给窗口的 `fileDrop`；应用内拖放直接拿到原值 | 可用（`examples/transfer`） |
| Markdown 解析器（mygo 的 ui/markdown/parser）：CommonMark 0.31.2 和 GitHub 扩展（规范示例全部通过）、脚注、公式；追加或编辑的文本只重新解析改动处；流式回复末尾写到一半的语法自动补全 | 可用 |
| Markdown 视图与流式视图（mygo 的 ui/markdown）：标题、带链接和脚注引用的段落、列表和任务列表、引用、带复制按钮的高亮代码块、表格、图片，公式和 HTML 按文本显示或交给钩子；可选中，复制为文本、HTML 和 Markdown，Shift+Cmd+C 复制为 Markdown；正在生成的回复匀速显示、渐入 | 可用 |
| 富文本（mygo 的 ui/richtext）：带属性的文本段、带样式的段落（标题、列表、任务列表、引用、代码块、对齐、链接、标签、附件），持久化数据结构；`Document` 管选区、输入属性、撤销重做和格式命令；Markdown、HTML、RTF、JSON 双向转换；编辑器（`RichText::editor`）：文字处理软件的快捷键、边打边转的 Markdown 快捷写法、列表和任务、格式随文本一起撤销、输入法、富文本复制粘贴 | 可用（`examples/richtext`） |
| 终端（mygo 的 plugins/terminal）：在伪终端里运行用户的 shell 或任意程序，由 Ghostty 的 libghostty-vt 模拟（mygo 编好的库，经 FFI 加载，首次运行下载到缓存）；颜色、Unicode、回滚缓冲、全屏程序、Kitty 键盘协议、鼠标、括号粘贴、超链接、标题、OSC 52、程序自己读取的粘贴；像 Ghostty 一样选择文本、快捷键绑定、Ghostty 的主题、Command+点击打开链接 | 可用（`examples/terminal`） |
| 自动更新（mygo 的 Updater）：`myphp keygen` 生成密钥，`myphp build` 给应用的归档签名（Ed25519），从已发布的版本做增量更新（bsdiff）并写出清单，发布到 GitHub Releases 或自己的服务器；应用里 `Updater::check()`、`Update::install()` 替换整个 bundle，`App::relaunch()` 重启；`App::go()` 在 Fiber 里跑会等待的工作 | 可用 |
| updater（mygo 的 plugins/updater）：Sparkle 式的更新窗口：每天后台检查一次，新版本显示发布说明并提供「安装更新」「以后提醒我」「跳过此版本」，下载进度，「立即重新启动」；菜单项「检查更新…」（`Updater::menuItem()`），用户选择存在 updater.json，15 种语言；窗口可以是网页，也可以用原生 UI 绘制（`Native::plugin()`） | 可用（`examples/updater`） |
| game（mygo 的 plugins/game）：`Game::view()` 以固定步长（每秒 60 步，或游戏自己的 `rate()`）运行游戏，按键位读取按键，另有指针和滚轮，每帧经 `Painter` 绘制；`Camera`（缩放、旋转、按整像素平移、`toWorld`、`visible`），像素画用 `Game::integerFit()`；`Gamepad\Gamepad::pads()` 经 GameController.framework 按标准布局读取手柄 | 可用（`examples/game`） |
| `UI\Canvas`（mygo 的）：画笔在 GPU 上绘制的位图，重画之前一直保留，像图片一样显示（像素画可 pixelated）；`UI\Chart`（mygo 的 ui/chart）：按 d3 取刻度的 `Linear`、`Band`、`Time` 比例尺，带坐标轴的 `Chart::plot`，本身就是节点的标记（`rect`、`point`、`ruleX`、`ruleY`、`anchor`），折线、面积和扇形路径，堆叠，指针和键盘都能移动的选择 | 可用（`examples/charts`、`examples/game`） |
| `UI\Direction`（mygo 的）：从右到左书写语言的窗口或单独一棵子树（`Node::direction`），逻辑边（`paddingStart`、`marginEnd`、`borderStartWidth`…），行、列、网格和横向滚动镜像，文字从右起，滑块、评分、树、日历等的方向键顺着行走；文本框尚未支持 | 可用（`MYPHP_DIRECTION=rtl`） |
| `UI\Inspector`（mygo 的）：原生 UI 的开发者工具，停靠在内容旁边：节点树（标记形式）、所选或在窗口中拾取的节点的样式、盒模型和属性并在内容上高亮、对照显示器刷新率的帧耗时、重复 key 等问题、从右到左开关；F12、Alt+Cmd+I、菜单角色 `toggleDevTools`；`PageOptions::$devTools`（`DevTools`）也打开网页的 Web 检查器；见 docs/inspector.md | 可用 |
| `Audio\Audio`（mygo 的 audio 包）：解码进内存、可叠加播放的音效，提前一秒解码的流，应用自己写的 `Decoder`（合成器）；播放器支持音量、声像、速度、无缝循环和循环区间、跳转、5 ms 渐变；在 PHP 里混音后送进 Audio Queue；WAV 和 Ogg Vorbis 用 PHP 解码，MP3、AAC、ALAC、FLAC、AIFF、CAF 用系统解码器；见 docs/audio.md | 可用（`examples/piano`） |
| 插件（mygo 的 Use），PHP 插件：`App::use(Plugin)` 把插件的服务绑定为 `plugin:<名字>`，网页侧是插件自带的 TypeScript 客户端；`CallContext` 参数告诉方法是哪个页面调用的，页面离开时结束；`App::onQuit` | 可用 |
| 文件监视（mygo 的 WatchFiles）：`App::watchFiles` 监视文件或目录，可递归，`Ignore` 排除的目录从不打开；改动停下后成批给出 create、write、remove、rename，编辑器式保存算一次 write，根目录没了监视即结束；kqueue 经 FFI | 可用 |
| watch（mygo 的 plugins/watch）：网页按名字监视应用开放的目录和文件（`src/Plugins/Watch/client/index.ts`，`@myphp-plugins/watch`），只在应用允许处递归，`Resolve` 与 `Allow`，每页和总数配额，失败只给代码、从不暴露路径 | 可用（`examples/watch`） |
| WebSocket（mygo 的 plugins/websocket）：网页用浏览器同款 API 从 PHP 建 WebSocket 连接（`src/Plugins/WebSocket/client/index.ts`，`@myphp-plugins/websocket`）：握手可带任意请求头（Authorization、Cookie、Origin），子协议，文本和二进制消息按序发送，`bufferedAmount`，双方都可关闭，连接随页面结束；用系统自带的 libcurl，经 FFI | 可用（`examples/websocket`） |
| fetch（mygo 的 plugins/fetch）：网页用标准的 `fetch`（`src/Plugins/Fetch/client/index.ts`）从 PHP 发 HTTP 请求：没有 CORS 限制，任意请求头（Origin、Cookie、Host…），响应体边收边流给页面，abort 即停止请求；用系统自带的 libcurl，经 FFI | 可用 |
| SQLite（mygo 的 plugins/sqlite）：mygo 编好的 SQLite 3.53.4 和它的 C shim，经 FFI 调用；`Sqlite::open`，每次一条语句、执行前整体校验，参数绑定，`RETURNING`，出错整体回滚的事务，只读连接，忙等超时，FTS5、R*Tree、JSON；网页用 `App::use(Sqlite::plugin())` 和 `src/Plugins/Sqlite/client/index.ts`（`@myphp-plugins/sqlite`），连接归调用它的页面 | 可用 |
| 网页：`Ipc::bind()` 绑定 PHP 服务，页面直接调用，事件回推，流式 channel，页面侧窗口控制，preload 脚本，生成 TypeScript client | 可用（`examples/webview`） |
| 菜单栏（角色、快捷键）、右键菜单、打开/保存/消息对话框 | 可用（`examples/desktop`） |
| App 服务（mygo 的）：深链接（`App::onOpenURL`、`registerURLScheme`、myphp.json 的 `urlSchemes`）与用本应用打开的文件（`onOpenFile`、`fileAssociations`）、单实例（`requestSingleInstanceLock`、`onSecondInstance`）、最近文档、登录时启动、`Network`（是否联网、是否按流量计费）、`Clipboard`（多格式数据、文件、flush）、`Secrets`（钥匙串）、`Cookies`；网页：页内查找、打印、打印为 PDF、权限（摄像头、麦克风）、下载 | 可用（`examples/services`） |
| 文档型应用（mygo 的）：`Documents`、`Document`——每个文件一个窗口、文件菜单的角色（new、open、openRecent、save、saveAs、duplicate、rename、moveTo、revert）、原地自动保存、崩溃后的恢复副本、关闭和退出前询问、被其他应用改动的文件、给网页用的 `DocumentService`；见 docs/documents.md | 可用（`examples/documents`） |
| PHP 服务的自定义 scheme（mygo 的 `Protocol`）：`Protocol::handle`、`ProtocolRequest`/`ProtocolResponse`（可流式输出）、`Protocol::fileServer`；前端在 myphp://localhost/（`App::setFrontend`，myphp.json 的 `devUrl`、`devCommand`、`buildCommand`、`frontendDist`）；只有受信任的页面能调用 PHP（`PageOptions::$trustedOrigins`） | 可用（`examples/protocol`） |
| 托盘图标、全局快捷键、通知（需打包的 .app）、窗口事件、文件拖放、记住窗口位置 | 可用（`examples/desktop`） |
| 不阻塞界面的子进程（`Process`）：curl、脚本、命令行工具 | 可用（`examples/http`） |
| SVG：图标按蒙版任意着色（`rotate` 可旋转），图片按自身颜色绘制——路径、基本图形、变换、渐变、描边与虚线、不透明度、裁剪、遮罩、样式表——由纯 PHP 渲染器绘制，路径蒙版也由它栅格化（不再用 CoreGraphics） | 可用（`examples/widgets`、`files`） |
| 语言与地区格式（mygo 的 i18n，v1.10）：应用语言随用户设置（或 `App::setLocale`，记在应用 defaults 里）；按 locale 写日期、数字、月份、星期和上下午（`I18n`、`DateFormatter`、`NumberFormatter`，通过 `ext-intl` 用 ICU）；CLDR 的父 locale 和复数类别；ICU 消息（`Message`：plural、selectordinal、select、offset、数字和日期样式）；应用自己的目录（`Catalog`，`myphp.json` 的 `i18n`），`myphp generate` 生成 PHP 类和 TypeScript 模块，`myphp i18n check`，`myphp dev --lang`，伪 locale en-XA 和 ar-XB，bundle 的本地化目录；MyPHP 自带文案和菜单（en、zh-Hans、zh-Hant）可用 `myphp.<key>` 改写 | 可用（`examples/widgets`） |
| 文件管理器：常用位置侧栏、路径面包屑、目录以图标或可排序表格显示且共用一个选择、查找条、图片预览、经 Finder 移到废纸篓 | 可用（`examples/files`） |
| 无障碍：VoiceOver 可朗读并操作所有控件、代码编辑器和 diff 查看器（mygo 的角色、名称、状态与动作树，基于 `NSAccessibilityElement`） | 新加，待 VoiceOver 实测 |
| 节点及其子节点的变换（平移、缩放、旋转、斜切，以及 3D 的 rotateX/rotateY/perspective、背面隐藏）和透明度，由 GPU 按每个 quad 的映射绘制，命中测试走逆映射（mygo 的 transform.go） | 新加 |
| 姿态（在变换之后平移、缩放、旋转、淡出节点）和内容过渡（文字像计数器数字那样滚动或淡入淡出、图标替换、图片交叉淡化）（mygo 的 pose.go 和 content.go） | 新加 |
| 过渡：节点从上一帧的位置和尺寸移动、缩放到新布局，进场和离场（平移、淡入淡出、折叠、缩放、旋转），颜色渐变，匹配节点从对应节点原来的位置过来（mygo 的 transition.go） | 新加 |
| 动画：按节点和键的曲线、弹簧、延迟、跳变、颜色、关键帧和循环（mygo 的 motion），沿同一播放头编排补间的时间线（可暂停、拖动定位、倒放、重复），遵循「减弱动态效果」；开关、滑块、折叠区、手风琴和侧边栏分组按 mygo 的方式动起来 | 新加 |
| Linux 和 Windows | 下一步 |
| Linux（GTK/WebKitGTK）、Windows（Win32/WebView2） | 计划中 |

在 M 系列 Mac 上用 `examples/inbox 1000` 实测：每帧重建、布局并绘制 9 000 个节点（含真实文字）约 6 ms，滚动时 60 fps；没有变化时不构建帧。

### 命令行

```sh
bin/myphp init my-app              # 原生 UI 项目（-template web 是调用 PHP 的网页项目）
cd my-app && ../myphp/bin/myphp dev   # 运行，文件改动自动重启，重新生成 TS client
../myphp/bin/myphp build           # build/darwin-arm64/my-app.app 和它的 .dmg，启动器 exec 本机的 php；插件要的原生库（终端的 libghostty-vt）放进 Resources
../myphp/bin/myphp build -launcher micro    # 单个静态二进制：第一次自动下载 static-php-cli 并构建 phpmicro
```

### 环境要求

- PHP ≥ 8.3，带 `ext-ffi` 和 `ext-intl`（Homebrew 的 `php` 都自带），建议开 OPcache JIT
- 目前仅支持 Apple Silicon 的 macOS

一个网页窗口，前端直接调用 PHP：

```php
/** Greeter says hello. */
final class Greeter { public function greet(string $name): string { return "Hello, $name"; } }

Ipc::bind(new Greeter());
App::whenReady(fn() => new Window(new WindowOptions(title: 'Hello', html: '<script>
    myphp.call("Greeter.greet", "Ada").then(s => document.body.textContent = s)</script>')));
App::run();   // MYPHP_GENERATE=src/myphp.ts php app.php 则只生成带类型的 client
```

### 运行

```sh
./run.sh webview          # Notes：页面调用绑定的 PHP 服务，带事件
./run.sh gen              # 生成 examples/webview/myphp.ts（TypeScript client）
./run.sh desktop          # 菜单和对话框
./run.sh form             # 文本框、输入法
./run.sh logview /var/log/system.log   # 日志查看器：跟随文件增长、过滤、高亮、Cmd+F 查找
./run.sh titlebar [hidden|hiddenInset] [x,y]   # 隐藏标题栏：视图在红绿灯下自己画标题栏（x,y 可挪红绿灯），拖它移动窗口
./run.sh files [目录]                   # 文件管理器：常用位置、路径面包屑、图标或可排序表格、Cmd+F 查找、空格预览、Delete… 移到废纸篓
./run.sh drag                          # 可拖动：卡片、甩到吸附点的圆块、底部弹出面板；把词拖进篮子
./run.sh transfer                      # 系统拖放：把便签拖到其他应用；拖入文本、链接和文件；卡片在两列间拖动
./run.sh router                        # 路由：侧栏、前进后退、链接、笔记滑入、设置页布局
./run.sh flyout                        # flyout：按钮下的菜单、系统 popover 里的同一个菜单、悬停卡片、托盘图标的 flyout；浮在所有应用之上的面板
php tests/run.php                      # UI 的无窗口测试：布局、按键、焦点、widgets 和 files 例子
php tests/bench.php                    # 无窗口压测：一帧的 build / layout / record / paint 耗时
./run.sh markdown-notes   # Markdown 笔记：搜索、工具栏、可拖分栏、实时预览、自动保存
./run.sh notes            # mygo 的 notes：富文本笔记、格式工具栏、格式菜单、链接、复制为 Markdown
./run.sh native           # mygo 的 native：网页调用 PHP，演示对话框、剪贴板、通知、右键菜单、托盘、全局快捷键、深色模式
./run.sh drag-drop        # mygo 的 drag-drop：笔记、URL 和文件在两个窗口及其他应用之间拖放
./run.sh richtext         # 富文本编辑器，带格式工具栏
./run.sh terminal         # 终端：你的 shell，由 Ghostty 的 libghostty-vt 模拟
./run.sh sqlite           # 网页经 PHP 插件用 SQLite；点 Reload 可以看到连接随页面结束
./run.sh watch [folder]   # 网页经 PHP 插件监视一个文件夹；按钮会改动其中的文件
./run.sh websocket [url]  # 网页经 PHP 插件连 WebSocket，默认连一个回显服务器
./run.sh updater --demo   # 更新窗口，用一个虚构的更新演示安装流程（--native：原生 UI 版）
./run.sh game             # 一个小游戏：WASD、方向键或手柄移动，滚轮缩放
./run.sh charts           # 图表：柱状图、实时折线、堆叠面积、散点图、环形图
./run.sh piano            # 合成音色的钢琴：鼠标、键盘、延音踏板、自动演奏曲子
./run.sh services         # 从网页调用 App 服务：网络、剪贴板、钥匙串、Cookie、页内查找、PDF…
./run.sh documents [x.md] # 文档型笔记：每个文件一个窗口、文件菜单、自动保存、崩溃恢复
./run.sh protocol         # myphp://localhost/ 上的前端、自定义 scheme 画的图、流式响应、下载
./run.sh photos ~/Pictures # 照片浏览：缩略图网格、单张全尺寸、方向键翻页
./run.sh http             # HTTP 客户端（curl 子进程）：请求列表、JSON 着色
./run.sh glass            # 照片上的 Liquid Glass
./run.sh widgets          # 小控件合集
./run.sh inbox            # 原生 UI，300 行列表，每秒打印一次 fps
./run.sh inbox 1000       # 每帧 9 000 个节点
./run.sh hello            # 移植自 mygo 的示例：页面通过绑定的服务打招呼
./run.sh counter-native   # 原生 UI 计数器：按钮和方向键
./run.sh text-buffer 100000 # 基于 TextBuffer 的十万行文本区
./run.sh text-input       # 应用自己绘制的文本框，经 TextInputClient 接输入法
./run.sh frameless        # 无边框透明窗口加 vibrancy，页面拖动窗口
./run.sh clipboard        # 多种格式的剪贴板（其一延迟生成）；数据拖出与拖入
./run.sh timeline         # tween 时间轴：先后、定时、按标签排布
./run.sh markdown-chat    # 回复按 token 流式到达，以 Markdown 逐词显现
./run.sh embed-webview    # 原生 UI 里的 web view，其上叠原生 popover、下拉和模态框
./run.sh menubar-flyout   # 托盘图标的 flyout，放在系统 popover 里
./run.sh menubar-native   # 菜单栏应用：托盘图标下的窗口，失去焦点即隐藏
./run.sh launcher         # 启动器：全局快捷键，边输边排序的已装应用及其图标
./run.sh vibrancy         # vibrancy 窗口：材质侧栏滑出收起
./run.sh todo             # Vite 页面里的待办：带类型的 PHP 服务与事件（需要 bun）
./run.sh confetti         # canvas-confetti 的演示，每片纸屑由只重绘的 pose 驱动
./run.sh effort-slider    # 力度滑杆，越过 Max 进入 Galaxy，字母变化时模糊
./run.sh farm             # 种田小游戏：翻地、播种、浇水、收获，昼夜与音乐
./run.sh mayoi            # 幸存者小游戏：对舞台恐惧唱歌、收集石子、找齐乐队
./run.sh gallery          # 工具包总览：所有控件、列表、图表、动效、语言
```

### 目录

```
bin/myphp     命令行：init、dev、build、micro
src/          App、Window、WindowOptions、Menu、Dialog、Ipc、TypeScript
src/Native/   操作系统桥接：ObjC 运行时、Metal、CoreText、事件视图
src/UI/       工具包：Context（建树）、Layout（flex）、Text、Runtime（输入）、Painter
examples/     可运行的示例
docs/         设计笔记
AGENTS.md     编码代理与贡献者的入口
```

### 许可

MIT。
