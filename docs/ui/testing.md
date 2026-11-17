# Testing

`MyPHP\Tests\Headless` (`tests/Headless.php`) runs a declarative view without a window, as fast as a unit test: it builds and lays out frames in memory, with text measured as cells of 7 by 16 points, paints them into a buffer, finds nodes by their text or accessibility label, and clicks, types, scrolls and presses keys. MyPHP's own tests run on it (`php tests/run.php`), with `check()` and `same()` for assertions, and so do the examples of these pages.

```php
$app = new class { public int $n = 0; };
$view = fn() => VStack()(
    Text(verbatim: (string)$app->n),
    Button('Increment', action: fn() => $app->n++),
);
$h = new Headless($view, 320, 240);
$h->frame();
$h->clickText('Increment');
$h->frame();
same(1, $app->n, 'one click counts one');
check($h->byText('1') !== null, 'the count shows: ' . json_encode($h->texts()));
```

`Headless` takes what a view file returns (a zero-argument closure returning a `View`), or `Content::file(__DIR__ . '/view.php', $app)->view` for the app's own file. Nodes are found by their text, or by their `accessibilityLabel()` for those without, as an icon button: `$h->byText('Close')`, `$h->byLabel('Volume')`; `$h->focused()` is the node with the focus. For a phone's look, set `$h->rt->touch = true` and `$h->theme = Theme::phone(false)` before the first frame.

## What it does

- **Pointer.** `click($x, $y, $mods, $clicks)`, `clickOn($node)`, `clickText()`, `clickLabel()`, `press()`, `drag()`, `release()`, `hover()`, `wheel($x, $y, $dx, $dy)` and `rightClick()`; `rect($node)` and `middle($node)` return where a node is in the window.
- **Keyboard.** `shortcut($char, $mods)` presses a key with modifiers (such as `$h->shortcut('a', Key::COMMAND)` for Select All); `type()` types text into the focused field; and `tab()`, `enter()`, `escape()`, `backspace()`, `up()`, `down()`, `left()`, `right()`, `home()`, `end()`, `pageDown()`, and `space()` press those keys.

  ```php
  $app = new class { public string $name = 'Old name'; };
  $h = new Headless(fn() => Form()(TextField('Name', text: $app->name)));
  $h->frame();
  $h->clickLabel('Name');               // the field, named by its title, takes the focus
  $h->shortcut('a', Key::COMMAND);      // Select All
  $h->type('New name');                 // typed over the selection
  same('New name', $app->name, 'the field holds what was typed');
  ```
- **Menus.** `rightClick()` opens a context menu, which `menuLabels()` lists and `chooseMenuItem('Move to', 'Archive')` chooses from.
- **What it shows.** `texts()`, `byText()`, `byLabel()`, `find($id)`, `focused()`, `tabStops()`.
- **Frames.** `frame()` builds a frame, and `paint()` paints it into a display list. `Headless::load($example)` runs an example's file up to its window and returns its view and state.

## Text sizes

The headless harness measures every character as a 7×16 cell regardless of font or size, so assert the node's `fontSize` property when checking typography sizes:

```php
$h = new Headless(fn() => Text('Title')->font(Font::system(size: 18)));
$h->frame();
$t = $h->byText('Title');
same(18.0, $t->fontSize, 'drawn at 18 points');
same(16.0, $h->rect($t)[3], 'and as high as any text in the tester');
```

## Waiting for async timers and fibers

Turn the run loop until an async timer (`App::after()`) or fiber (`App::go()`) completes with `App::wait()`, then build a frame to inspect the updated view:

```php
$app = new class { public string $status = 'loading'; };
$h = new Headless(fn() => Text(verbatim: $app->status));
$h->frame();
App::after(0.05, function () use ($app) { $app->status = 'loaded'; });
App::wait(fn() => $app->status !== 'loading');
$h->frame();
check($h->byText('loaded') !== null, 'shown once the timer ran');
```

## Testing the built app

To test a running packaged app with real OS windows from another process, use the [automation plugin](../automation.md).
