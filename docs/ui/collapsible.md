# Disclosure group

A `DisclosureGroup` shows its title with a chevron, and its content below
while it is expanded, as SwiftUI's: a click on the title, or Space or Enter
while it has the focus, opens or closes it. `isExpanded:` binds whether it
is open; without it, it keeps that itself.

```php
$app = new class { public bool $advanced = false; public bool $logging = true; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    DisclosureGroup('Advanced', isExpanded: $app->advanced)(
        Toggle('Logging', isOn: $app->logging),
    ),
)->padding();
```

Its content slides in and out. Several make an accordion; nested ones an
outline ([outline](outline.md)).

## Accessibility

Assistive technology sees a button, expanded or collapsed, named by its
title, controlling the content.
