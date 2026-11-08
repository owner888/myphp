# Outline

An outline of many items is a `ListView` whose rows are the items shown —
the branches open and their children — each indented by its depth, with a
chevron that opens or closes it, the app keeping which are open:

```php
$app = new class {
    public array $open = [1 => true];
    public array $tree = [['id' => 1, 'name' => 'Documents', 'kids' => [['id' => 2, 'name' => 'Notes.txt', 'kids' => []]]], ['id' => 3, 'name' => 'Downloads', 'kids' => []]];
    /** The rows shown: each item and its depth, the children of the open ones after them. */
    public function rows(?array $items = null, int $depth = 0): array
    {
        $out = [];
        foreach ($items ?? $this->tree as $it) {
            $out[] = ['id' => $it['id'], 'name' => $it['name'], 'depth' => $depth, 'kids' => $it['kids'] !== []];
            if (($this->open[$it['id']] ?? false) && $it['kids'] !== []) { array_push($out, ...$this->rows($it['kids'], $depth + 1)); }
        }
        return $out;
    }
};
$view = fn() => ListView($app->rows(), id: 'id')(fn(array $r) => HStack(spacing: 6)(
    $r['kids'] ? Button(action: fn() => $app->open[$r['id']] = !($app->open[$r['id']] ?? false), label: Image(systemName: ($app->open[$r['id']] ?? false) ? 'chevron.down' : 'chevron.right'))->buttonStyle(ButtonStyle::plain) : Spacer()->frame(width: 12),
    Text(verbatim: $r['name']),
)->padding(Edge::leading, 16 * $r['depth']));
```

Only the rows in view are built, so it holds as many items as a list. For a
few items, nested [disclosure groups](tree.md).
