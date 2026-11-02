# Breadcrumbs

`$c->breadcrumbs()` creates a path of items, as Finder's path bar and
AppKit's path control, separated by chevrons: the items but the last are
links, which a click chooses, as Enter does, setting an `int` (by
reference) to its index, which `$c->changed()` reports. Long items shorten
with an ellipsis as room runs short.

```php
$app = new class {
    public int $chosen = 3;
    public array $opened = [];
    public function open(array $path): void { $this->opened = $path; }
};
$view = function (Context $c) use ($app) {
    $path = ['Macintosh HD', 'Users', 'ada', 'Documents'];
    $crumbs = $c->breadcrumbs($path, $app->chosen)->label('Path');
    if ($c->changed($crumbs)) {
        $app->open(array_slice($path, 0, $app->chosen + 1));
    }
};
```

The last item is where the user is, which is no link.

## Accessibility

Assistive technology sees links in a group, which `label()` names.
