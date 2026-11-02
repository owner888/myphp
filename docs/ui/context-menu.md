# Context menu

`contextMenu()` gives a node a menu of the system's, which opens where the
node is right-clicked (Control-clicked on macOS), and below it when the
menu key or Shift+F10 is pressed while it or one inside it has the focus.
The function builds the items when the menu opens, and runs again in the
frame after one was chosen, where its `chosen()` reports it:

```php
$app = new class {
    public int $renaming = -1;
    public array $notes;
    public array $folders = ['Work', 'Home'];
    public function __construct() { $this->notes = [['title' => 'Groceries', 'pinned' => false, 'locked' => false, 'folder' => 'Home']]; }
};
$view = function (Context $c) use ($app) {
    $deleted = -1; $moved = -1; $moveTo = '';
    foreach ($app->notes as $i => $note) {
        $row = $c->key($i)->row(gap: 8);
          $c->text($note['title']);
        $c->end();
        $row->contextMenu(function (Menu $m) use ($app, $i, $note, &$deleted, &$moved, &$moveTo) {
            if ($m->item('Rename')->shortcut(0, Key::F2)->chosen()) {
                $app->renaming = $i;
            }
            if ($m->item('Pinned')->checked($note['pinned'])->chosen()) {
                $app->notes[$i]['pinned'] = !$note['pinned'];
            }
            $m->submenu('Move to', function (Menu $m) use ($app, $i, &$moved, &$moveTo) {
                foreach ($app->folders as $f) {
                    if ($m->item($f)->chosen()) {
                        [$moveTo, $moved] = [$f, $i];
                    }
                }
            });
            $m->separator();
            if ($m->item('Delete')->disabled($note['locked'])->chosen()) {
                $deleted = $i;
            }
        });
    }
    if ($moved >= 0) { $app->notes[$moved]['folder'] = $moveTo; }
    if ($deleted >= 0) { array_splice($app->notes, $deleted, 1); }
};
```

The function runs as the node builds, in the loop building the rows: a
choice that takes the row out of the notes, as moving or deleting it,
notes it, for the change once the loop is done (see
[views](views.md#events-and-actions)).

## Items

- `item($label)` adds an item; `chosen()` reports whether it was chosen.
- `checked($on)` shows a check mark by it, as for a setting that choosing
  the item toggles, and `radio($on)` shows it as one of a group choosing
  one setting, marked as the one chosen when `$on` is true.
- `disabled($d)` grays it out: it cannot be chosen.
- `shortcut($mods, $key)` shows a key by it, as the shortcut that does the
  same. It only shows the key: handle it with `$c->shortcut()` or
  `onShortcut()`.
- `separator()` adds a line between groups of items, `header($label)` the
  title of the group under it, and `submenu($label, $build)` an item
  opening a submenu, which `$build` fills.

## Which node

The innermost node with a menu gets the click, as a row's button with a
menu of its own inside a row with another. [Text inputs](text-input.md)
have the editing commands of their platform's text fields, and selectable
[text](text.md) has Copy and Select All. `contextMenu()` on the input, the
text, or the `selectable()` container replaces that menu, and
`editItems()` adds those commands to a menu of one's own, acting on the
text that was clicked, as a chat message's text offers Reply above Copy:

```php
$app = new class { public array $replies = []; public function reply(string $m): void { $this->replies[] = $m; } };
$msg = 'See you at noon.';
$view = function (Context $c) use ($app, $msg) {
    $message = $c->column()->selectable()->contextMenu(function (Menu $m) use ($app, $msg) {
        if ($m->item('Reply')->chosen()) {
            $app->reply($msg);
        }
        $m->separator();
        $m->editItems();
    });
      $c->text($msg);
    $c->end();
};
```

The same menu opens below a button with `menu()`: see
[menu button](menu-button.md). In tests, `Headless::rightClick()` opens a
context menu, which `menuLabels()` lists and `chooseMenuItem()` chooses
from (see [testing](testing.md)).

## Drawn in the window

`popoverMenu()` takes the same function, and MyPHP draws the menu in the
window with the theme's look, as a [popover](popover.md), where the system
would show its own: beside the popovers and buttons of an app's own design,
the system's menu is the one that looks out of place.

```php
$album = new class { public string $title = 'Blue'; public bool $pinned = false; public bool $locked = false; };
$app = new class {
    public string $sort = 'Title';
    public array $queue = [];
    public function playNext(object $a): void { $this->queue[] = $a; }
    public function remove(object $a): void {}
};
$view = function (Context $c) use ($app, $album) {
    $card = $c->column(pad: 8)->popoverMenu(function (Menu $m) use ($app, $album) {
        if ($m->item('Play Next')->chosen()) {
            $app->playNext($album);
        }
        if ($m->item('Pinned')->checked($album->pinned)->chosen()) {
            $album->pinned = !$album->pinned;
        }
        $m->submenu('Sort by', function (Menu $m) use ($app) {
            foreach (['Title', 'Artist', 'Year'] as $by) {
                if ($m->item($by)->radio($app->sort === $by)->chosen()) {
                    $app->sort = $by;
                }
            }
        });
        $m->separator();
        if ($m->item('Remove')->shortcut(0, Key::DELETE)->disabled($album->locked)->chosen()) {
            $app->remove($album);
        }
    });
      $c->text($album->title);
    $c->end();
};
```

It opens as the system's would, where the node is right-clicked, or below
it for the menu key or Shift+F10, after that point, or before it where the
window has no room, and follows the node as the window scrolls. It takes
the focus as it opens and gives it back as it closes to the node that had
it, and has the keys of the system's menus:

- Up and Down move among the items, past separators and those disabled,
  and round; Home and End go to the first and the last, and typing the
  first letters of an item goes to it.
- Right opens the submenu of the item, and Left closes it, the other way
  round [right to left](right-to-left.md); the pointer opens it as it
  moves onto the item.
- Enter and Space choose the item, as a click does; Escape closes the
  submenu on top, then the menu, and Tab closes the menu.
- A press outside the menu closes it, and goes on to what is under the
  pointer.

Its items show their `shortcut()` after their label, with a check mark or
a dot by those `checked()` or `radio()` marks. Assistive technology sees a
menu of menu items, check box items and radio items, checked or not, as it
sees the system's.

A drawn menu's items are nodes: in tests, `rightClick()` opens it, its
items are found by their text and a click chooses one, while
`menuLabels()` lists only the system's menus.

`popoverMenuButton()` draws the menu of a [menu
button](menu-button.md#drawn-in-the-window) so, below the button, and
takes the same function: a row's right-click and its ⋯ button can share
one menu, drawn alike.
