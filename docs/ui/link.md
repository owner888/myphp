# Link

`$c->link()` creates a text that opens a URL in the browser, or the app
registered for its scheme, when clicked, or with Enter while it has the
keyboard focus. It shows in the accent color, underlined while the pointer
is over it:

```php
$c->link('Privacy policy', 'https://example.com/privacy');
```

## Paths in a router

In a page of a [router](navigation.md), a path without a scheme, as
`/notes/42`, `edit` or `?tab=info`, goes there in the router, as `push()`
does, relative to the page shown as links in a web page are:

```php
$id = 41;
$c->link('Next note', (string)($id + 1));
```

## Within a sentence

Inside a [rich text](text.md#elements-within-a-sentence), a link is part of
the paragraph, wrapping with it; Tab reaches it all the same:

```php
$c->richText([])->children(function () use ($c) {
    $c->text('Read ');
    $c->link('the guide', 'https://example.com/guide');
    $c->text(' to get started.');
});
```

## Other actions

A link that does something in the app rather than opening a URL is a text
that is clicked: give `link()` an empty URL and ask `$c->clicked()`, or use
a [button](button.md).

```php
$app = new class { public bool $details = false; };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->link('Show details', ''))) {
        $app->details = true;
    }
};
```

## Accessibility

Assistive technology sees a link named by its text, which it follows as a
click does.
