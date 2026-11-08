# Form

A `Form` lays out labeled controls, as SwiftUI's: on the desktop a column
of rows, each control's title at its start; on a phone an inset grouped
list, Settings', its `Section`s cards of rows. Each control takes its title
itself (`Toggle('Sound', isOn:)`, `TextField('Name', text:)`), and
`LabeledContent` is a row of a title and a value.

```php
$app = new class { public string $name = 'Ada'; public bool $news = true; public string $plan = 'free'; };
$view = fn() => Form()(
    Section('Account', footer: 'Your name shows on what you share.')(
        TextField('Name', text: $app->name),
        Picker('Plan', selection: $app->plan)(Text('Free')->tag('free'), Text('Pro')->tag('pro')),
        Toggle('Send me news', isOn: $app->news),
    ),
    Section('About')(
        LabeledContent('Version', '1.0'),
    ),
);
```

A `Section`'s header shows over its rows, its footer under them, muted. A
`NavigationLink` in a phone's form is a row with a chevron, opening a page;
a `Button` a row of its title in the tint.

## Errors

A field's error is a `Text` under it in red, shown while the value is not
valid; disable the button that saves until it is:

```php
$app = new class { public string $email = ''; };
$view = fn() => Form()(
    Section('Contact')(
        TextField('Email', text: $app->email),
        $app->email !== '' && !str_contains($app->email, '@') ? Text('Not an email address')->foregroundStyle(Color::red)->font(Font::footnote) : null,
        Button('Save', action: fn() => null)->disabled(!str_contains($app->email, '@')),
    ),
);
```

## Accessibility

Assistive technology sees each control named by its title, a section as a
group named by its header.
