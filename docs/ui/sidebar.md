# Sidebar

`$c->sidebar()` is a source list, as the sidebars of Finder and Mail:
items with icons, in sections whose titles hide and show them, choosing
the ID of one into a string (by reference). It scrolls when they do not
fit. Its third argument is its width, 200 points by default:

```php
$app = new class { public string $mailbox = 'inbox'; public ?bool $mailboxesShown = true; public int $unread = 3; };
$view = function (Context $c) use ($app) {
    $c->sidebar($app->mailbox, function () use ($c, $app) {
        $c->sidebarSection('Mailboxes', $app->mailboxesShown, function () use ($c, $app) {
            $c->sidebarItem('inbox', 'Inbox', '📥', fn() => $c->badge((string)$app->unread));
            $c->sidebarItem('sent', 'Sent', '📤');
        });
        $open = null; // always open
        $c->sidebarSection('Tags', $open, function () use ($c) {
            $c->sidebarItem('work', 'Work');
        });
    }, 220);
};
```

- `$c->sidebarItem()` chooses its ID, showing its label after its icon: an
  `SVG` in the accent, a glyph, or null for none; its fourth argument
  builds what sits at its end, as a [badge](badge.md).
- `$c->sidebarSection()` holds the items its function builds under a title
  while its `bool` is true: a click on the title shows and hides them, as
  does its arrow, which shows as the pointer rests on the title. A null
  keeps the section open.

## Choosing

A click chooses an item; while the sidebar has the focus, Up, Down, Home,
End and the first letters of an item choose among them, and Tab leaves it,
as it is one stop. `$c->changed()` reports a new choice. The item chosen
shows in the accent color while the sidebar has the focus, in gray
otherwise, as AppKit's.

With a [router](navigation.md), a `Router` in your state, the items' IDs
are paths, and a new choice pushes its page:

```php
$router = new Router('/notes');
$view = function (Context $c) use ($router) {
    $page = $router->path();
    $bar = $c->sidebar($page, function () use ($c) {
        $c->sidebarItem('/notes', 'Notes');
        $c->sidebarItem('/files', 'Files');
    }, 220);
    if ($c->changed($bar)) {
        $router->push($page);
    }
};
```

## Accessibility

Assistive technology sees a tree: the sections' titles are items that open
and close, holding their items, and the item chosen has the focus while
the sidebar does.
