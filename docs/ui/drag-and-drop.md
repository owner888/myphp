# Drag and drop

`->draggable($data)` lets any view be dragged, carrying a string, a `Transfer\Data` with multiple representations, or an in-app PHP value (`value: $item`); `->dropDestination(for:, action:, isTargeted:)` receives what is dropped on a view, as SwiftUI's. A `ListView`'s `->onMove()` reorders its rows by dragging ([list](list.md)).

```php
$app = new class {
    public array $dropped = [];
    public bool $targeted = false;
};
$view = fn() => HStack(spacing: 24)(
    Text('Drag me')
        ->padding()
        ->background(Color::secondarySystemBackground)
        ->cornerRadius(8)
        ->draggable('hello'),
    Text($app->targeted ? 'Drop now!' : 'Drop here')
        ->padding()
        ->border($app->targeted ? Color::accentColor : Color::separator, width: $app->targeted ? 2 : 1)
        ->dropDestination(
            for: 'text/plain',
            action: function (array $items) use ($app) { array_push($app->dropped, ...$items); return true; },
            isTargeted: fn(bool $over) => $app->targeted = $over,
        ),
)->padding();
```

## Dragging in-app objects

Pass `value: $object` to `->draggable()` and the class name to `->dropDestination(for: MyClass::class, action: fn(MyClass $item) => …)` to drag typed PHP objects directly between views:

```php
final class TaskItem { public function __construct(public string $title, public bool $done = false) {} }

$app = new class {
    public array $tasks = [];
    public function __construct() { $this->tasks = [new TaskItem('Write docs'), new TaskItem('Run tests')]; }
};
$view = fn() => HStack(spacing: 16)(
    VStack(spacing: 6)(
        Each($app->tasks, id: 'title')(fn(TaskItem $t) => Text(verbatim: $t->title)->padding(8)->draggable($t->title, value: $t)),
    ),
    VStack()(Text('Done'))
        ->frame(width: 160, height: 100)
        ->border(Color::separator)
        ->dropDestination(for: TaskItem::class, action: function (TaskItem $t) { $t->done = true; }),
)->padding();
```

## Between windows and applications

Use `MyPHP\Transfer\{Data, Drop, DropOptions, Format, Item, Representation}` to exchange files (`Format::FILE_LIST`), URLs (`Format::URL`), plain text (`Format::TEXT`), HTML, PNG images, or custom MIME types with other desktop and mobile apps (see [`examples/transfer`](../../examples/transfer) and [`examples/drag-drop`](../../examples/drag-drop)).
