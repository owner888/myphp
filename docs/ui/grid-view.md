# Grid view

`$c->gridView()` shows items in a grid, as Photos' library and Finder's
icon view: as many columns as fit items at least as wide as asked, sharing
the width left, in rows of a height, built only as they show, so grids of
millions of items are as fast as small ones. `$t->space(2)` spaces the
items.

```php
$photos = new GridState();
$photos->selects = true;
$names = array_map(fn($i) => "IMG_$i.jpg", range(1, 500));
$view = function (Context $c) use ($photos, $names) {
    $c->gridView($photos, count($names), 140, 120, function (int $i) use ($c, $names) {
        $c->box(120, 80, $c->theme->surface)->grow(1);
        $c->text($names[$i])->singleLine();
    })->grow(1);
};
```

## Grid state

A `GridState` keeps the grid's place (`rows`, a `ListState`) and says how
items are chosen:

- `selects` lets a click choose an item, `selected`, as the arrows do in
  both directions while the grid has the keyboard focus, with Home, End,
  and Enter (`$c->submitted()`).
- `selection`, a `Selection`, lets the user choose several, by `key`:
  Cmd-click adds an item or takes it out, Shift-click and Shift with the
  arrows choose those from the item last chosen, and Cmd+A chooses all.
- `label` returns an item's text: typing its first letters chooses it, and
  assistive technology reads it as the item's name.
- `reorder` lets the user drag items to another place, as a list's rows:
  see [drag and drop](drag-and-drop.md).

`scrollTo($item)` and `scrollIntoView($item)` bring an item into view.

## Accessibility

Assistive technology reads the grid as a list of items, each saying which
of all it is, built or not.
