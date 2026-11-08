# Overlays

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)). For views, `->sheet()`, `->alert()`, `->popover()` and `->contextMenu()`.

Overlays show above the window's content: [dialogs](dialog.md) over the
dimmed window, [alert dialogs](alert-dialog.md), [popovers](popover.md)
below a node, the popups of [selects](select.md) and
[comboboxes](combobox.md), menus drawn in the window
([`popoverMenu()`](context-menu.md#drawn-in-the-window) and
[`popoverMenuButton()`](menu-button.md#drawn-in-the-window)),
[tooltips](tooltip.md) and [toasts](toast.md). They share how they treat
the keyboard and what is behind them. Though they show above the window,
they take the theme of where they were asked for: opened inside
[`themed()`](styling.md#a-theme-for-part-of-the-window), its theme, as
`$c->overlay()`'s nodes built there do; toasts are the window's.

```php
$app = new class { public bool $menu = false; public bool $renaming = false; public TextState $name; public function __construct() { $this->name = new TextState(); } };
$view = function (Context $c) use ($app) {
    $more = $c->button('More ▾');
    if ($c->clicked($more)) {
        $app->menu = !$app->menu;
    }
    $c->popover($more, $app->menu, function () use ($c, $app) {
        if ($c->clicked($c->button('Rename'))) {
            [$app->menu, $app->renaming] = [false, true];
        }
    });
    $c->modal($app->renaming, function () use ($c, $app) {
        $c->text('Rename')->bold();
        if ($c->submitted($c->input($app->name)->autoFocus())) {
            $app->renaming = false;
        }
    });
};
```

## The keyboard

A dialog keeps the keyboard: it takes the focus as it opens, on its first
node that takes it unless one in it asked for it (`autoFocus()`), Tab goes
round its nodes, and the window's shortcuts built outside it wait, as what
is behind it is inert, which screen readers do not see either. A popover's
nodes follow its anchor as Tab moves.

Escape closes the overlay on top, a select's popup before the dialog it is
in, unless the focused node takes it, as a terminal does; and an overlay
that closes with the focus in it gives the focus back to the node that had
it as it opened, its button say.

A press outside a popover closes it and goes on to what is under the
pointer, as with the web's popovers: a click on another button closes the
popover and presses the button. A select's popup takes the press, as the
system's pop-up menus do, and a dialog's backdrop closes it.

## Your own overlays

`$c->overlay()` builds nodes above everything else, placed with
`absolute()` in points of the window:

```php
$t = $c->theme;
$c->overlay(function () use ($c, $t) {
    $c->text('Offline')->absolute()->top(12)->right(12)
        ->padding(4, 10)->radius(999)->background($t->danger)->textColor($t->accentText);
});
```

`$c->popoverBase()` and `$c->dialogBase()` are a popover and a dialog
without a look, for overlays of your own design: see [custom
widgets](custom-widgets.md). Overlays that neither fits, as a drawer, a
hover card or a menu bar's menus, are built from what those are made of:

- `attachTo($target, $at, $self)` places a node beside another, wherever
  that one is: its point `$self` on the point `$at` of the target, as
  `attachTo($button, Anchor::BottomLeft, Anchor::TopLeft)` below a button.
  Where it would leave the window, it goes to the other side, or the other
  way along the target, then moves into the window, 4 points from its
  edges. Its margins keep it apart from the target. It is the target's
  popover: its nodes follow the target as Tab moves.
- `$c->pressedOutside($node)` reports a press outside a node, its target
  and the popovers of what is inside it, as a popover closes for. A press
  before the node showed is not outside it: an overlay that a right-click
  opens stays open, the press opening it in the same frame.
- `$c->overlayShortcut($node, $key, $mods)` takes the keys the focused
  node and those around it leave, wherever the focus is, the overlay built
  last first, as Escape closing the one on top.
- `block()` makes a node a dialog's backdrop: the pointer and Tab reach
  only it, what is inside it and what comes after, the window's shortcuts
  outside it wait, and screen readers see only it and what is above it.

Each node built at the top of `$c->overlay()` gives the focus back to the
node that had it as it came, when it goes with the focus in it. A drawer on
the right, closing with Escape or a click on its backdrop:

```php
$app = new class { public bool $drawer = true; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    if ($app->drawer) {
        $c->overlay(function () use ($c, $t, $app) {
            $back = $c->row()->absolute()->left(0)->top(0)->right(0)->bottom(0)->justify(Node::END)
                ->background(0x000000, 0.3)->block();
              $panel = $c->column(16)->width(320)->fillHeight()->background($t->background)
                  ->role(Role::DIALOG)->label('Filters');
                $c->text('Filters');
              $c->end();
            $c->end();
            if ($c->pressedOutside($panel) || $c->overlayShortcut($back, Key::ESCAPE)) {
                $app->drawer = false;
            }
        });
    }
};
```

Native [dialogs](../native.md#dialogs) work too: they show as sheets or
windows of the system's, and take a function that gets the result.
