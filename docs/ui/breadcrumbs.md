# Breadcrumbs

`Breadcrumbs($path, selection:)` shows a path of items, as Finder's path
bar and AppKit's path control, separated by chevrons: the items but the
last are links, which a click chooses, as Enter does, setting an `int`
(bound by reference) to its index. Long items shorten with an ellipsis as
room runs short. SwiftUI has none: it is myphp's own.

```php
$app = new class { public int $at = 3; };
$view = fn() => Breadcrumbs(['Macintosh HD', 'Users', 'developer', 'Documents'], selection: $app->at)
    ->onChange(of: $app->at, action: fn() => null)->padding();
```

## Accessibility

Assistive technology sees a navigation of links named by the items, the
last one the current page.
