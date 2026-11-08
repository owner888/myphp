# Grid

A `LazyVGrid` lays its views out in columns, row by row, as SwiftUI's: with
`GridItem::adaptive(minimum:)`, as many columns as fit cells that wide at
least, sharing the width left over, `spacing:` apart.

```php
$view = fn() => LazyVGrid(columns: [GridItem::adaptive(minimum: 100)], spacing: 12)(
    Each(range(1, 9))(fn(int $i) => VStack()(
        Image(systemName: 'folder')->font(Font::largeTitle),
        Text(verbatim: "Folder $i"),
    )->padding()->background(Color::secondarySystemBackground)->cornerRadius(8)),
)->padding();
```

For a dashboard of a few fixed cells, stacks: an `HStack` of `VStack`s, each
`->frame(maxWidth: INF)` to share the width. Thousands of items scroll as a
[grid view](grid-view.md).
