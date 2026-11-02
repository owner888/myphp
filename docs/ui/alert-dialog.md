# Alert dialog

`$c->alertDialog()` asks about something important over the window while a
`bool` (by reference) is true, as AppKit's alerts: a title, a message, and
buttons, the last of which is the default, in the accent color, with the
focus, which Enter clicks. Escape closes the alert choosing no button, as
canceling does, whatever the buttons say, in any language; a click outside
the alert does nothing. It returns the index of the button clicked, in the
frame it is, which closes the alert, `Context::ALERT_DISMISSED` in the
frame Escape closes it, and -1 otherwise.

```php
$app = new class {
    public bool $asking = false;
    public bool $deleted = false;
    public function delete(): void { $this->deleted = true; }
};
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Delete'))) {
        $app->asking = true;
    }
    switch ($c->alertDialog($app->asking, 'Delete “Notes”?', "You can't undo this.", 'Cancel', 'Delete')) {
        case 1:
            $app->delete();
            break;
    }
};
```

An alert that must be answered stays open by setting the `bool` back to
true as Escape dismisses it:

```php
$app = new class { public bool $updating = true; };
$view = function (Context $c) use ($app) {
    if ($c->alertDialog($app->updating, 'Update required', 'Restart to finish updating.', 'Restart') === Context::ALERT_DISMISSED) {
        $app->updating = true;
    }
};
```

For a dialog of your own content, use a [dialog](dialog.md); for a choice
the user can undo, act at once and offer it in a [toast](toast.md).

## Accessibility

Assistive technology sees an alert dialog named by its title and described
by its message, with the focus on its default button.
