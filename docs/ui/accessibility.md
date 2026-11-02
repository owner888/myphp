# Accessibility

Assistive technology, such as VoiceOver on macOS and Orca on Linux, reads
a window's native UI as it reads other apps: the widgets with their roles,
names, values and states, the texts, and the nodes that take the focus, as
the content changes. It acts on them as the keyboard and the pointer would:
it presses buttons, checks boxes, moves sliders, edits text inputs and
moves the focus.

## Names

Widgets describe themselves, and the text inside a node names it, as a
button's does. Nodes without text need a `label()`, which also finds them
in [tests](testing.md):

```php
$app = new class { public float $volume = 50; public TextState $query; public function __construct() { $this->query = new TextState(); } };
$view = function (Context $c) use ($app) {
    $c->slider($app->volume, 0, 100)->label('Volume');
    $c->input($app->query, 'Search')->label('Search');
    $c->column()->size(24, 24)->draw(fn(Painter $p, array $r) => $p->fill($r, 0x2563eb, 12))->label('Unread messages');
    $c->end();
};
```

A [field](form.md) names its control with its label.

## Roles

Other nodes get a role from what they do: one that is clickable and takes
the focus is a button, a scroll container a scroll area, and one with a
`label()`, or that takes the focus, a group. `role()` sets it for a node
drawn as a widget it is not built from, and `Role::NONE` leaves a node out
but not its children:

```php
$toggle = $c->column()->size(36, 20)->focusable()->role(Role::SWITCH)->label('Wi-Fi');
$c->end();
```

Besides the roles of MyPHP's widgets, `Role::HEADING` is the title of a
section, ranked with `level()`, and `Role::MENU`, `Role::MENU_BAR`,
`Role::MENU_ITEM`, `Role::MENU_ITEM_CHECK_BOX` and `Role::MENU_ITEM_RADIO`
make menus drawn in the window, as a drop-down menu or a menu bar of your
own (a node's menu drawn in the window,
[`popoverMenu()`](context-menu.md#drawn-in-the-window) or
[`popoverMenuButton()`](menu-button.md#drawn-in-the-window), has them):

```php
$c->text('Appearance')->bold()->role(Role::HEADING)->level(2);
```

## States

The bases tell assistive technology what their widgets show. A widget of
your own tells it with these, as ARIA's states do on the web:

| Method | What it tells |
|---|---|
| `checked($on)`, `mixed()` | whether a check box, switch, radio button, toggle or menu item is on, or partly on |
| `expanded($open)` | whether what the node opens shows, as a popup or a section |
| `value($s)` | its value, as the choice a button opening a popup shows |
| `range($lo, $hi, $value, $step)` | the range and value of a slider, progress bar, meter or stepper, and how far the keys move it |
| `level($n)` | the rank of a heading, or how deep an item of a tree is |
| `activeDescendant($node)` | the option that has the focus while the node keeps it, as the one the arrows are on in a menu or a list |

`access(fn(AccessNode $a) => ...)` fills in what else the node tells, after
the rest.

```php
$app = new class { public bool $all = false; public bool $some = true; public bool $picking = false; public DateTimeImmutable $due; public function __construct() { $this->due = new DateTimeImmutable('2026-01-02'); } };
$view = function (Context $c) use ($app) {
    $all = $c->checkboxBase($app->all)->label('Select all');
    if ($app->some) {
        $all->mixed();
    }
    $c->end();

    $date = $c->buttonBase()->role(Role::POP_UP_BUTTON)->label('Due')->value($app->due->format('M j'))->expanded($app->picking);
      $c->text($app->due->format('M j'));
    $c->end();
};
```

## Descriptions and errors

A node's `description` tells more than the name, as help text read after
it, as a [tooltip](tooltip.md) does: its tooltip unless set. A
[field](form.md) gives its control the texts below it, its description and
its error, which marks the value as invalid, for a message read with it.

## Formats of text

The [rich text editor](rich-text-editor.md) tells screen readers its text,
its selection and its placeholder; screen readers move the selection of
text fields too.

## Lists and tables

A [list](list.md)'s rows are list items, and a [table](table.md)'s rows,
named by the text inside them, and each says which of all the rows it is,
"5 of 10,000", although the list builds only those in view. A list
choosing its rows gives assistive technology the focus on the row chosen:
Up and Down are read as they move the choice, and focusing a row chooses
it. A screen reader moving out of view asks the list to scroll there,
which builds the rows it reaches. `label()` names a list or a table.

## Out of the pointer's way

`ignoreInput(true)` leaves a node, and those inside it, to assistive
technology alone: the pointer goes through it to what is under it, Tab and
clicks give it no focus, but assistive technology still finds it, with its
role and name, and presses it, which `$c->clicked()` reports. A button
that shows while its row is hovered can so stay built at rest, at
`opacity(0)`, where a screen reader finds it and a click on the row is the
row's:

```php
$app = new class {
    public array $items = ['Milk', 'Bread'];
    public array $opened = [];
    public function open(string $i): void { $this->opened[] = $i; }
    public function remove(string $i): void { $this->items = array_values(array_diff($this->items, [$i])); }
};
$view = function (Context $c) use ($app) {
    foreach ($app->items as $item) {
        $row = $c->key($item)->row(8);
        $hovered = $c->hovered($row);
          $c->text($item)->grow(1);
          $del = $c->button('Delete')->ignoreInput(!$hovered);
          if (!$hovered) {
              $del->opacity(0);
          }
          if ($c->clicked($del)) {
              $app->remove($item);
          }
        $c->end();
        if ($c->clicked($row)) {
            $app->open($item);
        }
    }
};
```

Assistive technology can neither focus it nor move a slider inside it, as
both go through the keyboard focus; it can set a text input's value.
Unlike HTML's `inert`, which hides a node from assistive technology too,
it stays in the tree: `invisible()` and `hidden()` take it out of it, and
`disabled()` keeps it there without its press.

## Announcements

News that does not move the focus, as a search done or a file saved,
reaches screen readers with `$c->announce($text)`, which they read once,
after what they are reading: VoiceOver's and Orca's announcements.
[Toasts](toast.md) announce their text, and a [router](navigation.md) the
title of a page shown while the focus stays outside it.

```php
$app = new class { public array $results = []; };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Search'))) {
        $app->results = ['one', 'two'];
        $c->announce(sprintf('%d results', count($app->results)));
    }
};
```

MyPHP describes frames only once assistive technology asked, so apps pay
nothing for it otherwise.

Each component's page says what assistive technology sees of it.
