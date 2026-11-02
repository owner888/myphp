# Tree

`$c->tree()` creates a tree whose items `$c->treeItem()` builds, each
inside the item it belongs to. An item's children show while its `bool`
(by reference) is true; a click on its arrow opens or closes it, and
`$c->clicked()` reports a click elsewhere on it, or Enter, for choosing it,
which its `$selected` argument shows.

```php
$app = new class { public string $chosen = ''; public ?bool $srcOpen = true; };
$view = function (Context $c) use ($app) {
    $item = function (string $path, string $label, ?bool &$open, ?Closure $children) use ($c, $app) {
        if ($c->clicked($c->treeItem($label, $open, $children, $app->chosen === $path))) {
            $app->chosen = $path;
        }
    };
    $leaf = null;
    $c->tree(function () use ($item, $app, &$leaf) {
        $item('src', 'src', $app->srcOpen, function () use ($item, &$leaf) {
            $item('src/main.php', 'main.php', $leaf, null);
        });
        $item('composer.json', 'composer.json', $leaf, null);
    });
};
```

Null children, or a null open, makes a leaf.

## Keyboard

While an item has the keyboard focus, Up and Down move it to the items
around, Right opens the item or moves to its first child, and Left closes
it or moves to its parent.

A tree builds every item it shows. For trees of thousands of items, or with
columns, use an [outline](outline.md), which builds only the rows in view.

## Accessibility

Assistive technology sees a tree of items, each with its level, open or
closed when it has children.
