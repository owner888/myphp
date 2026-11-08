# Code editor

`CodeEditor($state)` edits code, taking the room it is given: `$state` an
`Editor\State` the app keeps, over a `Buffer`, highlighted, completed and
diagnosed as it says.

<!-- no-test -->
```php
$view = fn() => CodeEditor($this->editor);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

The classes of `MyPHP\UI\Editor` are a code editor and a [diff
viewer](diff-viewer.md) for native UI, designed after Zed's: syntax
highlighting, several carets and selections, soft wraps, folding, finding
and replacing, completions, hovers, diagnostics and inlay hints from the
app or a language server, and the commands and keys of code editors. It
lays out and paints only the lines in view, so a file of a million lines
scrolls and edits as one of ten.

```php
use MyPHP\UI\Editor\{Buffer, Languages, State};

// Kept by the app, as its other state.
$buf = new Buffer("<?php\n\necho 'Hello';\n");
$buf->setLanguage(Languages::for('main.php'));
$code = new State($buf);
$code->wrapGuides = [100];

$view = function (Context $c) use ($code) {
    $code->view($c)->grow(1)->fillWidth();
};
```

A `State` is the editor's state, kept across frames as a text input's
`TextState` is: its buffer, its options, and what the editor keeps of its
own, as its selections, folds and scroll position. Its options are
properties set before the editor is built; their defaults are those of
Zed. `view($c)` builds the editor of a state, which is a focusable node as
any other: give it a size, and `autoFocus()` to type in it at once.

The [code editor example](../../examples/code-editor) edits files of
several languages.

## Buffers

A `Buffer` is the text an editor edits, with its undo history and its
language. Several editors, and both sides of a diff, can show one buffer:
an edit in one shows in the others, their carets moving with the text
around them, and undo takes back the buffer's last edit, whichever editor
made it.

```php
use MyPHP\UI\Editor\{Buffer, Edit};

$buf = new Buffer("one\ntwo\n");
$buf->applyEdits(new Edit(0, 0, "// Generated.\n"));
$buf->transact(function () use ($buf) { // one step of undo
    $buf->applyEdits(new Edit(14, 17, 'uno'));
    $buf->applyEdits(new Edit(18, 21, 'dos'));
});
if ($buf->modified()) {
    $saved = (string)$buf; // write it where it goes
    $buf->markSaved();
}
```

Offsets count bytes of UTF-8, and a `Pos` is a line and the bytes into it,
both from 0: `pos()` and `offset()` convert between them, and `line()`,
`slice()` and `(string)` read the text. `set()` replaces the text as an
edit, which undo takes back, and `reset()` as a new document, without
history; `undo()` and `redo()` step through it.

Lines end in `\n`, as in Zed: a text with `\r\n` line endings is kept with
`\n` ones, and `lineEnding()` says which it had, for the file to save as
it was; `setLineEnding()` changes it.

The text is a persistent tree of chunks, as Zed's rope: an edit copies a
few kilobytes and a path of the tree, whatever the size of the document,
and each version shares the rest with the one before. Undo keeps versions
rather than copies, and a `Snapshot` (`snapshot()`) is a version that stays
as it is while the buffer changes. Change a buffer from outside the view
with `Window::update()` so the window shows the change.

## Editing

The editor edits as Zed does, with the commands bound to the same keys:

- **Several carets.** Cmd+D selects the next occurrence of the selection,
  or the word at the caret; Cmd+Shift+L all of them; Cmd+Alt+Up and Down
  add a caret on the line above or below; Alt+click adds a caret, and
  Alt+Shift+drag selects a column. Typing, deleting and every command act
  at each caret, and Escape goes back to one. Cmd+U undoes a change of the
  selections.
- **Selections by structure.** Ctrl+Shift+Right selects the word, then the
  string or brackets around it, and on out; Ctrl+Shift+Left goes back.
- **Lines.** Alt+Up and Down move lines, Alt+Shift+Up and Down duplicate
  them, Cmd+Shift+K deletes them, Ctrl+J joins them, Cmd+/ comments them
  out, Cmd+] and Cmd+[ indent them; Cmd+Enter opens a line below.
- **Typing.** Brackets and quotes close as they are typed, and wrap the
  selection; Enter keeps the indentation, indents after an opening bracket
  and between a pair, and continues line comments; a closing bracket, or a
  word such as `end`, outdents its line as it is typed. Undo takes back
  typing a burst at a time.
- **Folding.** The gutter's markers fold the lines indented under a line,
  up to its closing bracket, or a run of imports, with the blank lines
  between them; Cmd+Alt+[ and ] fold and unfold, Cmd+K Cmd+0 folds
  everything and Cmd+K Cmd+J unfolds it, Cmd+K Cmd+1 to 9 by level.
- **Finding.** Cmd+F opens the find bar, with case, whole words, regular
  expressions and finding in the selection; Cmd+Alt+F replaces. Cmd+G goes
  to the next match, and Ctrl+G to a line.
- **The pointer.** A double click selects a word, a triple click a line,
  and dragging extends by them; dragging near the edges scrolls; Cmd+click
  goes to a definition.

`run($command)` runs a command, a `Command` constant, as a menu item or
the app's own key does, and `bindings` binds keys: a `Binding` of mods and
key, then the mods and key of a chord's second key (null for none), and
the command, as Cmd+K Cmd+0. An app's bindings come before the defaults
(`Keymap`), and a binding to `Command::NO_COMMAND` takes a key away from
the editor, for the app's own shortcut. `Command::named()` finds a command
by the name keymaps give it, as "selectNext".

```php
use MyPHP\UI\Editor\{Binding, Command, State};

$code = new State();
$code->bindings = [
    new Binding(Key::COMMAND, Key::P, 0, null, Command::NO_COMMAND), // the app's
    new Binding(Key::COMMAND | Key::SHIFT, Key::D, 0, null, Command::DUPLICATE_SELECTION),
];
$view = function (Context $c) use ($code) {
    if ($c->clicked($c->button('Sort'))) {
        $code->run(Command::SORT_LINES);
    }
    $code->view($c)->grow(1);
};
```

`selections()`, `setSelections()`, `select()`, `cursor()` and
`cursorPos()` read and set the carets and selections, `insert()` types at
each of them, `goTo($pos)` moves the caret to a position and
`scrollTo($line)` shows a line. `find($query)` opens the find bar on a
query and `findMatches()` returns its matches; `fold()`, `unfold()` and
`folded()` fold lines, and `foldImports()` the first run of imports, as an
editor can on opening a file; `focused()` reports whether the editor has
the keyboard focus, and `visibleLines()` the first and last lines the last
frame showed, as a half page of Vim's moves by. `scrollY()` and
`setScrollY()` read and set how far the lines are scrolled, in points, and
`lineTop($line)` where a line starts, below the first: editors side by side
scroll together with them. The scroll bars' thumbs show while the pointer
is over the editor, or drags one, as a scroll container's do; the marks of
changes, matches and diagnostics on the vertical one show all the time.

`onInput` sees each input event before the editor does: keys, the text
typed, edit commands and the pointer. Returning true takes the event, and
the editor leaves it; the others go on as without it. An app's own mode of
editing, as Vim's normal mode, takes keys this way while the editor still
shows and moves the carets. Letters typed at the carets come as
`InputEvent::TEXT`. What an input method composes goes to the editor
alone. `noInputMethod` turns the system's input methods off while the
editor has the keyboard, as Neovide does in Vim's normal mode:

```php
use MyPHP\UI\Editor\{CursorShape, State};

$normalKeys = fn(string $text): bool => in_array($text, ['j', 'k'], true);
$code = new State();
$code->cursorShape = CursorShape::CursorBlock; // normal mode
$code->noInputMethod = true;
$code->onInput = function (InputEvent $ev) use ($normalKeys): bool {
    if ($ev->kind === InputEvent::TEXT) {
        return $normalKeys($ev->text); // j, k, dd, …
    }
    return false;
};
```

## Options

- `softWrap` wraps lines at the editor's width, or at `wrapColumn` columns
  when narrower; wrapped rows keep the line's indentation.
- `lineNumbers` shows the gutter's numbers, absolute
  (`LineNumbers::LineNumbersOn`), relative to the caret's line, or not at
  all; `gutterMarks` put dots of a color by lines (`GutterMark`), as
  breakpoints, and `onGutterClick` reports clicks on numbers.
- `lineBackgrounds` fill lines (`LineBackground`, from `first` to `last`)
  with a color under the text and the selections, as the statement a key
  would run; the gutter keeps its own, and lines a fold hides take no room
  to fill. They keep their line numbers as the text is edited: compute
  them again when the buffer's `version()` changes.
- `textBackgrounds` fill ranges of the text (`TextBackground`, bytes
  `start` to `end`) with a color under the text and the selections and
  over line backgrounds, as the words a merge changed within its lines.
- `tabSize` and `indent` set indentation: tabs or spaces, as the
  language's files usually have them by default.
- `whitespace` shows spaces and tabs as dots and arrows: within selections
  by default, or nowhere.
- `wrapGuides` draws guides at columns, as 80 and 100; `noIndentGuides`
  hides those of indentation.
- `readOnly` lets the text be selected and copied, not edited.
- `fitContent` makes the editor as high as its lines, rather than
  scrolling them, to show code in a scroll view or a list.
- `fontSize`, `fontStyle` and `lineHeight` set the text: the system's
  monospaced font of 13 points, 1.5 times as high, by default.
- `noAutoClose`, `noFolding`, `noActiveLine` and `noScrollPastEnd` turn
  those behaviors off. `placeholder` shows while the buffer is empty.
- `cursorShape` paints carets as bars (`CursorShape::CursorBar`), as blocks
  over the character after them (`CursorBlock`), as Vim's normal mode
  does, or as underlines (`CursorUnderline`).
- `diffBase` is the text the buffer's changes show against in the gutter,
  as the file in Git: bars mark the lines added and changed, and a mark
  where lines were deleted.
- `contextMenu` adds items to the editor's context menu, after its own.
- `label` names the editor for assistive technology.

## Languages and highlighting

`Languages::for($filename)` finds a file's language by its name, and
`Languages::named()` by its own; `Languages::all()` lists them. The editor
knows Go, JavaScript, TypeScript, JSON, Python, Rust, C, C++, Java,
Kotlin, Swift, Dart, C#, shell scripts, YAML, TOML, SQL, Ruby, Lua, PHP,
CSS, HTML, XML, Markdown, diffs and Zig, among others. A `Language` says
how to highlight, comment, match brackets and indent its text, and with
`imports`, a pattern of a line that imports, which lines fold as a run:
build one for another language.

A language highlights its text with a `Tokenizer` by default: from a line,
and the state the line before it ended in, to the line's tokens and the
state it ends in, as TextMate grammars and Monaco's tokenizers work. The
editor tokenizes the lines it shows, and after an edit only the lines below
it until one starts in the state it started in before, a frame's time
budget at a time.

```php
use MyPHP\UI\Editor\{Language, Token, Tokenizer};

// Lines starting with # are comments.
$lang = new Language('Notes', extensions: ['.notes'], lineComment: '#', tokenizer: new class implements Tokenizer {
    public function tokenize(string $line, int $state, array $tokens): array
    {
        $kind = str_starts_with(ltrim($line), '#') ? Token::COMMENT : Token::TEXT;
        $tokens[] = [strlen($line), $kind];
        return [$tokens, 0];
    }
});
```

A token is `[end, kind]`, a run of a line of one kind (`Token::KEYWORD`,
`TYPE`, `FUNCTION`, `STRING`, `COMMENT` and the others), which the theme
colors.

### Highlighters

A language can instead bring a highlighter of its own, as one of a parser:
its `highlighter` makes the `Highlighter` of each buffer of the language,
and the editor uses it in place of the tokenizer. The tokenizer itself is
one (`TokenHighlighter`), so a highlighter plugs in without the editor
changing. A highlighter follows the buffer's edits (`edit()`), works toward
the tokens of the lines it is asked for within a time budget
(`highlight()`), and gives a line's tokens (`tokens()`). One that knows the
structure of the text can tell the editor more, by implementing
`SyntaxSelector` (what Select Larger selects), `Folder` (what a fold
hides), `BracketMatcher` (the pair around a range) or `Indenter` (how far
a line Enter opens is indented).

## Completions, hovers and diagnostics

The editor shows what the app or a language server knows of the code; the
app answers its requests, at once or later through `Window::update()`:

```php
use MyPHP\UI\Editor\{Buffer, Completion, CompletionRequest, Diagnostic, HoverRequest, Severity, State};

$buf = new Buffer("echo strlen('x');\n");
$code = new State($buf);
$code->onCompletion = function (CompletionRequest $r) use ($code) {
    $items = [new Completion('strlen', 'function'), new Completion('strtolower', 'function')];
    $code->showCompletions($r->offset - strlen($r->prefix), $items);
};
$code->onHover = function (HoverRequest $r) use ($code) {
    $code->showHover($r->offset, $r->offset + 6, 'Returns the length of a string.');
};
$code->setDiagnostics($buf->version(), [new Diagnostic(5, 11, Severity::SeverityWarning, 'Unused result')]);
$view = fn(Context $c) => $code->view($c)->grow(1);
```

- `onCompletion` asks for completions as a word is typed, or Ctrl+Space
  pressed. `showCompletions()` lists them below the caret, filtered and
  ranked as typing goes on by the word typed, with their kinds, details
  and documentation; Enter or Tab inserts the one chosen.
- `onHover` asks what to show as the pointer rests on the text.
  `showHover()` shows text in a popover over the range, or what a function
  builds there. The pointer can go into the hover, which stays while it is
  there, to select and copy its text; Escape closes it.
- `setDiagnostics()` underlines problems with wavy lines of their
  severity, shows their messages in the hover and marks them on the scroll
  bar. The version is the buffer's the diagnostics are of: the editor moves
  them through the edits made since.
- `setInlays()` shows inlay hints, text in the lines that is not theirs, as
  the types of variables; `setSuggestion()` shows text after the caret as a
  suggestion, which Tab accepts, as an AI's.
- `onDefinition` is called for Cmd+click and F12 on a word.

## Styling

### Themes

An editor `Theme` colors the editor: its background, text, gutter,
selections, carets, guides, diagnostics, changes (its `c`, by the names of
`Theme::NAMES`) and each kind of token (its `tokens`). Colors are
`[0xRRGGBB, alpha]`; those left null take those of `Theme::defaults()`,
which follows the window's theme and appearance, light or dark.

```php
use MyPHP\UI\Editor\{State, Theme as CodeTheme, Token};

$code = new State();
$view = function (Context $c) use ($code) {
    $th = CodeTheme::defaults($c->theme);
    $th->tokens[Token::KEYWORD] = ['color' => [0xc678dd, 1.0], 'italic' => false, 'bold' => true];
    $code->theme = $th;
    $code->view($c)->grow(1);
};
```

### Bars and popups

The find bar, the go to line bar, the completions and the hovers are parts
the app can build itself, as it styles the [bases](custom-widgets.md):
`renderFindBar`, `renderGoToLine`, `renderCompletions` and `renderHover`
are each given the node the editor places (a toolbar over its top right
corner, a bar at its top, a list below the caret, a card above the text
hovered) and a handle to what the part shows and does (`FindBar`,
`GoToLineBar`, `CompletionMenu`, `HoverCard`). Style the node and build its
content.

## Accessibility

Assistive technology sees a multi-line text field, named by `label`, with
the text of the buffer and the newest selection, which it moves; the
completions are a list, and the find bar a toolbar that tells the count of
matches. The editor's menu, its find and go to line bars and their names
are in the window's language ([MyPHP's own
strings](../i18n.md#myphps-own-strings)). Input methods compose in the
editor as in other text fields, with their candidate window at the caret.
