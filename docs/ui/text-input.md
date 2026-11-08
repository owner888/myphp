# Text input

`TextField(title, text:)` edits a string on one line, `axis:
Axis::vertical` growing with its lines; `SecureField` a password;
`TextEditor(text:)` several lines; all bound to a property, as SwiftUI's,
with selection, undo, the clipboard and input methods.

```php
$app = new class { public string $name = ''; public string $notes = ''; public int $sent = 0; };
$view = fn() => Form()(
    TextField('Name', text: $app->name)->onSubmit(fn() => $app->sent++),
    TextEditor(text: $app->notes)->frame(height: 80),
);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

`$c->input()` creates a single-line text input editing a `TextState`, and
`$c->textarea()` a multi-line one. They change the state's `text` as the
user types: read it, or ask `$c->changed()` for work that follows, and
`$c->submitted()` for Enter in a single-line input. Keep the `TextState`
in the app's state: it holds the text, the selection and the undo history
from frame to frame.

```php
$app = new class {
    public TextState $query, $name, $notes;
    public array $results = [];
    public string $title = '';
    public function __construct() { [$this->query, $this->name, $this->notes] = [new TextState(), new TextState(), new TextState()]; }
};
$view = function (Context $c) use ($app) {
    if ($c->changed($c->input($app->query, 'Search')->label('Search'))) {
        $app->results = [$app->query->text];
    }

    if ($c->submitted($c->input($app->name)->autoFocus())) {
        $app->title = $app->name->text;
    }

    $c->textarea($app->notes, height: 160);
};
```

`input($state, $placeholder, $width, $size)` is 200 points wide by
default, `textarea($state, $placeholder, $width, $height, $size,
$minLines, $maxLines)` 320 by 120; a width or height of 0 fills the room
its container has left along its axis.

- The placeholder shows a text while the input is empty, on one line in an
  input, wrapped in a text area; `placeholderColor()` colors it.
- `password()` hides what it holds, and keeps it from the clipboard and
  input methods, in the frames that call it: an eye button that shows the
  text stops calling it. Only an `input()` takes it: as on every platform,
  a text area has no password mode.
- `autoFocus()` gives it the keyboard focus as it appears, as the first
  field of a dialog.
- `$state->readOnly = true` shows the text without letting the user change
  it: it still takes the focus, without a caret, and its text can be
  selected and copied.
- `disabled(true)` grays it out.
- `$state->onPaste` sees a paste (Cmd+V, the Edit menu's or the context
  menu's Paste) before it is inserted, with the clipboard's text, empty
  when it holds none, as with an image. Returning true takes the paste,
  which then changes nothing, as `preventDefault` does on the web:

  ```php
  $app = new class {
      public TextState $draft;
      public array $attachments = [];
      public function __construct() { $this->draft = new TextState(); }
  };
  $app->draft->onPaste = function (string $text) use ($app): bool {
      if (strlen($text) > 10_000) {
          $app->attachments[] = $text; // a large block becomes an attachment
          return true;
      }
      return false;
  };
  $view = function (Context $c) use ($app) {
      $c->textarea($app->draft, minLines: 1, maxLines: 8);
  };
  ```

- `$state->onTextKey` sees a key that types no text before the input takes
  it: Enter, Tab, Escape, Backspace and Delete, the arrows and the other
  keys that move the caret, and chords with Cmd or Ctrl, as AppKit's
  `doCommandBySelector` and a web page's `keydown` do. It gets the key's
  code, its characters, the modifiers and the state, and returning true
  takes the key: a text area inserts no newline, an input does not submit,
  Tab does not move the focus. A key left goes on as without it. It may run
  between frames, so it reads and replaces the text through the state it
  gets, as a chat composer sends with Enter, breaks the line with
  Shift+Enter, and puts the last message back with Up:

  ```php
  $app = new class {
      public TextState $draft;
      public array $sent = [];
      public function __construct() { $this->draft = new TextState(); }
  };
  $app->draft->onTextKey = function (int $key, string $chars, int $mods, TextState $s) use ($app): bool {
      if (($key === Key::RETURN || $key === Key::ENTER) && $mods === 0) {
          $app->sent[] = $s->text;
          $s->text = '';
          $s->setCursor(0);
          return true;
      }
      if ($key === Key::UP && $mods === 0 && $s->text === '' && $app->sent !== []) {
          $s->text = end($app->sent);
          $s->setCursor(strlen($s->text));
          return true;
      }
      return false; // Shift+Enter breaks the line
  };
  $view = function (Context $c) use ($app) {
      $c->textarea($app->draft, minLines: 1, maxLines: 8);
  };
  ```

An input too narrow for its text keeps the caret in view while it has the
focus, and shows the start of its text without it.

`textRanges()` colors runs of the text, in the frames that call it, as a
message field shows the mentions in what is typed:

```php
$app = new class { public TextState $draft; public function __construct() { $this->draft = new TextState('Ask @ada and @bob'); } };
$view = function (Context $c) use ($app) {
    $in = $c->textarea($app->draft, minLines: 1, maxLines: 8);
    preg_match_all('/@\w+/u', $app->draft->text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$mention, $byte]) {
        $start = mb_strlen(substr($app->draft->text, 0, $byte));
        $in->textRanges(new TextRange($start, $start + mb_strlen($mention), $c->theme->accent));
    }
};
```

The ranges are runes of the text, found in it each frame. They may overlap
and nest: where ranges overlap, the color is the last given range's,
across the calls of the frame. A password shows none. While an input
method composes, its text sits unstyled at the caret and the ranges keep
their style around it.

A text area given a height scrolls within it, with the wheel and a scroll
bar, and keeps the caret in view as it moves. `minLines` and `maxLines`
make it as high as its text wraps at its width, from `minLines` lines up
to `maxLines`, past which it scrolls, as a message field grows with what
is typed:

```php
$app = new class { public TextState $draft; public function __construct() { $this->draft = new TextState(); } };
$view = function (Context $c) use ($app) {
    $c->textarea($app->draft, minLines: 1, maxLines: 8);
};
```

A text area lays out only the paragraphs in view and keeps their layouts
until they change, so that it holds texts of hundreds of thousands of
lines, as a log or a source file, and stays as quick to type in. For texts
the program also changes, `$c->textInputBuffer()` and
`$c->textAreaBuffer()` edit a `TextBuffer`, where an edit replaces only
what changed.

## Editing

Text inputs edit as the platform's text fields do: selection with the
pointer (a double click selects a word, a triple click a line), with Shift
and the arrows, and by words and lines with the platform's keys; undo and
redo (`$state->undo()`, `redo()`); cut, copy and paste, also from the Edit
menu's roles; and a context menu of the editing commands. They take text
composed with input methods, which see the text around the caret, so that
press and hold, Japanese conversion and predictions work as in other apps.
The keys typed while an input method composes are its own, as Enter
choosing a candidate or Escape giving the composition up: they submit
nothing, press no shortcut and close no dialog. `$state->marked` holds the
text being composed, not yet in the text, and null otherwise.

Setting the state's `text` from elsewhere replaces the text.

## The caret

`textSelection()` returns the selection as offsets in runes into the text,
the caret where they are equal, and `setTextSelection()` moves it, as an
app completing the word being typed puts the caret after it:

```php
$app = new class { public TextState $draft; public string $completed = 'world'; public function __construct() { $this->draft = new TextState('Hello wo'); } };
$view = function (Context $c) use ($app) {
    $c->textarea($app->draft);
    [$start] = $app->draft->textSelection();
    if ($app->completed !== '') {
        $app->draft->text = 'Hello ' . $app->completed;
        $start = mb_strlen($app->draft->text);
        $app->draft->setTextSelection($start, $start);
        $app->completed = '';
    }
};
```

`textSelection()` returns the selection in order. `textAnchorCaret()`
returns it from where it was started to its caret, so an app can tell a
selection made backwards from one made forwards: an editor's own keys that
extend a selection, such as Shift-Home moving to the first character of
the line, extend it from its anchor:

```php
$draft = new TextState("one\ntwo");
[$anchor, $caret] = $draft->textAnchorCaret();
$lineStart = mb_strrpos(mb_substr($draft->text, 0, $caret), "\n");
$draft->setTextSelection($anchor, $lineStart === false ? 0 : $lineStart + 1);
```

## Beside the lines

`trackLines()` keeps in a `TextLines` where a text area lays out the lines
of its text, for an app that paints beside them: numbers by the first row
of each line, or marks on runs of a line. A line is the text between
newlines, on several rows where the area wraps it. `top($i)` is the top of
line `$i` in the area's content, below its padding and before it scrolls,
`at($y)` the line at a height, `rows($i)` the runes where each row of line
`$i` starts, and `height()` the height of the text. With the area's scroll
offset, `$state->scrollY`, a gutter numbers the lines in view:

```php
$app = new class {
    public TextState $source;
    public TextLines $lines;
    public function __construct() { $this->source = new TextState("<?php\necho 'hi';\n"); $this->lines = new TextLines(); }
};
$view = function (Context $c) use ($app) {
    $pad = 8; // the text area's top padding
    $c->row(grow: 1);
      $c->column()->width(48)->fillHeight()->draw(function (Painter $p, array $r) use ($app, $pad) {
          [$x, $y, $w, $h] = $r;
          $top = $y + $pad - $app->source->scrollY; // the top of the area's content
          $p->clip($r, function () use ($p, $app, $x, $y, $h, $top) {
              for ($i = $app->lines->at($app->source->scrollY); $i < $app->lines->count() && $top + $app->lines->top($i) < $y + $h; $i++) {
                  $p->text($x + 8, $top + $app->lines->top($i), (string)($i + 1), 12, $p->theme()->textMuted);
              }
          });
      });
      $c->end();
      $c->textarea($app->source, width: 0, height: 0)->padding($pad, 12)->grow(1)->fillHeight()->trackLines($app->lines);
    $c->end();
};
```

The heights of the lines the area has not laid out are estimates, as its
scroll bar's are, until it shows them. In `draw()` the lines are those of
the frame; while the view is built, those of the last one.

## Errors

In a [field](form.md), an error marks the value invalid: the input draws
its border in the theme's `danger` color, and assistive technology reads
the message with it.

```php
$app = new class { public TextState $email; public string $emailError = 'Enter an email address.'; public function __construct() { $this->email = new TextState(); } };
$view = function (Context $c) use ($app) {
    $c->field('Email', fn() => $c->input($app->email), error: $app->emailError);
};
```

## Without a look

`$c->textInputBase()` and `$c->textAreaBase()` are text inputs without
padding, background, border or corners, for inputs of your own design: see
[custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a text field, or a text area, named by its
`label()` or its field, whose value is its text, with the caret and the
selection; it edits it as typing does, unless it is read-only. A password
field hides its value.
