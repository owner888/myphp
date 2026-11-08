# Find bar

`->findNavigator()` shows a bar for finding text over a view while a `bool`
(bound by reference) is true, as SwiftUI's on a text editor and Safari's
and Xcode's: a field of the query, which takes the focus as the bar opens
with the last query selected, how many matches the app found, previous and
next buttons stepping the current match (an `int`, bound), and Done.
⌘F opens it.

```php
$app = new class {
    public bool $finding = true; public string $query = 'the'; public int $current = 0;
    public string $text = 'the cat sat on the mat by the door';
    public function matches(): int { return $this->query === '' ? 0 : substr_count($this->text, $this->query); }
};
$view = fn() => ScrollView()(Text(verbatim: $app->text)->padding())
    ->findNavigator(isPresented: $app->finding, text: $app->query, matches: $app->matches(), current: $app->current);
```

The app finds: it counts the matches of the query and shows the current
one, which `->onChange(of: $app->current)` follows.

## Keyboard

Enter in the field goes to the next match, Shift+Enter to the previous, as
do ⌘G and Shift+⌘G, round the ends; Escape and Done close the bar.

## Accessibility

Assistive technology sees a toolbar named "Find", holding a search field,
the count and the buttons.
