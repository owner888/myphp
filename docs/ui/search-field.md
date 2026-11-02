# Search field

`$c->searchField()` creates a field for searching, editing a `TextState`: a
magnifying glass, the text, and a button clearing it while it holds any, as
Escape does then. It shows "Search" (in the window's language) while
empty. `$c->changed()` reports a change, and `$c->submitted()` Enter.

```php
$app = new class {
    public TextState $query;
    public int $filtered = 0;
    public function __construct() { $this->query = new TextState(); }
    public function filter(): void { $this->filtered++; }
};
$view = function (Context $c) use ($app) {
    $field = $c->searchField($app->query)->label('Search mail');
    if ($c->changed($field)) {
        $app->filter();
    }
};
```

The second argument is its width, 200 points by default; 0 fills what the
row has left.

To search as the user types, filter on `$c->changed()`; for searches that
take time, search on `$c->submitted()`, or after a pause with `$c->after()`.

## Accessibility

Assistive technology sees a search field (`AXSearchField` on macOS), named
by its `label()`.
