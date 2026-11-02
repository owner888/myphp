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

`byLabel()` and `clickLabel()` take the first node in the order of the
tree whose `label()`, or the name its [field](form.md) gives it, or its
text, is the one asked, as mygo's `Tester.Find`: a text or a table's
header that shows "Amount" above a form comes before the field, and a
field's label comes before the control it names. A click on a field's
label focuses its control, so `clickLabel('Amount')` on a form gives its
field the keyboard; to reach the control itself, look under the field
(`$h->byLabel('Amount', $field)`, the node `field()` returned), or give it a
`label()` of its own that nothing else shows.

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
  the view sends it: the key event, then what the text system makes of it,
  the AppKit selector a focused field takes (`'moveLeft:'`,
  `'deleteBackward:'`, `'insertNewline:'`), `''` for a key it makes nothing
  of. `shortcut($char, $mods)` presses the key that types `$char` with
  `$mods`, as the window sends it, so that `$c->shortcut('n', Key::COMMAND)`
  and `$c->shortcut(Key::N, Key::COMMAND)` both take `$h->shortcut('n',
  Key::COMMAND)`; Command with A, C, X, V, Z or Y goes as the Edit menu's
  command (Select All, Copy, …), which a focused field takes. `type()` types
  text into the focused node. Rather than `key()` with its selector,
  `tab()`, `enter()`, `escape()`, `up()`, `down()`, `left()`, `right()`,
  `home()`, `end()`, `pageDown()` and `space()` press those keys (`up()`
  and `down()` take `Key::SHIFT` to select). `compose()` shows the
  composition of an input method. To replace what a field holds, select
  it all and type over it:

  ```php
  $name = new TextState('Old name');
  $h = new Headless(function (Context $c) use ($name) {
      $c->form(fn() => $c->field('Name', fn() => $c->input($name)));
  });
  $h->frame();
  $h->clickLabel('Name');               // the label focuses its field
  $h->shortcut('a', Key::COMMAND);      // Select All
  $h->type('New name');                 // typed over the selection
  same('New name', $name->text, 'the field holds what was typed');
  ```
  Or set it from the test, `$name->text = 'New name'`, when typing is not
  what is tested.
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

## Text sizes

The tester measures every text as cells of 7 by 16 points, whatever its
font, size or weight, so a size cannot be read from a node's rect: a text
of 18 points is as high as one of 13. Assert the size the node is laid
out and drawn at, its `fontSize` property, which `text($s, $size)`,
`fontSize()` on it and a size its container sets all give. `textStyle()`
is the style the node resolves from its own `fontSize()`, `bold()`, …
over what it inherits; `text()`'s `$size` argument is not among them, so
`textStyle()->size` of `text('Title', 18)` is the inherited size:

```php
$t = null;
$h = new Headless(function (Context $c) use (&$t) { $t = $c->text('Title', 18); });
$h->frame();
same(18.0, $t->fontSize, 'drawn at 18 points');
same(16.0, $h->rect($t)[3], 'and as high as any text in the tester');
```

## Waiting for work that is not the view's

What the app does on its own time, as a timer of `App::after()`, a
function of `App::later()` or a fiber of `App::go()` waiting for an
[HTTP request](../plugins/fetch.md#from-php-or-native-ui), happens outside
the frames the tester builds. Turn the run loop until it is done with
`App::wait()`, which a test may call without the app running, then build a
frame to see what it changed:

```php
$app = new class { public string $status = 'loading'; };
$h = new Headless(fn(Context $c) => $c->text($app->status));
$h->frame();
App::after(0.05, function () use ($app) { $app->status = 'loaded'; }); // as the app's own code would
App::wait(fn() => $app->status !== 'loading');
$h->frame();
check($h->byText('loaded') !== null, 'shown once the timer ran');
```

Without the app running, an `Http` request blocks until its response came,
so a fiber of `App::go()` making one runs to its end before `App::go()`
returns; give the code under test the server's URL, as a local
`php -S` (`rangeServer()` in `tests/Headless.php` serves a folder).

## Testing the built app

A headless view runs inside the test suite. To test the app as built, with
its real windows, from another process, as an end-to-end test runner or an
agent does, use the [automation plugin](../automation.md): it reads the
nodes of every window by their labels or by their keys, acts on them, sends
input, and waits for frames.
