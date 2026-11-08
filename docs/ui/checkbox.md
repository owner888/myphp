# Checkbox

A `Toggle` with `->toggleStyle(ToggleStyle::checkbox)` (what it is on a
Mac by default) is a check box with a title, toggling a `bool` bound by
reference: a click on the box or its title checks it or unchecks it, as
does Space while it has the keyboard focus.

```php
$app = new class {
    public bool $sync = true;
    public bool $showHidden = false;
    public int $reloads = 0;
    public function reload(): void { $this->reloads++; }
};
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    Toggle('Sync with iCloud', isOn: $app->sync)->toggleStyle(ToggleStyle::checkbox),
    Toggle('Show hidden files', isOn: $app->showHidden)->toggleStyle(ToggleStyle::checkbox)
        ->onChange(of: $app->showHidden, action: fn() => $app->reload()),
)->padding();
```

The check box changes the value itself, the moment the user clicks: read
it, or `onChange(of:)` for work that follows.

## Groups

A `Toggle` given `sources:`, the values it stands for, is checked when they
all are, mixed when some are, and a click checks them all, or none when
they all are, as SwiftUI's:

```php
$app = new class { public bool $read = true; public bool $write = false; public bool $delete = false; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    Toggle('All permissions', sources: [&$app->read, &$app->write, &$app->delete])->toggleStyle(ToggleStyle::checkbox),
    Toggle('Read', isOn: $app->read)->toggleStyle(ToggleStyle::checkbox)->padding(Edge::leading, 20),
    Toggle('Write', isOn: $app->write)->toggleStyle(ToggleStyle::checkbox)->padding(Edge::leading, 20),
    Toggle('Delete', isOn: $app->delete)->toggleStyle(ToggleStyle::checkbox)->padding(Edge::leading, 20),
)->padding();
```

## Accessibility

Assistive technology sees a check box named by its title, checked, unchecked
or mixed, which it toggles as a click does.
