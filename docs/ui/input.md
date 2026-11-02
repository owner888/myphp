# Input

Nodes report what the user did to them since the last frame, as you build
them: ask, and handle it where the node is built.

A `Node` is valid for one build pass. Store a `Handle` for focus or
shortcuts across builds. Each window has its own binding for the handle;
use that window's context when querying it.

```php
$app = new class { public array $opened = []; public function open(string $item): void { $this->opened[] = $item; } };
$item = 'Report';
$view = function (Context $c) use ($app, $item) {
    $t = $c->theme;
    $card = $c->column(12)->radius(8)->focusable();
      $c->text($item);
    $c->end();
    if ($c->hovered($card)) {
        $card->background($t->surfaceHover);
    }
    if ($c->doubleClicked($card)) {
        $app->open($item);
    }
};
```

## The pointer

`$c->hovered()`, `pressed()`, `clicked()`, `doubleClicked()`,
`rightClicked()`, `middleClicked()`, `dragged()` (how far the pointer
moved since the last frame while pressing the node, `[dx, dy]`, or null)
and `pointerPosition()`. `$c->clickModifiers()` returns the modifier keys
held for the last click, as `Key::SHIFT` for a Shift-click.
`$c->modifiers()` returns the modifier keys held now, as one goes down or
up on its own too, and the view draws again as they change: a list showing
each row's Cmd+1–9 while Cmd is held reads it. `passThrough()` lets the
pointer through the node to what is below; `ignoreInput()` lets the
pointer through it and those inside it, and keeps the keyboard focus away
from them.

Nodes that take the pointer give it to the innermost under it: a button in
a clickable row takes its own clicks.

`$c->pressed()` stays true while the pointer is held inside the node.
Observing the start of a press rebuilds the view before painting, so a row
chosen by `if ($c->pressed($row) || $c->clicked($row))` shows its new
selection and focus colors in that frame. Guard the choice against
selecting the same item again. `$c->clicked()` reports activation on
release inside the node, or through the keyboard or assistive technology;
buttons use that activation.

While the pointer presses a node, the nodes it was over as the press
began, as the row around a button, stay hovered as long as it is over
them, as in CSS, and the others hover no more: dragging over other nodes
does not light them up. A button that shows while its row is hovered stays
as it is pressed, to take its click:

```php
$items = [['id' => 1, 'title' => 'Milk']];
$view = function (Context $c) use (&$items) {
    $removed = null;
    foreach ($items as $item) {
        $row = $c->key($item['id'])->row(8);
        $hovered = $c->hovered($row);
          $c->text($item['title'])->grow(1);
          if ($hovered && $c->clicked($c->button('Remove'))) {
              $removed = $item['id'];
          }
        $c->end();
    }
    if ($removed !== null) {
        $items = array_values(array_filter($items, fn($i) => $i['id'] !== $removed));
    }
};
```

Built only while the row is hovered, the button is not there for assistive
technology. `ignoreInput()` keeps it built, transparent and out of the
pointer's and the keyboard's way at rest, still read and pressed by
assistive technology (see
[Accessibility](accessibility.md#out-of-the-pointers-way)):

```php
$hovered = false;
$del = $c->button('Remove')->ignoreInput(!$hovered);
if (!$hovered) {
    $del->opacity(0);
}
```

## The keyboard focus

`focusable()` nodes take the focus when clicked, and Tab and Shift+Tab
move it between them, with a focus ring when it moves by keyboard.
`autoFocus()` gives a node the focus when it appears, such as the first
field of a dialog; `$c->focused()` and `$c->focusWithin()` report it.
Enter and Space press a focused button or link, and Space toggles a
focused check box, switch, toggle or radio button. `keepFocus()` keeps a
click on a node from taking the focus from where it is, as a button that
acts on the focused field.

## Persistent focus

Bind an app-owned handle to a control on each build:

```php
$app = new class {
    public TextState $query;
    public Handle $search;
    public function __construct() { $this->query = new TextState(); $this->search = new Handle(); }
};
$view = function (Context $c) use ($app) {
    $c->key('search')->input($app->query)->bind($app->search);
};
```

`$app->search->focus()` requests focus and waits while the control is
hidden. `cancelFocus()` cancels the request. `focused($c)` and
`focusWithin($c)` read the control's identity before or after it is built.
`resolve($c)` returns only this pass's node. `bounds($c)` reads its box
from the committed frame, including between builds in an input callback.
Closing a window cancels its focus request.

A handle can bind in several windows. Queries take that window's context;
use `focus($c)` or `cancelFocus($c)` to select a window explicitly. The
no-argument focus form requires at most one open binding.

## Focus bound to data

```php
$app = new class {
    public const NONE = 0, FILES = 1, DIFF = 2;
    public int $pane = self::NONE;
};
$view = function (Context $c) use ($app) {
    $c->key('files')->column()->focusBind($app->pane, $app::FILES);
      $c->text('Files');
    $c->end();
    $c->key('diff')->column()->focusBind($app->pane, $app::DIFF);
      $c->text('Diff');
    $c->end();

    // Actual focus in this window:
    $focused = $c->focusedValue($app->pane);
};
```

`focusBind()` takes a field (by reference) and a matching value: in an
action, `$app->pane = $app::DIFF` moves the focus to the diff. Each value
names one control in the window; null is no member. The field holds
desired focus. A request waits if its control is hidden, so use
`$c->focusedValue()` to read actual focus while it waits. User focus
changes update the field when no request is pending. Use separate fields
for separate windows.

## Focus groups

The controls of a toolbar, a radio group, a segmented control or a tab
list are one stop of Tab, as they are natively: Tab moves the focus into
the group, to the control that had it last (or the radio button or tab
chosen), and on out of it, while the arrows move it among them, Home and
End to the first and last. `focusGroup()` does it for a container of your
own, with the arrows `Node::HORIZONTAL`, `Node::VERTICAL` or both; a
slider or a text input inside keeps the arrows it takes.

```php
$tools = ['Select', 'Pen', 'Eraser'];
$app = new class { public string $tool = 'Select'; };
$view = function (Context $c) use ($app, $tools) {
    $c->row(gap: 4)->focusGroup(Node::HORIZONTAL);
      foreach ($tools as $tool) {
          if ($c->clicked($c->button($tool))) {
              $app->tool = $tool;
          }
      }
    $c->end();
};
```

## Shortcuts

`$c->shortcut(Key::S, Key::COMMAND)` reports a key pressed with exactly
those modifiers anywhere in the window (the key first, then the
modifiers), and `onShortcut()` on a node only while the node or one inside
it has the focus, which comes first. A focused button or link keeps Enter
and Space, and a toggle Space, so `$c->shortcut(Key::RETURN)` presses a
dialog's default button wherever else the focus is.

```php
$app = new class { public int $saves = 0; public function save(): void { $this->saves++; } };
$view = function (Context $c) use ($app) {
    if ($c->shortcut(Key::S, Key::COMMAND)) {
        $app->save();
    }
};
```

Keys are `Key` constants, by the key's place on a US keyboard (`Key::S`,
`Key::RETURN`, `Key::LEFT`, `Key::F2`), or a character as a string;
modifiers are `Key::COMMAND`, `Key::SHIFT`, `Key::OPTION` and
`Key::CONTROL`, or'd together. `Key::BACK` and `Key::FORWARD` are the back
and forward buttons of a mouse, which a [router](navigation.md) takes.
Shortcuts of [menus](../menus.md) still work, and the Edit menu's roles
(cut, copy, paste, select all, undo, redo) act on the focused text input.

Shortcuts work on layouts that type no Latin letters too: with Cmd, Ctrl,
Alt or Super held, a key typing a letter of no US key, as ы on a Russian
layout, stands for the key at its place on a US keyboard, so Cmd+ы is
`Key::S`. Typed without those modifiers, it stays text.

A focused text input takes the editing keys of the platform first: on
macOS, Option and Command with the arrows and Backspace, and Control with
A, E, B, F, N, P, D, H and K, as in other Mac apps.

A shortcut action can be declared before binding its control:

```php
$app = new class {
    public ListState $list;
    public Handle $filesView;
    public array $files = ['a.txt', 'b.txt'];
    public int $opened = 0;
    public function __construct() { $this->list = new ListState(); $this->filesView = new Handle(); }
    public function openSelected(): void { $this->opened++; }
};
$view = function (Context $c) use ($app) {
    $app->filesView->onShortcut($c, Key::K, Key::COMMAND, fn() => $app->openSelected());
    $c->key('files')->list($app->list, count($app->files), function (int $i) use ($c, $app) {
        $c->text($app->files[$i]);
    })->bind($app->filesView);
};
```

`Handle::onShortcut()` runs after construction only when the window built
an enabled control for that handle. Hidden controls take no command.
`$c->onShortcut()` declares an action in the current parent scope; at the
root it handles keys left by the focused control and active overlays. Use
`onShortcut()` on a node for an action inside its focus subtree.

## Input methods

Text inputs take text composed with input methods, which see the text
around the caret: macOS's press and hold replaces the letter it accents,
Japanese input methods convert typed text again, and others predict from
what comes before.

## Dropped files

`$c->droppedFiles()` returns the paths of the files dropped on the node
from Finder or a file manager, and `$c->fileDragOver()` reports files
dragged over it, to show it would take them. The window takes files only
over such nodes, or anywhere when it has `fileDrop` listeners
(`Window::on('fileDrop', ...)`), which get the files no node takes, with
where they were dropped:

```php
$app = new class { public array $files = []; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $zone = $c->column()->size(260, 80)->border(1, $t->border);
    $c->end();
    if (($files = $c->droppedFiles($zone)) !== null) {
        $app->files = $files;
    }
    if ($c->fileDragOver($zone)) {
        $zone->border(2, $t->accent);
    }
};
```

Values dragged within or between windows, and serialized data exchanged
with other applications, are [drag and drop](drag-and-drop.md).

## Scrolling and pinching

Scroll containers scroll with the wheel and the touchpad on their own. A
node that does something else with them, as a chart zooming and panning
its candles, gets them with `handleInput()`: `InputEvent::SCROLL` scrolls
by `dx` and `dy` points, positive down and to the right of the content,
with `x` and `y` the pointer relative to the node; `precise` tells a
touchpad, which scrolls by pixels, from a mouse wheel's notches.
`InputEvent::ZOOM` is a touchpad's pinch, at `x` and `y`, zooming by
`scale`: 2 shows the content twice as large as it was. What the node takes
no scroll container around it scrolls with; what it leaves, by returning
false, they do.

```php
$chart = new class {
    public float $zoom = 1, $offset = 0;
    public function zoom(float $x, float $by): void { $this->zoom *= $by; }
    public function pan(float $dx): void { $this->offset += $dx; }
};
$view = function (Context $c) use ($chart) {
    $c->column()->fill()->handleInput(function (InputEvent $ev) use ($chart): bool {
        switch (true) {
            case $ev->kind === InputEvent::ZOOM:
                $chart->zoom($ev->x, $ev->scale);
                break;
            case $ev->kind === InputEvent::SCROLL && !$ev->precise:
                // A notch zooms by a quarter.
                if ($ev->dy < 0) {
                    $chart->zoom($ev->x, 1.25);
                } elseif ($ev->dy > 0) {
                    $chart->zoom($ev->x, 0.8);
                }
                break;
            case $ev->kind === InputEvent::SCROLL:
                $chart->pan($ev->dx);
                break;
            default:
                return false;
        }
        return true;
    });
    $c->end();
};
```

A headless test scrolls with `Headless::wheel()` (see
[testing](testing.md)).

## Every key, as it comes

Widgets that take every key themselves, as the
[terminal](../plugins/terminal.md) does, get their input as it comes with
`handleInput()`, before the next frame: keys pressed and released, text
typed and composed while they have the focus, the edit commands of the
menus, and the pointer pressed on them, moving over them or scrolling over
them. The function reports whether it took the event; one it leaves goes
on to shortcuts, Tab, context menus and scroll containers as usual, and
keys that the window or a node around the focus handles with a shortcut go
there first. `textCaret()` turns on the system's input methods for such a
node while it has the focus, composing at the caret it gives;
`inputMethod(false)` turns them off again while keys still type text, as
for Vim's normal mode. `MyPHP\Clipboard` reads and writes the clipboard,
in several formats at once (`Clipboard::write()` of a `Transfer\Data`), and
`Clipboard::formats()` lists the clipboard's without reading them, as a
terminal decides what a paste reads. `$c->openURL()` opens links;
`$c->openURLThen()` opens a link too, and its function gets what came of
it a moment later, as the system opens it, with an error when no app could,
and the view builds a frame anew. A text field of the app's own gets
`InputEvent::SELECT` when a screen reader moves its selection: the runes
`start` to `end` of the text its `access()` describes.

```php
$app = new class {
    public string $text = '';
    public function key(int $mods, int $key): bool { return false; }
    public function insert(string $s): void { $this->text .= $s; }
};
$url = 'https://example.com';
$view = function (Context $c) use ($app, $url) {
    $c->column()->fill()->focusable()->handleInput(function (InputEvent $ev) use ($app): bool {
        switch ($ev->kind) {
            case InputEvent::KEY_DOWN:
                return $app->key($ev->mods, $ev->key);
            case InputEvent::TEXT:
                $app->insert($ev->text);
                return true;
        }
        return false;
    })->textCaret(0, 0, 1, 16);
    $c->end();

    if ($c->clicked($c->link('Open', ''))) {
        $c->openURLThen($url, function (?\Throwable $err) use ($c, $url) {
            if ($err !== null) {
                $c->toast('No app opens ' . $url);
            }
        });
    }
};
```

## See also

- [Context menus](context-menu.md) and [menu buttons](menu-button.md).
- [Tooltips](tooltip.md).
- Custom title bars: [windows with native UI](windows.md).
