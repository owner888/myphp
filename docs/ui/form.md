# Form

A `$c->field()` labels the control its function builds: the label names
the control for assistive technology, and a click on it focuses the
control, or clicks a check box, a switch or a radio button. Its third and
fourth arguments, a description and an error, add texts below the control,
which assistive technology reads with it; an error also marks the
control's value invalid, and a text input draws its border in the theme's
`danger` color. An empty error leaves the control valid:

```php
$app = new class { public TextState $email; public function __construct() { $this->email = new TextState('ada'); } };
$view = function (Context $c) use ($app) {
    $invalid = '';
    if ($app->email->text !== '' && !str_contains($app->email->text, '@')) {
        $invalid = 'Enter an email address.';
    }
    $c->field('Email', function () use ($c, $app) {
        $c->input($app->email);
    }, description: 'For receipts.', error: $invalid);
};
```

The control is the first node the function builds that takes the focus,
or a group of them, such as a [radio group](radio.md).

## Forms

A field's label sits above its control, as on the web. In a `$c->form()`,
the labels of its fields sit in a column of their own beside the controls,
as in macOS's forms: aligned to the right, as wide as the widest, and level
with the first line of text of their control, or centered on a control
without text, such as a switch. Its fields are `$t->space(3)` apart.

```php
$app = new class {
    public TextState $name;
    public int $plan = 0;
    public bool $sound = true;
    public function __construct() { $this->name = new TextState(); }
};
$plans = ['Free', 'Pro', 'Team'];
$view = function (Context $c) use ($app, $plans) {
    $c->form(function () use ($c, $app, $plans) {
        $c->field('Name', fn() => $c->input($app->name));
        $c->field('Plan', fn() => $c->select($plans, $app->plan));
        $c->field('Sound', fn() => $c->switch('', $app->sound));
    });
};
```

## Fieldsets

A `$c->fieldset()` groups fields under a legend, which names the group;
their labels line up with the form's others, and `disabled()` disables
them all:

```php
$app = new class {
    public TextState $name;
    public bool $mail = false;
    public bool $sound = true;
    public bool $signedIn = false;
    public function __construct() { $this->name = new TextState(); }
};
$view = function (Context $c) use ($app) {
    $c->form(function () use ($c, $app) {
        $c->field('Name', fn() => $c->input($app->name));
        $c->fieldset('Notifications', function () use ($c, $app) {
            $c->field('Email', fn() => $c->checkbox('Weekly summary', $app->mail));
            $c->field('Sound', fn() => $c->switch('', $app->sound));
        })->disabled(!$app->signedIn);
    });
};
```

A control with a name of its own, as a check box with its text, keeps it,
and the field's label names the group around them.

## Accessibility

Assistive technology reads a control by its field's label, with the
description and the error after it, and the value invalid while there is
an error; a fieldset is a group named by its legend.
