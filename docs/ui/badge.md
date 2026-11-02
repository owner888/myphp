# Badge

`$c->badge()` creates a short text in a pill, as a count of unread messages
beside a [sidebar](sidebar.md)'s item, which builds it in its trailing
closure:

```php
$app = new class { public string $section = 'inbox'; public int $unread = 4; };
$view = function (Context $c) use ($app) {
    $c->sidebar($app->section, function () use ($c, $app) {
        $c->sidebarItem('inbox', 'Inbox', null, fn() => $c->badge((string)$app->unread));
        $c->sidebarItem('sent', 'Sent');
    });
};
```

The pill is the text color at a tenth behind the text, two points smaller
than the theme's font size.

## Accessibility

Assistive technology reads its text with the item it is in.
