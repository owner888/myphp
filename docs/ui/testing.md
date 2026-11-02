# Testing

`MyPHP\Tests\Headless` (`tests/Headless.php`) runs a view without a window,
as fast as a unit test: it builds and lays out frames in memory, with text
measured as cells of 7 by 16 points, paints them into a buffer, finds nodes
by their text, and clicks, types, scrolls and presses keys. MyPHP's own
tests run on it (`php tests/run.php`), with `check()` and `same()` for
assertions, and so do the examples of these pages.

```php
$app = new class { public int $n = 0; };
$view = function (Context $c) use ($app) {
    $c->text((string)$app->n);
    if ($c->clicked($c->primaryButton('Increment'))) {
        $app->n++;
    }
};
$h = new Headless($view, 320, 240);
$h->frame();
$h->clickText('Increment');
$h->frame();
same(1, $app->n, 'one click counts one');
check($h->byText('1') !== null, 'the count shows: ' . json_encode($h->texts()));
```

Nodes are found by their text, or by their `label()` for those without, as
an icon button: `$h->byText('Close')`, `$h->byLabel('Volume')`;
`$h->focused()` is the node with the focus.

Two nodes given the same key under one parent share one state, so that a
click or the focus meant for one goes to the other: with
`$h->rt->strict = true` the runtime throws where the second key was given,
as apps only log it.

## What it does

- **Pointer.** `click($x, $y, $mods, $clicks)`, `clickOn($node)`,
  `clickText()`, `clickLabel()`, `press()`, `drag()`, `release()`,
  `hover()`, `wheel($x, $y, $dx, $dy)` and `rightClick()`; `rect($node)`
  and `middle($node)` give where a node is in the window.
- **Keyboard.** `key($code, $selector, $mods, $chars)` presses a key as
  the view sends it, `shortcut($char, $mods)` a shortcut, and `type()`
  types text into the focused node; `tab()`, `enter()`, `escape()`,
  `up()`, `down()`, `left()`, `right()`, `home()`, `end()`, `pageDown()`
  and `space()` press those keys. `compose()` shows the composition of an
  input method.
- **Menus.** `rightClick()` opens a context menu, which `menuLabels()`
  lists and `chooseMenuItem('Move to', 'Archive')` chooses from.
- **The window.** `$h->rt->rightToLeft = true` lays it out
  [right to left](right-to-left.md); `$h->w` and `$h->h` are its size.
- **What it shows.** `texts()`, `byText()`, `byLabel()`, `find($id)`,
  `focused()`, `tabStops()`, and `$h->told`, what the view asked screen
  readers to read out.
- **Frames.** `frame()` builds a frame, for what changed outside the view,
  and `paint()` paints it into a display list. `Headless::load($example)`
  runs an example's file up to its window and returns its view and state.
- **Time.** The runtime's `clock` (a closure returning nanoseconds)
  replaces the system's, so that a test of an animation reads the same on
  a busy machine as on a fast one: set it, and move it on between frames.

## Testing the built app

A headless view runs inside the test suite. To test the app as built, with
its real windows, from another process, as an end-to-end test runner or an
agent does, use the [automation plugin](../automation.md): it reads the
nodes of every window by their labels or by their keys, acts on them, sends
input, and waits for frames.
