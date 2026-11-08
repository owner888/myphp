# Split view

An `HSplitView` puts its views side by side with a divider between each,
which the user drags to resize them, as SwiftUI's on the Mac:

```php
$view = fn() => HSplitView()(
    Text('Files')->frame(minWidth: 120, maxWidth: INF, maxHeight: INF),
    Text('Editor')->frame(minWidth: 200, maxWidth: INF, maxHeight: INF),
);
```

`->frame(minWidth:)` keeps a pane from getting narrower. For a sidebar of
the app's data, a [NavigationSplitView](sidebar.md).

## Accessibility

Assistive technology sees the divider as a splitter, which the arrows move.
