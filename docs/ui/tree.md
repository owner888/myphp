# Tree

Nested items that open and close are `DisclosureGroup`s inside one another,
each a branch, its leaves the views inside it, as SwiftUI's outline of a
few items:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    DisclosureGroup('Documents')(
        Label('Notes.txt', systemImage: 'doc'),
        DisclosureGroup('Photos')(Label('Beach.jpg', systemImage: 'photo')),
    ),
    Label('Downloads', systemImage: 'arrow.down.circle'),
)->padding();
```

Each keeps whether it is open itself, or binds it with `isExpanded:`. For
thousands of items, an [outline](outline.md).

## Accessibility

Assistive technology sees each branch as a button, expanded or collapsed,
and the leaves under it.
