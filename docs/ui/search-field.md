# Search field

`->searchable(text:)` puts a search field over a list or a page, bound to a
string, as SwiftUI's: a magnifying glass, the text, and a button clearing
it while it holds any, as Escape does then. It shows "Search" (in the
window's language) while empty, or its `prompt:`. The app filters what it
shows by it:

```php
$app = new class { public string $query = ''; public array $fruits = ['Apple', 'Banana', 'Cherry']; };
$view = fn() => ListView(array_values(array_filter($app->fruits, fn($f) => $app->query === '' || stripos($f, $app->query) !== false)))(
    fn(string $f) => Text(verbatim: $f),
)->searchable($app->query, prompt: 'Fruits');
```

On a phone it is iOS 26's capsule across the top of the page.
`->searchScopes($scope, Text('All')->tag('all'), …)` after it adds a
segmented control of scopes under it. `SearchField(text:)` is the field on
its own, anywhere (myphp's own, as AppKit's NSSearchField).

To search as the user types, filter as above; for searches that take time,
start them from `->onSubmit()` or after a pause ([async](../app.md)).

## Accessibility

Assistive technology sees a search field (`AXSearchField` on macOS), named
by its prompt.
