# Link

A `WebLink` shows a text that opens a URL in the browser, or the app
registered for its scheme, when clicked, or with Enter while it has the
keyboard focus (SwiftUI's `Link`; `link` is a PHP built-in). It shows in the
accent color, underlined while the pointer is over it:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    Text('MyPHP draws its own controls.'),
    WebLink('Read the documentation', destination: 'https://github.com/owner888/myphp'),
)->padding();
```

## Pages of a NavigationStack

A page of the app is a `NavigationLink('Note', value: '/notes/42')`, which
pushes it on the [NavigationStack](navigation.md) around it.

## Other actions

A link that does something in the app rather than opening a URL is a
`Button` with `->buttonStyle(ButtonStyle::borderless)`.

## Accessibility

Assistive technology sees a link named by its text, which it follows as a
click does.
