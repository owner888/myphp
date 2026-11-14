# Terminal

In a view, `Representable` puts a terminal among the declarative views:

<!-- no-test -->
```php
use MyPHP\Plugins\Terminal\{Options, Terminal};

$view = fn() => Representable(fn(Context $c) => Terminal::view($c, $this->term)->autoFocus(), fills: true);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](../ui/custom-widgets.md)).

The terminal plugin is a terminal for apps of [native UI](../ui/README.md):
a view that runs the user's shell, or any program, in a pseudo-terminal,
with the terminal emulator of [Ghostty](https://ghostty.org),
libghostty-vt. It is all PHP, with no npm package: the emulator is a
native library that the app loads at run time through FFI, and that
`myphp build` and `myphp dev` put into the app.

## Set up

Make a terminal, which starts its program, and show it in a window:

<!-- no-test -->
```php
use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Terminal\{Options, Terminal};
use MyPHP\UI\{Content, Context};

App::whenReady(function () {
    $win = null;
    $term = Terminal::new(new Options(
        onTitle: function (string $title) use (&$win) { $win?->setTitle($title); },
        onExit: function (int $code) use (&$win) { $win?->close(); },
    ));
    $win = new Window(new WindowOptions(title: 'Terminal', width: 760, height: 480,
        content: Content::view(fn() => Representable(fn(Context $c) => Terminal::view($c, $term)->fill()->autoFocus(), fills: true))));
    $win->on('closed', fn() => $term->close());
});
App::run();
```

The [terminal example](../../examples/terminal) shows this app (`myphp run -d macos
examples/terminal`). Its window uses the shell's title and closes when the shell
exits.

`Terminal::view()` is a node like any other: size it with `fill()` or
`grow`, and put it next to other nodes, as in a pane of an editor. Its
size sets the terminal's, in cells of its font, and a click gives it the
keyboard focus. A terminal shows in one view at a time. Its program starts
once a view first shows it, so that it draws its first screen at the
view's size, or half a second after `Terminal::new()` at 80×24 when no
view does. PHP runs the app on one thread: the terminal reads the
program's output on the run loop.

The terminal does what terminal apps do:

- colors (16, 256 and 24-bit), bold, italic, faint, inverse, strikethrough,
  overlines and underlines (single, double, curly, dotted, dashed, in their
  own colors);
- Unicode: wide characters, emoji and grapheme clusters take the cells of
  their width, as in Ghostty, and the system's fonts stand in for what the
  terminal's font lacks;
- box drawing, blocks and Powerline's separators drawn rather than taken
  from the font, so that the lines of full-screen programs join;
- a scrollback that reflows when the terminal resizes, and the alternate
  screen of full-screen programs;
- the keyboard as programs ask: legacy sequences, xterm's modifyOtherKeys
  or the Kitty keyboard protocol, and input methods, which compose at the
  cursor;
- mouse reports, focus reports, bracketed paste, synchronized output,
  hyperlinks (OSC 8), the title (OSC 0 and 2), the working directory
  (OSC 7), copying to and reading the clipboard (OSC 52, OSC 5522) as the
  app allows (see `clipboardRead`), and paste events of the Kitty
  clipboard protocol (OSC 5522, mode 5522), which let programs take
  pasted images;
- the shell's prompts and commands marked (OSC 133), by MyPHP's shell
  integration for zsh and bash (`shellIntegration`), so that a command
  that ran long can tell of its end (`notifyOnCommandFinish`).

## Using it

- **Selecting** with the pointer works as in Ghostty: a drag selects
  cells, a double click words and a triple click lines, and dragging past
  the top or the bottom scrolls. Option drags a rectangle. Shift-click
  extends an existing selection from its original anchor. While a program
  takes the mouse, as editors do, Shift selects.
- **The pointer** is Ghostty's: the I-beam over a shell, the arrow while a
  program takes the mouse (the I-beam again while Shift is held), and the
  shape a program asks for with OSC 22 until it takes or leaves the mouse
  again.
- **Copy and paste** are Command+C and Command+V, which the Edit menu's
  roles also send (see [Keys](#keys)). Pastes are bracketed when the
  program asks, so that pasted lines do not run as commands. The context
  menu has Copy, Paste, Select All and Clear Scrollback. Options add
  copying on select and other secondary clicks (see [Options](#options)).
- **Pasting images**: a program that reads the clipboard itself, as it
  asks with mode 5522 (as coding agents that accept images do), is told
  what a paste holds, every format of the clipboard as MIME types (the
  text, an image as PNG, files as `text/uri-list`, …), and reads what it
  wants, even over SSH. Other programs get the text alone. An image alone
  pastes an empty bracketed paste to a program that asks for bracketed
  pastes, as VS Code's terminal does, which agents take as their cue to
  read the image from the clipboard themselves; to any other, nothing.
- **Dropping files** on the view types their paths, quoted for the
  program's shell (`$term->dropFiles($paths)` does it from code).
- **Reading the clipboard**: a program asks with OSC 52 (`?`) or OSC 5522
  and is refused by default; `clipboardRead` allows it or asks the app
  first (see [Options](#options)). The clipboard is read, and the answer
  written, as the view builds a frame, so what the program reads is what
  the clipboard held then, not when it asked.
- **Scrolling**: the wheel and the touchpad scroll the scrollback, as do
  Shift+Page Up and Shift+Page Down. Full-screen programs get the wheel as
  arrow keys, or as mouse reports when they take the mouse. Typing scrolls
  back to the bottom.
- **Zooming**: the wheel with Command held, and Command with =, - and 0
  make the text larger, smaller, and its size again, a point a step, as in
  Ghostty; `onZoom` takes zooms instead.
- **Links**: Command+click opens a hyperlink a program printed (OSC 8), or
  a URL printed as text, even while a program takes the mouse; the pointer
  is a hand over them while Command is held.
- Shortcuts of the app come first: keys that the window or a node around
  the terminal handles with `$c->shortcut()`, or that a menu of the app
  has as its key equivalent on macOS, go there.

## Options

<!-- no-test -->
```php
$term = Terminal::new(new Options(
    command: ['htop'],                  // the user's login shell when empty
    dir: '/tmp',                        // the home directory when empty
    env: ['EDITOR=vim'],                // added to the app's environment
    font: new Font(family: 'JetBrains Mono', size: 14),
    theme: Terminal::darkTheme(),       // follows the window when null
    cursor: CursorStyle::CursorBar,     // a block by default
    onTitle: fn(string $title) => $win->setTitle($title),
    onExit: fn(int $code) => $win->close(),
));
```

(`Font` here is `MyPHP\Plugins\Terminal\Font`, not the UI's.)

- `command` runs a program instead of the shell. The shell runs as a login
  shell (`-zsh`), as terminal apps start it, so that it reads the user's
  profile: `$SHELL`, else `/bin/zsh` on macOS and `/bin/sh` elsewhere.
- `env` adds to the environment, which has `TERM=xterm-256color`,
  `COLORTERM=truecolor`, and `LANG` a UTF-8 locale when no locale is set,
  as for apps started from the Finder.
- `font` sets the font: the system's monospaced font at 13 points by
  default (see [Fonts](#fonts)).
- `theme` sets the colors: the foreground, the background, the cursor's
  and the text's under it, the selection's and the selected text's, and
  the palette of 16 colors; `darkTheme`, when set too, sets those of dark
  windows (see [Themes](#themes)). `Terminal::darkTheme()` and
  `Terminal::lightTheme()` are the window's background and text with
  Visual Studio Code's palette, and without a theme the terminal takes the
  one of the window's appearance as it changes. Programs may change the
  colors (OSC 4, 10, 11, 12).
- `cursor` and `noBlink` set the cursor, which programs may change.
- `scrollback` is about how many bytes of output to keep above the screen:
  10 MB by default, none when negative.
- `scrollMultiplier` scales scrolling, as Ghostty's
  `mouse-scroll-multiplier`: `new ScrollMultiplier(precision:, discrete:)`,
  `discrete` the rows a notch of a mouse wheel scrolls (3 by default) and
  `precision` scaling a touchpad's distance (1 by default).
  `setScrollMultiplier()` changes it in a running terminal.
- `minimumContrast` is the least contrast text has with its background, as
  Ghostty's `minimum-contrast`: a WCAG ratio from 1 (none, the default) to
  21. Text with less, as a program's light gray on a light theme, darkens
  or lightens as little as reaches it, keeping its hue.
  `setMinimumContrast()` changes it in a running terminal.
- `transparent` leaves the background undrawn, as Ghostty's
  `background-opacity`, so that what is under the view shows through: the
  window's material, or a node's translucent background. Cells with a
  background color of their own still draw it.
- `padding` is the room around the grid, in points across (`x`) and down
  (`y`), as Ghostty's `window-padding-x` and `window-padding-y`: 6 across
  and 4 down by default, none when negative. `paddingColor` is what it
  shows, as Ghostty's `window-padding-color`: the background by default,
  or with `PaddingColor::PaddingExtend` the backgrounds of the cells
  nearest to it, so that a full-screen program's colors reach the edges of
  the view. As Ghostty's `extend`, it extends only rows whose every cell
  has a background of its own; `PaddingExtendAlways` extends every row's.

  <!-- no-test -->
  ```php
  $term = Terminal::new(new Options(
      padding: new Padding(x: 14, y: 8),
      paddingColor: PaddingColor::PaddingExtend,
  ));
  ```
- `optionAsAlt` makes Option the Alt key of programs (Meta), instead of
  the key typing accented letters.
- `copyOnSelect: CopyOnSelect::CopyOnSelectClipboard` copies the
  selection to the clipboard as the pointer ends it, and as Select All
  makes it, as Ghostty's `copy-on-select = clipboard`.
  `setCopyOnSelect()` changes it in a running terminal.
- `keybinds` bind keys to actions, over the defaults (see [Keys](#keys)).
- `rightClick` is what a secondary click does, as Ghostty's
  `right-click-action`: the context menu by default,
  `RightClickAction::RightClickPaste`, `RightClickCopy`,
  `RightClickCopyOrPaste` (copy the selection, or paste when there is
  none) or `RightClickIgnore`. A program that takes the mouse still gets
  the click, unless Shift is held.
- `noClipboardWrite` keeps programs from writing to the clipboard (OSC 52
  and OSC 5522), as Ghostty's `clipboard-write = deny`; reads are governed
  by `clipboardRead`.
- `clipboardRead` is what programs get when they read the clipboard:
  `ClipboardAccess::ClipboardDeny` by default, as Ghostty's
  `clipboard-read = deny`, or `ClipboardAllow` to answer with what the
  clipboard holds, or `ClipboardAsk` to ask the app first. A read of a
  terminal no view shows waits, and one of a closed terminal is never
  answered.
- `onClipboardRequest` asks the app before a program reads the clipboard,
  when `clipboardRead` is `ClipboardAsk`. It gets a `ClipboardRequest`:
  the MIME types the program asked for (`mimes`), whether it asked for the
  types available (`list`), the selection it wants (`primary`) and what
  the clipboard held as text (`text`), for a prompt about it. `allow()`
  and `deny()` answer the request, in the hook or later, and the first of
  them wins; a request the hook leaves unanswered waits. Without the hook
  the terminal draws its own prompt in the view, one read at a time, with
  a one-line preview of what the program would read and Allow and Deny
  buttons:

  <!-- no-test -->
  ```php
  onClipboardRequest: function (ClipboardRequest $req) {
      if ($req->text === '' && !$req->list) {
          $req->deny(); // nothing to leak
          return;
      }
      askTheUser(fn(bool $ok) => $ok ? $req->allow() : $req->deny());
  },
  ```
- `onCopy` runs after the terminal writes text to the clipboard, with what
  made the copy: `CopySource::CopyUser` (the copy keys, the context menu,
  a secondary click that copies), `CopySelect` (`copyOnSelect`) or
  `CopyProgram` (OSC 52). An app may say so, as Ghostty's
  `app-notifications = clipboard-copy` does with a toast:

  <!-- no-test -->
  ```php
  onCopy: function (string $text, CopySource $by) {
      if ($by !== CopySource::CopyProgram) {
          showToast('Copied to clipboard');
      }
  },
  ```
- `onClear` sees the user's clear, before the terminal clears:
  `Clear::ClearScreen`, for `ActionClearScreen`'s keys and the context
  menu's Clear. It reports whether it took the clear, which then clears
  nothing here. It is not asked on a full-screen program's screen.
- `onTitle`, `onExit`, `onBell` and `onNotify` run when the program sets
  the title, ends, rings the bell or asks for a desktop notification (OSC
  9, OSC 777; `onNotify` gets a title and a body). `done()` says whether
  the program exited, and `exitCode()` returns its code (-1 for a program
  that could not start); the terminal then shows "[Process exited]".
- `notifyOnCommandFinish` tells of a command that ran at least
  `notifyOnCommandFinishAfter` seconds (5 by default) as it ends, as
  Ghostty's `notify-on-command-finish`: `NotifyOnCommandFinish::Never`
  (the default), `Unfocused` while the view has not the focus, or
  `Always`; `notifyOnCommandFinishAction` is a `CommandFinishAction`
  (`bell`, `notify`), the notification going through `onNotify`.
- `openLink` takes the links Command+click opens, and with it file paths
  printed as text too (`/abs/path`, `~/notes.md`, `./a.php`,
  `src/app.php:12:3`), as printed: a relative path is the program's. It
  reports whether it opened the link; a hyperlink or a URL it leaves opens
  with the system, as every one does without `openLink`.
- `onPaste` sees a paste of the clipboard, by the keys, a Paste menu item
  or a secondary click, with every format the clipboard holds as a
  `Transfer\Data`, and takes it by returning true: nothing is pasted. An
  app driving a remote shell uploads the clipboard's files
  (`$d->paths()`) or image (`$d->read(Format::PNG)`) this way, and pastes
  their remote paths with `paste()` instead:

  <!-- no-test -->
  ```php
  onPaste: function (Data $d) use (&$term): bool {
      $files = $d->paths();
      if ($files === []) {
          return false; // text pastes as usual
      }
      $term->paste(implode(' ', upload($files)));
      return true;
  },
  ```
- `onPasteStatus` tells how a paste to a program that reads the clipboard
  itself goes, with a `PasteProgress`: `PasteStatus::PasteOffered`, then
  `PasteSending` with `sent` of `total` bytes as the program reads, then
  `PasteSent`; or `PasteNotTaken` when the program has not read it 5
  seconds after the offer was written, or `PasteNoText` when a paste with
  no text goes to a program that reads neither the clipboard nor
  bracketed pastes.
- `onKey` sees a key press, its modifiers and key (`Key::*`), before the
  keybinds and before the terminal sends it, and takes it by returning
  true: neither the key nor the text it types reaches the program. Unlike
  a `$c->shortcut()` around the view, which runs with the next frame, it
  keeps its place among the keys typed after it, as does what it sends
  with `send()`.
- `onScroll` sees the wheel scroll the view by whole rows (negative up)
  before the terminal scrolls its scrollback, and takes it by returning
  true: the scrollback stays. It is not asked while a program gets the
  wheel.
- `onZoom` sees a zoom: the wheel with Command held, or a key bound to a
  font size action. Its `Zoom` has how many `steps`, rolling up positive,
  or `reset`. It takes the zoom by returning true; without it, or for a
  zoom it leaves, the terminal changes its font's size itself, a point a
  step, down to 1, and back to the font's `size` on a reset.

## Keys

The terminal binds keys to actions, as Ghostty's `keybind` option does,
with Ghostty's macOS defaults, which `Terminal::defaultKeybinds()`
returns:

| Action | Keys |
| --- | --- |
| `ActionCopy` | Cmd+C |
| `ActionPaste` | Cmd+V |
| `ActionSelectAll` | Cmd+A |
| `ActionClearScreen` | Cmd+K (performable) |
| `ActionIncreaseFontSize` | Cmd+=, Cmd++ |
| `ActionDecreaseFontSize` | Cmd+- |
| `ActionResetFontSize` | Cmd+0 |
| `ActionScrollPageUp`, `…Down` | Shift+ or Cmd+Page Up/Down |
| `ActionScrollToTop`, `…Bottom` | Cmd+Home/End |

The keys that clear the screen and scroll with Command are performable,
so that a full-screen program, as an editor, gets them. `keybinds` bind
more, over the defaults: a binding replaces the defaults of its key and
modifiers, and one to `ActionNone` unbinds them, so that the program gets
the key; `noDefaultKeybinds` starts from none, as Ghostty's `keybind =
clear`:

<!-- no-test -->
```php
$term = Terminal::new(new Options(keybinds: [
    // Windows Terminal's Control+C and Control+V.
    new Keybind(Key::CONTROL, Key::C, Action::ActionCopy, performable: true),
    new Keybind(Key::CONTROL, Key::V, Action::ActionPaste, performable: true),
    // Command+A reaches the program.
    new Keybind(Key::COMMAND, Key::A, Action::ActionNone),
]));
```

A performable binding, as Ghostty's `performable:` prefix, takes the key
only when its action does something: a copy only with a selection, a
paste only when the clipboard holds something, clearing the screen and
scrolling only off a full-screen program's screen; otherwise the key goes
on to the program, as if unbound: Control+C interrupts when nothing is
selected. A performable copy also lets go of the selection, so that the
key's next press interrupts. `ActionClearScreen` clears the scrollback and
the screen above the cursor's line, which moves to the top with the
shell's prompt, as Kitty's `clear_terminal to_cursor`, and the context
menu's Clear does it too; `clearScreen()` does the same from code, and
`clearScrollback()` clears the scrollback alone. `ActionIgnore` takes the
key and does nothing.

A key goes first to the app's shortcuts, then to `onKey`, then to the
keybinds, then to the program. The context menu shows the keys bound to
its items.

## Fonts

`MyPHP\Plugins\Terminal\Font` holds what Ghostty's font options set:

<!-- no-test -->
```php
$term = Terminal::new(new Options(font: new Font(
    family: 'JetBrains Mono, Menlo, monospace', // tried in order
    size: 14,                                    // points, 13 when 0
    weight: 500,                                 // 400 when 0
    features: ['-calt', '+ss01'],                // as Ghostty's font-feature
    lineHeight: 1.2,                             // as adjust-cell-height = 20%
    thicken: true,                               // as font-thicken, on macOS
)));
```

- `family` is a list of families, as `Node::font()` takes it: the
  system's monospaced font when empty. Characters the fonts lack come from
  the system's fallback fonts. A font the app carries, rather than one the
  user installed, is added with `MyPHP\UI\Font::register()`, its styles
  under one family so that bold and italic text take them:

  <!-- no-test -->
  ```php
  foreach (['Regular', 'Bold', 'Italic', 'BoldItalic'] as $name) {
      \MyPHP\UI\Font::register(file_get_contents(__DIR__ . "/fonts/JetBrainsMono-$name.ttf"), 'JetBrains Mono');
  }
  ```

- `weight` sets how heavy text is, from 100 to 900, of the weights the
  family has; bold text is 300 heavier, at least 700.
- `features` turn OpenType features on and off: `"ss01"` or `"+ss01"` on,
  `"calt=0"` or `"-calt"` off, `"cv05=2"` an alternate. Coding fonts make
  most of their ligatures with `calt`, the others with `liga`; a ligature
  spans the cells of its characters.
- `lineHeight` makes rows taller or shorter than the font's line height,
  with the text centered in them; box drawing still joins.
- `thicken` draws text with a thicker stroke on macOS, with Core Text's
  font smoothing at its strongest, as Ghostty does. It changes nothing on
  Linux, as in Ghostty.

`setFont()` changes the font of a running terminal; the grid then takes
the new size of its cells.

## Themes

The plugin carries the hundreds of themes Ghostty ships (from
[iTerm2-Color-Schemes](https://github.com/mbadolato/iTerm2-Color-Schemes)),
which `Terminal::ghosttyTheme()` returns by name, as Ghostty's `theme`
option takes it:

<!-- no-test -->
```php
$term = Terminal::new(new Options(
    theme: Terminal::ghosttyTheme('Catppuccin Latte'),    // in light windows
    darkTheme: Terminal::ghosttyTheme('Catppuccin Mocha'), // in dark windows, as theme = light:…,dark:… in Ghostty
));
```

As in Ghostty, a theme file of the user's in Ghostty's themes directory
(`$XDG_CONFIG_HOME/ghostty/themes`, `~/.config/ghostty/themes` by default)
comes first, and an absolute path names a theme file.
`Terminal::ghosttyThemes()` lists the names, the user's themes with
Ghostty's, for a picker, and `Terminal::parseGhosttyTheme()` reads a theme
in Ghostty's format from a string:

```
palette = 0=#45475a
palette = 1=#f38ba8
background = #1e1e2e
foreground = #cdd6f4
cursor-color = #f5e0dc
cursor-text = #1e1e2e
selection-background = #f5e0dc
selection-foreground = #1e1e2e
```

`setTheme($theme, $dark)` changes the themes of a running terminal.

## Methods

- `send()` sends bytes to the program as if typed, and `paste()` pastes
  text. `pasteData()` pastes a `Transfer\Data`, as the paste keys paste
  the clipboard: a program that reads the clipboard itself is offered
  every format, as MIME types, and reads those it wants, when it asks; any
  other gets the text.
- `feed()` shows bytes as if the program wrote them, text and escape
  sequences.
- `title()`, `dir()` (the directory the shell reported), `size()` (in
  cells) and `text()` (the screen and its scrollback) read the terminal.
- `hoveredLink()` returns the link Command is held over in the view, as
  `openLink` takes it, whether it is a file path, and the rectangle of its
  cells relative to the view: read it after `Terminal::view()` to show a
  preview of the link over the view.
- `setFont()` and `setTheme()` change the font and the colors; `setFont()`
  also sets the size a zoom resets to, and drops the zoom.
- `resize()` sets the size of a terminal no view shows, and `snapshot()`
  returns what a terminal shows as escape sequences: its scrollback and
  screen with their styles, the cursor and the modes programs set. Fed to
  a new terminal of the same size, it shows the same.
- `useTheme()` gives a terminal no view shows the colors programs asking
  for them get, as a server's for the window that shows its session.
- `close()` hangs up the program, which gets SIGHUP, and frees the
  terminal.

## Without a program

`conn` connects the terminal to anything that reads and writes, such as an
SSH session or a serial port, instead of a program: what the terminal
reads from it shows, and what is typed is written to it. A `Conn` is an
interface of `read()` (`''` for nothing yet, `null` once it ended),
`write()` and `close()`, which must not block: the terminal polls it on
the run loop. One with a method `resize(int $cols, int $rows)` is told the
size of the terminal:

<!-- no-test -->
```php
$term = Terminal::new(new Options(conn: $session));
```

A terminal whose `Conn` never sends anything shows what the app writes
with `feed()`, as a log with colors would.

## The library

libghostty-vt is a native library of Ghostty, built for each platform
from the version of Ghostty the plugin binds, and published with the
upstream framework's releases. The plugin names those builds and their
SHA-256 in `mygo-plugin.json`, and the CLI puts the one of each platform
into the apps it builds, among their resources, as `libghostty-vt.dylib`
or `libghostty-vt.so`: a macOS app signs it with the app, and a universal
app gets both architectures in one file.

A script not built by the CLI, as `php app.php` or the tests, downloads
the library once into the user's cache and checks its SHA-256. Packaged
apps never download it. `Terminal::libraryPath()` returns the library a
program loads, and `$MYGO_GHOSTTY_VT` names another library.

`Terminal::load()` loads the library, which `Terminal::new()` does too:
call it first to report a missing library before showing a window.

The library runs on macOS 13 or later, as Ghostty does, so apps with a
terminal set `minimumSystemVersion` to 13.0 or later; and on Linux with
glibc 2.28 or later, on x64 and arm64.

## Testing

The headless harness (`tests/Headless.php`) runs a terminal view without a
window: type into it, and read what the program printed with `text()`, as
`tests/terminal_test.php` does.
