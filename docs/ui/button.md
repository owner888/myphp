# Button

`$c->button()` creates a push button showing a label, and
`$c->primaryButton()` one in the accent color, for the main action of a
window or a dialog. `$c->clicked()` reports a click, or Enter or Space
while the button has the keyboard focus:

```php
$app = new class {
    public bool $editing = true;
    public int $saves = 0;
    public function save(): void { $this->saves++; }
};
$view = function (Context $c) use ($app) {
    $c->row(gap: 8)->justify(Node::END);
      if ($c->clicked($c->button('Cancel'))) {
          $app->editing = false;
      }
      if ($c->clicked($c->primaryButton('Save'))) {
          $app->save();
      }
    $c->end();
};
```

The button is padded `$t->space(1.5)` by `$t->space(3.5)`, a bordered
surface face, or the accent without a border when primary. The border takes
room inside the button, as mygo's does, so a primary button is 2 points
shorter than a plain one or a text input (29 against 31 at 13 points): in a
row, which centers its children across, it is a point in from each edge.
To give a row of mixed buttons and inputs one height, stretch them across it:

```php
$name = new TextState('');
$view = function (Context $c) use ($name) {
    $c->row(gap: 8)->alignItems(Node::STRETCH); // every child as high as the highest
      $c->input($name, 'Name', 0);
      $c->primaryButton('Add');
    $c->end();
};
```

## Content

A button of `$c->button()` shows its label alone. A button showing an icon,
or other content, is built on `$c->buttonBase()`: a row that centers what
is built in it until `end()`, takes the focus and reports `clicked()`,
given its look. A button showing only an icon needs a `label()`, which
names it for assistive technology, and a `tooltip()`:

```php
$trash = SVG::fromFile('icons/trash.svg');
$app = new class { public int $deleted = 0; public function delete(): void { $this->deleted++; } };
$view = function (Context $c) use ($app, $trash) {
    $t = $c->theme;
    $b = $c->buttonBase()->padding($t->space(1.5))->radius($t->radius)->label('Delete')->tooltip('Delete');
      $c->icon($trash);
    $c->end();
    if ($c->clicked($b)) {
        $app->delete();
    }
};
```

See [Icon](icon.md#in-buttons) for an icon beside a label.

## States

`disabled()` grays a button out: it takes neither clicks nor the focus. In
a [toolbar](toolbar.md), buttons take no face of their own until hovered.
A default button that Enter presses wherever the focus is takes the key
with a [shortcut](input.md#shortcuts):

```php
$app = new class { public bool $signedIn = false; public function signIn(): void { $this->signedIn = true; } };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->primaryButton('Sign in')) || $c->shortcut(Key::RETURN)) {
        $app->signIn();
    }
};
```

A focused button keeps Enter and Space for itself, so the shortcut fires
only when the focus is elsewhere.

## Style

The button returns its node: calls after it change its look, as
`$c->button('Save')->padding(10, 20)->radius(999)`. `$c->buttonBase()` is
a button without a look, for a design of your own: see
[custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a button named by its text or its `label()`,
which it presses as a click does.

## Click actions

Use `$c->clicked()` for inline control flow or `onClick()` to run an
action after construction:

```php
$app = new class { public bool $saving = false; public int $saves = 0; public function save(): void { $this->saves++; } };
$view = function (Context $c) use ($app) {
    $c->button('Save')->disabled($app->saving)->onClick(fn() => $app->save());
};
```

The callback sees the final configuration and runs after bound-value
input. It can change the model without interrupting a loop that is
building controls. Handled input is consumed before the view rebuilds. See
[Events and actions](views.md#events-and-actions) for choosing between
queries and callbacks.
