# Dialog

`$c->modal()` shows a dialog its function builds over the dimmed window
while a `bool` (by reference) is true; clicking outside it or pressing
Escape sets it to false.

```php
$app = new class {
    public bool $renaming = false;
    public TextState $name;
    public string $title = 'Untitled';
    public function __construct() { $this->name = new TextState('Untitled'); }
    public function rename(): void { $this->title = $this->name->text; }
};
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Rename…'))) {
        $app->renaming = true;
    }
    $c->modal($app->renaming, function () use ($c, $app) {
        $c->text('Rename')->bold();
        if ($c->submitted($c->input($app->name)->autoFocus())) {
            $app->renaming = false;
        }
        $c->row(gap: 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) {
              $app->renaming = false;
          }
          if ($c->clicked($c->primaryButton('Rename'))) {
              $app->rename();
              $app->renaming = false;
          }
        $c->end();
    });
};
```

## The keyboard

A dialog keeps the keyboard: it takes the focus as it opens, on its first
node that takes it unless one in it asked for it (`autoFocus()`), Tab goes
round its nodes, and the window's shortcuts built outside it wait, as what
is behind it is inert. As it closes, it gives the focus back to the node
that had it, the button that opened it say. See [overlays](overlays.md).

## Keeping it open

`$c->dismissed()` reports the frame Escape or a click outside closed the
dialog; one that must stay open, with changes to save say, sets the `bool`
back to true then:

```php
$app = new class { public bool $editing = true; public bool $unsaved = true; };
$view = function (Context $c) use ($app) {
    $d = $c->modal($app->editing, fn() => $c->text('Editor'));
    if ($d !== null && $c->dismissed($d) && $app->unsaved) {
        $app->editing = true;
    }
};
```

`modal()` returns the panel, or null while the dialog is closed.

To ask about something important, with buttons and no closing by a click
outside, use an [alert dialog](alert-dialog.md).

## Without a look

`$c->dialogBase()` is a dialog without a look: its function styles the
backdrop covering the window, which centers the panel, and the panel, and
builds the panel's content:

```php
$app = new class { public bool $open = true; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $c->dialogBase($app->open, function (Node $backdrop, Node $panel) use ($c, $t) {
        $backdrop->background(0x000000, 0.5);
        $panel->width(360)->padding(24)->radius(16)->background($t->background);
        $c->text('Welcome')->fontSize(20)->bold();
    });
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a dialog, and nothing behind it while it shows.
