# Checkbox

`$c->checkbox()` creates a check box with a label toggling a `bool` (by
reference): a click on the box or its label checks it or unchecks it, as
does Space while it has the keyboard focus, and `$c->changed()` reports a
click.

```php
$app = new class {
    public bool $sync = true;
    public bool $showHidden = false;
    public int $reloads = 0;
    public function reload(): void { $this->reloads++; }
};
$view = function (Context $c) use ($app) {
    $c->checkbox('Sync across devices', $app->sync);
    if ($c->changed($c->checkbox('Show hidden files', $app->showHidden))) {
        $app->reload();
    }
};
```

The check box changes the value itself, the moment the user clicks: read
it, or ask `$c->changed()` for work that follows.

## Groups

`$c->checkboxGroup()` creates a check box over the check boxes built inside
it, indented below it, as a list of permissions: it is checked when they
all are, mixed when some are, and a click, or Space while it has the focus,
checks them all, or none when they all are. `$c->changed()` reports a
click.

```php
$app = new class { public bool $mail = true; public bool $calendar = false; public bool $messages = true; };
$view = function (Context $c) use ($app) {
    $c->checkboxGroup('Notifications', function () use ($c, $app) {
        $c->checkbox('Mail', $app->mail);
        $c->checkbox('Calendar', $app->calendar);
        $c->checkbox('Messages', $app->messages);
    });
};
```

## Without a look

`$c->checkboxBase()` is a check box without a look: a row that toggles its
`bool` when clicked, which you draw from the value: see
[custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a check box named by its label, checked or not,
which it toggles as a click does. A check box group is a check box, mixed
while some are checked, and a group of the others named by its label.
