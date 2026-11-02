# Toast

`$c->toast()` shows a message near the bottom of the window for a few
seconds, as the outcome of what the user just did:

```php
$app = new class { public int $saves = 0; public function save(): void { $this->saves++; } };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Save'))) {
        $app->save();
        $c->toast('Saved');
    }
};
```

`$c->toastAction()` shows one with a button, as Undo after deleting, for
longer: the button runs the action, on the main thread as the view does,
and closes the toast; Tab reaches it.

```php
$app = new class {
    public array $notes = ['Groceries', 'Ideas'];
    public array $trash = [];
    public function trash(string $n): void { $this->notes = array_values(array_diff($this->notes, [$n])); $this->trash[] = $n; }
    public function restore(string $n): void { $this->notes[] = $n; }
};
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Delete')) && $app->notes !== []) {
        $note = $app->notes[0];
        $app->trash($note);
        $c->toastAction('Note deleted', 'Undo', fn() => $app->restore($note));
    }
};
```

A message already showing shows anew; others stack above it, the newest
three showing, while older ones wait hidden, their time running. The time
of the toasts stops while the pointer rests on them, the keyboard focus is
in them, or the window is in the background. Escape closes the toast the
focus is in. Toasts fade in and out.

A toast takes the theme's `inverse` and `inverseText` colors, its text and
background turned over unless the theme sets them (see
[themes](styling.md#themes)).

## Adding and closing toasts

`$c->addToast()` shows a `Toast`, as Base UI's toast manager adds one, and
returns its ID; `toast()` and `toastAction()` are `addToast()` with the
message as the ID and the title:

```php
$app = new class { public string $upload = ''; };
$view = function (Context $c) use ($app) {
    if ($app->upload === '') {
        $app->upload = $c->addToast(new Toast(
            title: 'Uploading',
            description: 'report.pdf',
            timeout: -1, // until closed
        ));
    }
    // ...
    if ($c->clicked($c->button('Cancel upload'))) {
        $c->closeToast($app->upload);
    }
};
```

| Property | What it does |
|---|---|
| `id` | names the toast: adding one with the ID of a toast showing changes that toast, which shows anew; one is made up when empty |
| `title`, `description` | what it says, which screen readers read out as it shows |
| `type` | what kind of toast it is, as `'error'`, for the look to tell |
| `timeout` | how long it shows, in seconds: 4 when zero, until closed when negative |
| `action`, `onAction` | a button that runs `onAction` and closes the toast |
| `onClose` | runs as it closes: its time over, or the user, its action or `closeToast()` closed it |
| `data` | what else the look needs |

`$c->closeToast('')` closes every toast.

## Without a look

`$c->toastViewportBase()` builds the window's toasts with a look of your
own, in place of the theme's, as Base UI's Toast parts do: its function
styles the viewport, a column covering the window that the pointer goes
through, which holds the toasts at its bottom (`justify()` and
`alignItems()` move them), and builds each toast showing with
`$c->toastBase()`, whose `root` assistive technology sees as a status, and
whose `actionButton()` and `closeButton()` run the action and close it.
Each part is built in until `end()`. Call it once in the view, wherever.
Toasts in the bottom right corner:

```php
$alert = SVG::fromFile('icons/clock.svg');
$x = SVG::fromFile('icons/trash.svg');
$view = function (Context $c) use ($alert, $x) {
    $th = $c->theme;
    if ($c->clicked($c->button('Fail'))) {
        $c->addToast(new Toast(title: 'Upload failed', type: 'error', action: 'Retry', onAction: fn() => null));
    }
    $c->toastViewportBase(function (Node $viewport, array $toasts) use ($c, $th, $alert, $x) {
        $viewport->padding(16)->alignItems(Node::END)->gap(8);
        foreach ($toasts as $t) {
            $toast = $c->toastBase($t);
            $c->transition($toast->root, new ElementTransition(enter: new TransitionMotion(y: 8), exit: new TransitionMotion()));
              $c->row(gap: 12)->padding(10, 14)->radius(8)->background($th->surface)->border(1, $th->border);
                if ($t->type === 'error') {
                    $c->icon($alert)->textColor($th->danger);
                }
                $c->text($t->title)->grow(1);
                if ($t->action !== '') {
                    $toast->actionButton();
                      $c->text($t->action);
                    $c->end();
                }
                $toast->closeButton()->label('Close');
                  $c->icon($x);
                $c->end();
              $c->end();
            $c->end();
        }
    });
};
```

`$toasts` are those showing, the newest three, oldest first.
`$toast->left()` is how long one shows still, to count down, 0 for one
showing until closed. The time and the pausing are the same as with the
theme's look. The viewport stays while no toast shows, for the last one to
go with its exit transition.

## Accessibility

Screen readers read a toast's title and description as it shows (see
[announcements](accessibility.md#announcements)); assistive technology sees
it as a status, which holds its buttons.
