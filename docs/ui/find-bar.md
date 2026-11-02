# Find bar

`$c->findBar()` creates a bar for finding text while a `bool` (by
reference) is true, as Safari's and Xcode's: a field of the query, a
`TextState`, which takes the focus as the bar opens with the last query
selected, how many matches there are, previous and next buttons choosing
the current match, an `int` by reference, and Done.

```php
$app = new class {
    public bool $finding = false;
    public TextState $query;
    public int $match = 0;
    public array $lines = ['one apple', 'two pears', 'an apple pie'];
    public function __construct() { $this->query = new TextState('apple'); }
    public function find(string $q): array { return $q === '' ? [] : array_keys(array_filter($this->lines, fn($l) => str_contains($l, $q))); }
};
$view = function (Context $c) use ($app) {
    if ($c->shortcut(Key::F, Key::COMMAND)) {
        $app->finding = true;
    }
    $matches = $app->find($app->query->text); // the app counts them
    $c->findBar($app->finding, $app->query, count($matches), $app->match);
    if ($app->finding && $matches !== []) {
        $c->text($app->lines[$matches[$app->match]]);
    }
};
```

The app finds: it counts the matches of the query and shows the current
one, which `$c->changed()` reports moving.

## Keyboard

Enter in the field goes to the next match, Shift+Enter to the previous, as
do Cmd+G and Shift+Cmd+G, round the ends; Escape and Done close the bar.

While closed, it builds nothing and returns null.

## Accessibility

Assistive technology sees a toolbar named "Find", holding a search field,
and hears the count of matches as it changes. Its names, its count ("3 of
12", "No matches") and Done are in the window's language
([MyPHP's own strings](../i18n.md#myphps-own-strings)).
