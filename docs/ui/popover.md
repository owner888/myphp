# Popover

`$c->popover()` shows a panel its function builds below a node, its
anchor, while a `bool` (by reference) is true; pressing outside it or
Escape sets it to false. The press goes on to what is under the pointer,
as with the web's popovers: a click on another button closes the popover
and presses the button, and one on the anchor closes it, as the anchor
toggles it. Where there is no room below the anchor, the panel shows above
it, and it follows the anchor as the window scrolls.

```php
$app = new class {
    public bool $menu = false;
    public bool $renaming = false;
    public int $copies = 0;
    public function duplicate(): void { $this->copies++; }
};
$view = function (Context $c) use ($app) {
    $more = $c->button('More ▾');
    if ($c->clicked($more)) {
        $app->menu = !$app->menu;
    }
    $c->popover($more, $app->menu, function () use ($c, $app) {
        if ($c->clicked($c->button('Rename'))) {
            [$app->menu, $app->renaming] = [false, true];
        }
        if ($c->clicked($c->button('Duplicate'))) {
            $app->menu = false;
            $app->duplicate();
        }
    });
};
```

`$c->dismissed()` on the panel it returns reports the frame a press outside
or Escape closed the popover; one that must stay open sets the `bool` back
to true then.

A popover's nodes follow its anchor as Tab moves, and as it closes with the
focus in it, it gives the focus back to the node that had it. See
[overlays](overlays.md).

For a menu of the system's, use a [menu button](menu-button.md); for a
node's menu drawn in the window, with the keys of a menu, a
[`popoverMenu()`](context-menu.md#drawn-in-the-window), or a
[`popoverMenuButton()`](menu-button.md#drawn-in-the-window) below a
button; for a tip, a [tooltip](tooltip.md). A popover shows in its window:
one that must extend beyond the window's edges shows in a
[flyout](../windows.md#flyouts), a window of its own placed next to the
anchor.

## Without a look

`$c->popoverBase()` is a popover without a look: its function styles the
panel and builds its content. A top margin keeps the panel apart from the
anchor, on either side:

```php
$app = new class { public bool $open = true; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $anchor = $c->button('Filters');
    $c->popoverBase($anchor, $app->open, function (Node $panel) use ($c, $t) {
        $panel->margin(6, 0, 0, 0)->padding(8)->radius(12)->background($t->background)->shadow(0, 8, 24, 0, 0x000000, 0.2);
        $c->text('Filters');
    });
};
```

The panel goes elsewhere with `attachTo()`: to the right of the anchor,
its middles lined up, and to the left where there is no room on the right:

```php
$app = new class { public bool $open = true; };
$view = function (Context $c) use ($app) {
    $anchor = $c->button('Details');
    $c->popoverBase($anchor, $app->open, function (Node $panel) use ($c, $anchor) {
        $panel->attachTo($anchor, Anchor::Right, Anchor::Left)->margin(0, 0, 0, 6);
        $c->text('Details');
    });
};
```

Popovers of your own, as a hover card, are built in an overlay with
`attachTo()` and `$c->pressedOutside()`: see
[overlays](overlays.md#your-own-overlays).

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees the panel as a popup after its anchor.
