# Badge

`->badge()` shows a short count or word in a pill at the end of a row, as
the unread messages of a mailbox, or on a tab, as SwiftUI's:

```php
$app = new class { public string $box = 'inbox'; };
$view = fn() => ListView()(
    Label('Inbox', systemImage: 'tray')->badge(3),
    Label('Drafts', systemImage: 'doc')->badge('new'),
    Label('Sent', systemImage: 'paperplane'),
);
```

A `Tab`'s badge shows in the sidebar of a `TabView(.sidebarAdaptable)`. The
pill is the text color at a tenth behind the text, two points smaller than
the text.

## Accessibility

Assistive technology reads its text with the row it is on.
