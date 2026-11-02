# Menu button

`$c->menuButton()` creates a button showing a label and an arrow, which
opens a menu of the system's below it, as the system's pop-up buttons do:
as the pointer goes down on it, or for Enter, Space or Down while it has
the keyboard focus. The function builds the items when the menu opens, and
runs again in the frame after one was chosen, where its `chosen()` reports
it:

```php
$app = new class { public string $sort = 'Name'; };
$view = function (Context $c) use ($app) {
    $c->menuButton('Sort by', function (Menu $m) use ($app) {
        foreach (['Name', 'Date', 'Size'] as $by) {
            if ($m->item($by)->checked($app->sort === $by)->chosen()) {
                $app->sort = $by;
            }
        }
    });
};
```

`Menu` here is `MyPHP\UI\Menu`, the menu a view builds, not the
application's `MyPHP\Menu`.

## Items

Menus take the same items as [context menus](context-menu.md#items):
`item()`, with `checked()`, `radio()`, `disabled()` and `shortcut()`,
`separator()`, `header()` and `submenu()`.

## Any node

`menu()` gives the same menu to any node, as a button showing only an icon
built on `$c->buttonBase()`:

```php
$dots = SVG::fromFile('icons/list.svg');
$app = new class {
    public int $copies = 0; public bool $deleted = false;
    public function duplicate(): void { $this->copies++; }
    public function delete(): void { $this->deleted = true; }
};
$view = function (Context $c) use ($app, $dots) {
    $c->buttonBase()->padding(6)->label('More')->menu(function (Menu $m) use ($app) {
        if ($m->item('Duplicate')->chosen()) {
            $app->duplicate();
        }
        if ($m->item('Delete')->chosen()) {
            $app->delete();
        }
    });
      $c->icon($dots);
    $c->end();
};
```

In a [toolbar](toolbar.md), a menu button takes no face of its own until
hovered.

## Drawn in the window

`$c->popoverMenuButton()` takes the same function, and MyPHP draws the menu
in the window with the theme's look, as
[`popoverMenu()`](context-menu.md#drawn-in-the-window) draws a context
menu, where the system would show its own: beside the popovers and buttons
of an app's own design, the system's menu is the one that looks out of
place, and a right-click's drawn menu and a ⋯ button's system menu, side by
side, look like two menus. `popoverMenuButton()` on a node gives such a
menu to any node, as `menu()` does, and takes the same function as
`popoverMenu()`, so one function serves both:

```php
$note = new class { public string $title = 'Groceries'; public bool $pinned = false; public bool $locked = false; };
$app = new class { public int $renaming = -1; public array $deleted = []; public function delete(int $i): void { $this->deleted[] = $i; } };
$i = 0;
$view = function (Context $c) use ($app, $note, $i) {
    $menu = function (Menu $m) use ($app, $note, $i) {
        if ($m->item('Rename')->shortcut(0, Key::F2)->chosen()) {
            $app->renaming = $i;
        }
        if ($m->item('Pinned')->checked($note->pinned)->chosen()) {
            $note->pinned = !$note->pinned;
        }
        $m->separator();
        if ($m->item('Delete')->disabled($note->locked)->chosen()) {
            $app->delete($i);
        }
    };
    $row = $c->row(gap: 8)->popoverMenu($menu);
      $c->text($note->title)->grow(1);
      $c->popoverMenuButton('More', $menu);
    $c->end();
};
```

It opens as the system's would, as the pointer goes down on the button,
and for Enter, Space or Down while the button has the focus: below the
button, lined up with its start, or above it where the window has no room.
Pressing the button again closes it. It has the keys, the focus and the
look of `popoverMenu()`'s: it takes the focus as it opens, on its first
item when the keyboard opened it, and gives it back to the button as it
closes; Up, Down, Home, End and typing move among its items, Right and
Left open and close submenus, Enter and Space choose, and Escape, Tab and
presses outside close it. A choice reports `chosen()` as from
`popoverMenu()`.

Assistive technology sees the menu button, expanded while its menu shows,
and the menu with its menu items, check box items and radio items, as it
sees the system's. In a toolbar's overflow menu, choosing the button opens
its drawn menu below the overflow button.

A drawn menu's items are nodes: in tests, a click on the button opens it,
its items are found by their text and a click chooses one, while
`Headless::menuLabels()` lists only the system's menus (see
[testing](testing.md)).

## Accessibility

Assistive technology sees a menu button: `AXMenuButton` on macOS, a push
button with a menu on Linux.
