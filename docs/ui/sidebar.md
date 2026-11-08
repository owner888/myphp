# Sidebar

A `NavigationSplitView` is a sidebar beside the view it chooses, as Notes'
and Mail's, and SwiftUI's on the Mac: its sidebar a `ListView` with a
`selection:`, its detail what the selection shows. A button by the window's
controls hides the sidebar and shows it again.

```php
$app = new class { public ?int $chosen = 1; public array $notes = [1 => 'Groceries', 2 => 'Ideas']; };
$view = fn() => NavigationSplitView(
    ListView(selection: $app->chosen)(Each($app->notes)(fn(string $title, int $id) => Label($title, systemImage: 'note.text')->tag($id))),
    $app->chosen === null ? Text('No note')->foregroundStyle(Color::secondary) : Text(verbatim: $app->notes[$app->chosen])->navigationTitle($app->notes[$app->chosen]),
);
```

The sidebar takes the window's sidebar material with `vibrancy: 'sidebar'`
and `titleBarStyle: 'hiddenInset'` on the window. `Section`s in it are its
groups, their headers muted. It is for a sidebar of the app's data; an
app's top-level pages are a `TabView(.sidebarAdaptable)` ([tabs](tabs.md)),
which a phone shows as its tab bar.

## Accessibility

Assistive technology sees the sidebar as a list, its rows by their titles,
the chosen one selected.
