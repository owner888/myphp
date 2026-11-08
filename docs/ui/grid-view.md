# Grid view

A `LazyVGrid` of one `Each` given a `selection:` is an icon view, as
Finder's and Photos': only the rows in view are built, it scrolls, a click
chooses an item (⌘- and Shift-click several, into an array), the arrows
move the choice, and `->contextMenu(primaryAction:)` runs on a double click
or Return.

```php
$app = new class {
    public ?int $chosen = null; public array $photos;
    public function __construct() { $this->photos = array_map(fn($i) => ['id' => $i, 'title' => "IMG_$i"], range(1, 5000)); }
};
$view = fn() => LazyVGrid(columns: [GridItem::adaptive(minimum: 120)], spacing: 8, selection: $app->chosen)(
    Each($app->photos, id: 'id')(fn(array $p) => VStack()(
        RoundedRectangle(cornerRadius: 6)->fill(Color::systemGray5)->frame(height: 90),
        Text(verbatim: $p['title'])->font(Font::caption),
    )),
);
```

## Accessibility

Assistive technology sees a grid of the items, the chosen ones selected.
