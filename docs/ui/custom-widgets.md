# Custom widgets

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)).

Every widget is built on a base, the same widget without a look: the base
handles the pointer, the keyboard and the focus, and tells assistive
technology what it is, and leaves every color, size and shape to the
nodes it returns, which you style as any other. Build a design of your own
on them, as headless component libraries do on the web.

| Base | What it does |
|---|---|
| `buttonBase()` | a row that takes the focus, and reports `clicked()` for the pointer, Enter and Space |
| `checkboxBase($on)`, `switchBase($on)`, `toggleBase($on)` | a row that toggles a `bool` |
| `radioBase($selected, $value)` | a row that selects its value into a variable |
| `sliderBase($value, $min, $max, $step, $vertical)` | sets a `float` from where the pointer is across its content box, inside its padding, or up it (`vertical`), and with the arrows, Page Up and Down, Home and End, by its step |
| `tabsBase($selected, $n)` | the tab `list`, whose `tab()`s choose an `int`, with the arrows moving the choice and the focus |
| `segmentedBase($selected, $n)` | a `track` of `segment()`s, a radio group choosing an `int` |
| `collapsibleBase($open)` | a `trigger` that shows and hides what `panel()` builds, growing and shrinking it with `progress()`, which goes from 0 closed to 1 open |
| `selectBase($selected)` | a `trigger` opening a `popup()` of `item()`s choosing a value, which the arrows highlight (`highlighted()`) and Enter chooses |
| `comboboxBase($text)` | an `input` whose text filters the `item()`s of a `popup()` below it, which the arrows highlight and Enter or a click chooses (`chosen()`) |
| `popoverBase()`, `dialogBase()` | a panel beside an anchor, or over a backdrop covering the window, that a press outside or Escape closes |
| `tooltipBase()` | a tip beside an anchor while the pointer rests on it or the keyboard focus is on it, which a press or Escape hides ([Tooltip](tooltip.md#without-a-look)) |
| `toastViewportBase()`, `toastBase()` | the window's toasts in a viewport of your own, each a status with an `actionButton()` and a `closeButton()`, their time stopping while the pointer or the focus is on them ([Toast](toast.md#without-a-look)) |
| `textInputBase()`, `textAreaBase()` | text inputs without padding, background, border or corners, read-only or not |

Charts have bases of their own: [the chart classes](chart.md) have scales,
axes, marks and a selection without a look.

A base that returns a node opens a container, built in until `end()`; one
that returns parts (`TabsParts`, `SegmentedParts`, `CollapsibleParts`,
`SelectParts`, `ComboboxParts`, `ToastParts`) opens the container its
first part is, and each part that is a container is closed with `end()`
too. `textInputBase()` and `textAreaBase()` are the exception: a text input
is a leaf, which opens nothing, so no `end()` follows it. Give stateful
bases a key with `$c->key()` before construction.

A text area without a look, as a notes app's editor that fills its pane:
the base has no padding, so the padding goes on it (`padding()`, or
`paddingX()` and `paddingY()`, which take one value each), and a
placeholder of your own shows while it is empty:

```php
$app = new class { public TextState $note; public function __construct() { $this->note = new TextState(''); } };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $c->column(0, 0, 1, $t->background)->fill();
      $area = $c->key('editor')->textAreaBase($app->note)->label('Note')
          ->paddingX($t->space(6))->paddingY($t->space(4))->fontSize(15)->grow(1);
      if ($app->note->text === '') {
          $c->text('Start writing…', 15, $t->textMuted)->absolute()->top($t->space(4))->left($t->space(6));
      }
    $c->end(); // the column's: textAreaBase() opened nothing
    if ($c->changed($area)) {
        // save $app->note->text
    }
};
```

`textarea()` and `input()` are the same nodes with the theme's padding,
face, border and corners, which calls after them replace:
`$c->textarea($s, '', 0, 0)->borderWidth(0)->padding(16, 20)` keeps the face
and drops the border.

A segmented control on `segmentedBase()`, and a select on `selectBase()`:

```php
$chevron = SVG::fromFile('icons/chevron.svg');
$sizes = ['S', 'M', 'L'];
$app = new class { public int $view = 0; public string $size = 'M'; };
$view = function (Context $c) use ($app, $sizes, $chevron) {
    $t = $c->theme;
    $period = $c->key('period')->segmentedBase($app->view, 3);
    $period->track->padding(3)->radius(999)->background($t->surface);
      foreach (['Day', 'Week', 'Month'] as $i => $name) {
          $seg = $period->segment($i)->padding(5, 14)->radius(999);
          if ($i === $app->view) {
              $seg->background($t->background)->shadow(0, 1, 2, 0, 0x000000, 0.15);
          }
            $c->text($name);
          $c->end();
      }
    $c->end();

    $sel = $c->key('size')->selectBase($app->size);
    $sel->trigger->gap(6)->padding(6, 10)->radius(8)->border(1, $t->border);
      $c->text($app->size);
      $c->icon($chevron);
    $c->end();
    $sel->popup(function (Node $panel) use ($c, $t, $sel, $sizes) {
        $panel->margin(4, 0, 0, 0)->padding(4)->radius(10)->background($t->background)->border(1, $t->border);
        foreach ($sizes as $size) {
            $item = $sel->item($size)->padding(6, 10)->radius(6);
            if ($item->highlighted()) {
                $item->background($t->accent)->textColor($t->accentText);
            }
              $c->text($size);
            $c->end();
        }
    });
};
```

Bases ring the node with the keyboard focus, as every node taking the focus
is; `focusRing(false)` turns the ring off for a widget that draws its own,
with `Painter::focusRing()`.

## Widgets of your own

For text controls with their own buffer or selection model, use
[`handleTextInput()` and retained text geometry](text-input-client.md).

Build your own widgets from nodes, as functions that take the context. A
node keeps state of its own from frame to frame in
`$c->runtime->local($node->id, $make)`, an object made the first time and
kept while the node is built (the runtime is null when a context builds no
window's tree, as `memo()`'s does not):

```php
/** Hides text until clicked. */
function spoiler(Context $c, string $text): void
{
    $t = $c->theme;
    $box = $c->column()->padding(2, 6)->radius(4)->focusable();
    $shown = $c->runtime?->local($box->id, fn() => new \stdClass()) ?? new \stdClass();
    $shown->on ??= false;
    if ($c->clicked($box)) {
        $shown->on = !$shown->on;
    }
    if ($shown->on) {
        $box->background($t->surface);
    } else {
        $box->background($t->text); // the color of the text, hiding it
    }
      $c->text($text);
    $c->end();
}

$view = fn(Context $c) => spoiler($c, 'The butler did it.');
```

A widget whose input changes what it edits reports it with `setChanged()`
on its node, as it builds: `$c->changed()` then returns true for it, as for
the widgets' own. The [rich text editor](rich-text-editor.md) does so.

Give a widget a [role](accessibility.md#roles), a name and its
[states](accessibility.md#states) for assistive technology, take the keys
it needs with `onShortcut()` on its node, or every key as it comes with
[`handleInput()`](input.md#every-key-as-it-comes), and paint what nodes do
not with [`draw()`](drawing.md). Overlays of your own, as drawers, hover
cards and menus, are placed with `attachTo()` and close with
`$c->pressedOutside()` and `$c->overlayShortcut()`; `block()` keeps the
focus in one: see [overlays](overlays.md#your-own-overlays).
