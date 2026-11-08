# Back and forward

A `NavigationStack` has its way back built in: on a phone the back button
of its bar and the swipe from the screen's start edge, on the desktop Back
in its toolbar, ⌘[ and the back button of a mouse. Code goes back with
`$app->path->back()` and forward with `->forward()`, `$app->path` the
stack's `Router` ([navigation](navigation.md)).

```php
$app = new class {
    public Router $path;
    public function __construct() { $this->path = new Router('/'); }
};
$view = fn() => NavigationStack(path: $app->path)(
    ListView()(NavigationLink('Note', value: '/note'))->navigationTitle('Notes')
        ->toolbar(Button('Back', action: fn() => $app->path->back(), systemImage: 'chevron.left')->disabled(!$app->path->canGoBack())),
)->navigationDestination(for: '/note', destination: fn() => Text('A note')->navigationTitle('Note'));
```
