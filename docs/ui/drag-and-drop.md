# Drag and drop

`->draggable($data)` lets a view be dragged, its data a string or a
`Transfer\Data` of several representations, within the window and to
other apps; `->dropDestination(for:, action:)` takes what is dropped on a
view, as SwiftUI's. A `ListView`'s `->onMove()` reorders its rows by
dragging ([list](list.md)).

```php
$app = new class { public array $dropped = []; };
$view = fn() => HStack(spacing: 24)(
    Text('Drag me')->padding()->background(Color::secondarySystemBackground)->draggable('hello'),
    Text('Drop here')->padding()->border(Color::separator)
        ->dropDestination(for: 'text/plain', action: function (array $items) use ($app) { array_push($app->dropped, ...$items); return true; }),
)->padding();
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

`$c->drag($node, $value)` makes a node the source of a value dragged within
the window: once the pointer pressing it moves a few points, a translucent
copy of the node follows the pointer, and the press is no click.
`$c->drop($node, $type, $value)` makes the node take values of `$type`, a
class or interface, or one of PHP's types as `get_debug_type()` names them
(`'string'`, `'int'`), and reports one dropped on it, setting `$value`;
`$c->dragOver($node, $type, $value)` reports one over it, for showing it
would take it:

```php
final class Task { public function __construct(public int $id, public string $name, public bool $done = false) {} }

$app = new class { public array $todo; public function __construct() { $this->todo = [new Task(1, 'Write docs'), new Task(2, 'Run tests')]; } };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    foreach ($app->todo as $task) {
        $row = $c->key($task->id)->row();
          $c->text($task->name);
        $c->end();
        $c->drag($row, $task);
    }

    $done = $c->column(12, grow: 1)->radius(8)->border(1, $t->border);
    $c->end();
    if ($c->dragOver($done, Task::class)) {
        $done->border(2, $t->accent);
    }
    if ($c->drop($done, Task::class, $task)) {
        $task->done = true;
    }
};
```

- **Targets.** The innermost node under the pointer that takes the value's
  type gets it, so a target inside another that takes other types does
  not block it.
- **Controls inside.** A press on a control that only takes clicks, as a
  button, inside a node that drags drags that node once the pointer moves,
  and is no click: a list's rows made of buttons reorder. A press that does
  not move clicks the control. Controls that take the pointer's moves, as
  text inputs and sliders, keep them.
- **Canceling.** Escape gives up a drag.
- **Scrolling.** Scroll containers scroll as a drag nears their top or
  bottom, faster nearer to the edge.
- **Dragging several.** The copy shows how many values a drag holds,
  beside the pointer, as when the rows chosen in a list are dragged
  together.

`$c->dragging($node)` reports that a node is dragging its value, to dim it
where it was. A node that the pointer moves itself, as a window's panel or
a card on a board, is [`$c->draggable()`](motion.md).

## Reordering

Lists, tables and grid views reorder their rows by dragging with
`ListState::$reorder` and `GridState::$reorder`: the rows chosen move
together when the row dragged is one of them, the list shows where they
would go with a line, and the rows go where the pointer let them go.

<!-- no-test -->
```php
$app->list->reorder = function (array $rows, int $to) use ($app) {
    $app->items = move($app->items, $rows, $to); // before row $to, count() for the end
};
```

See [List](list.md#reordering) and [Grid view](grid-view.md).

Files dragged from other apps are [dropped files](input.md#dropped-files).

## Between windows and applications

`$c->dragData($node, $data)` starts a system drag with explicit
representations from `MyPHP\Transfer`. Files, text, URLs, HTML, PNG and
custom serialized formats can leave the app or come from another app.
`$c->dropData()` and `$c->dataDragOver()` declare the formats and
operations a node accepts:

```php
$app = new class { public string $message = ''; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $c->dragData($c->text('Drag me')->padding(12), Data::text('Hello from MyPHP'));

    $zone = $c->column()->size(260, 140)->border(1, $t->border);
    $c->end();
    $options = new DropOptions(formats: [Format::TEXT]);
    if ($c->dataDragOver($zone, $options)) {
        $zone->border(2, $t->accent);
    }
    if ($c->dropData($zone, $options, $drop)) {
        $app->message = $drop->data->read(Format::TEXT);
    }
};
```

The innermost matching enabled target receives the drop once, in the next
frame. At least one advertised format must match, and the source and target
must share an operation. Hover advertises formats without reading bytes;
`dataDragOver()`'s third argument gets the `Offer`. Accepted
representations are copied on drop; the resulting data is safe to keep.

### Representations and lazy providers

An item can offer alternatives to the same content. Custom formats use
MIME names and explicit encodings, such as JSON:

```php
$task = ['id' => 7, 'title' => 'Write docs'];
$view = function (Context $c) use ($task) {
    $taskFormat = 'application/vnd.example.task+json';
    $frozen = $task; // arrays are copies: the value all representations describe
    $data = new Data(new Item(
        Representation::bytes(Format::TEXT, $frozen['title']),
        Representation::lazy($taskFormat, fn() => json_encode($frozen, JSON_THROW_ON_ERROR)),
    ));
    $row = $c->row();
      $c->text($task['title']);
    $c->end();
    $c->dragData($c->drag($row, $task), $data);
};
```

`Representation::bytes()` holds its bytes. `Representation::lazy()`
advertises a format and caches its result or error once per drag session,
when requested by a receiver. Providers run on the main thread and must
return promptly. Prepare expensive data beforehand. External applications
can request data before release.

Use `$c->dragDataFrom($node, fn() => $data)` to snapshot a changing
selection when the gesture starts. Use `Data::url($url)` and
`Data::files(...$absolutePaths)` for URLs and file lists. File paths are
escaped as file URLs; the receiver's `paths()` returns local paths, while
`urls()` returns URI-list entries. `Data::files()` transfers existing
paths; it does not create promised files or stream file contents.

`$c->drag($node, $value)` alone stays within its window. Combining it with
`dragData()` also offers its value to existing `drop()` and `dragOver()`
targets in other windows of the same process. Those typed targets accept
copy. A temporary random session token identifies the value; native
representations hold no PHP value and the token stops resolving when the
source ends. Other processes use the serialized representations and decode
them explicitly.

### Copy, move, completion and cancellation

Sources and destinations default to copy. To allow moves, give both
`operations: Operation::COPY | Operation::MOVE`. The modifier-key
suggestion wins when both ends allow it; otherwise copy is preferred, then
move. An incompatible offer is rejected. Option requests copy and Cmd
requests move on macOS.

```php
$task = ['id' => 7, 'title' => 'Write docs'];
$app = new class {
    public array $errors = [], $removed = [];
    public function report(\Throwable $e): void { $this->errors[] = $e; }
    public function removeOriginal(int $id): void { $this->removed[] = $id; }
};
$view = function (Context $c) use ($app, $task) {
    $row = $c->text($task['title']);
    $c->dragData($row, Data::text($task['title']), new DragOptions(
        operations: Operation::COPY | Operation::MOVE,
        done: function (Result $result) use ($app, $task) {
            if ($result->error !== null) {
                $app->report($result->error);
            } elseif ($result->operation === Operation::MOVE) {
                $app->removeOriginal($task['id']);
            }
        },
    ));
};
```

`done` runs exactly once on the main thread, including cancellation,
native startup failure, source removal and window destruction. Escape
cancels through the system drag tracker. `Window::cancelDataDrag()`
cancels a source owned by that window.

`Result::$operation` is the destination's final effect. `canceled` means
no drop occurred; `escaped` tells a drag the user gave up (Escape,
`cancelDataDrag()` or the window closing) from one let go over no target,
and `point` is where the drag ended, in the screen coordinates of
`Screen::cursorScreenPoint()`. A tab let go outside every window, for
example, is `canceled && !escaped` at `point`. `error` reports a
native or provider failure. MyPHP never deletes files. Remove application
records only after a successful move, and account for file managers that
move a file themselves. Existing `droppedFiles()` and `fileDrop`
listeners keep accepting files with copy semantics.

The default preview is a snapshot of the source's visible node. Set
`DragOptions::$preview` to an `Image` and `hotspot` to its pointer offset
for a custom preview. The image is copied at drag start; one image pixel
represents one point.

### Native format mapping

| Platform | Native transfer | Representations |
|---|---|---|
| macOS | AppKit dragging sessions and pasteboard item providers | Text/HTML/PNG/file URLs use system UTIs. MIME names map through `UTType`, with a reversible identifier fallback. Item boundaries are preserved. |
| Linux | GTK 3 drag contexts and asynchronous selections | MIME targets, UTF8_STRING for text, text/uri-list for URLs and files. Accepted formats are requested after drop. |

GTK has a single selection: multiple text items are joined by newlines,
URI and file lists by CRLF, and other formats use the first item offering
them. Transfers between windows in the same MyPHP process preserve all
items through the core's local registry.

The [drag-drop example](../../examples/drag-drop) has two windows
exchanging notes, files, text and URLs, including a lazy JSON
representation and move completion. `Clipboard::write()` and
`Clipboard::read()` exchange this same `Data`; each clipboard write and
drag gets an independent provider cache. See
[Clipboard and drag data](../data-transfer.md) for clipboard negotiation,
persistence, and provider lifetimes.
