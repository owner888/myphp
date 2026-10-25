<?php
// A small but complete MyPHP app (mygo's examples/todo): a typed service and
// an event shared with a TypeScript frontend, persistence, dialogs, menus
// and several windows kept in sync. In examples/todo:
//
//   bun install && bun run dev    # live reload with the Vite dev server
//   bun run build                 # the app in dist/
declare(strict_types=1);

use MyPHP\{App, CallContext, Dialog, Event, FileFilter, Ipc, Menu, MenuItem, PathName, SaveDialogOptions, Window, WindowOptions};

// MyPHP from inside the built app, else from this checkout.
require_once is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp/src/autoload.php' : __DIR__ . '/../../src/autoload.php';

/** An item of the list. */
final class Todo
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $done,
        /** RFC 3339. */
        public string $createdAt,
    ) {}
}

/** Which todos are listed: the client types it as the union of its values. */
enum Filter: string
{
    case All = 'all';
    case Active = 'active';
    case Done = 'done';
}

/** Counts of the list, for the footer. */
final class Stats
{
    public function __construct(public int $total, public int $active) {}
}

/** The list, kept as JSON in the user data directory; its public methods are callable from the frontend. */
final class Todos
{
    /** Sent to every window whenever the list changes. */
    public static Event $changed;
    /** @var list<Todo> */
    private array $items = [];
    private int $next = 0;

    public function __construct(private readonly string $path)
    {
        if (is_file($path)) {
            foreach (json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) as $it) {
                $this->items[] = new Todo((int)$it['id'], (string)$it['title'], (bool)$it['done'], (string)$it['createdAt']);
                $this->next = max($this->next, (int)$it['id']);
            }
        }
    }

    /** The todos matching $filter, newest first. @return list<Todo> */
    public function list(Filter $filter): array
    {
        $out = [];
        foreach (array_reverse($this->items) as $it) {
            if ($filter === Filter::All || ($filter === Filter::Done) === $it->done) { $out[] = $it; }
        }
        return $out;
    }

    /** Creates a todo. */
    public function add(string $title): Todo
    {
        $title = trim($title);
        if ($title === '') { throw new InvalidArgumentException('a todo needs a title'); }
        $this->items[] = $todo = new Todo(++$this->next, $title, false, date(DATE_RFC3339));
        $this->save();
        return $todo;
    }

    /** Marks a todo done or not done. */
    public function toggle(int $id): void
    {
        $this->item($id)->done = !$this->item($id)->done;
        $this->save();
    }

    /** Deletes a todo. */
    public function remove(int $id): void
    {
        $this->item($id);
        $this->items = array_values(array_filter($this->items, fn(Todo $t) => $t->id !== $id));
        $this->save();
    }

    /** Removes the completed todos, and returns how many. */
    public function clearDone(): int
    {
        $before = count($this->items);
        $this->items = array_values(array_filter($this->items, fn(Todo $t) => !$t->done));
        $this->save();
        return $before - count($this->items);
    }

    /** The counts of the footer. */
    public function stats(): Stats
    {
        return new Stats(count($this->items), count(array_filter($this->items, fn(Todo $t) => !$t->done)));
    }

    /** Asks where to save the list, as a sheet of the window calling, and writes it as JSON: the path chosen, or '' when the dialog was canceled. */
    public function export(CallContext $ctx): string
    {
        return $this->exportFrom($ctx->window());
    }

    public function exportFrom(?Window $parent): string
    {
        $path = Dialog::save(new SaveDialogOptions(parent: $parent, title: 'Export Todos', defaultPath: 'todos.json', filters: [new FileFilter('JSON', ['json'])]));
        if ($path !== '') { file_put_contents($path, json_encode($this->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
        return $path;
    }

    private function item(int $id): Todo
    {
        foreach ($this->items as $t) { if ($t->id === $id) { return $t; } }
        throw new RuntimeException('todo not found');
    }

    /** Writes the list and tells every window of the change. */
    private function save(): void
    {
        @mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, json_encode($this->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::$changed->broadcast($this->stats());
    }
}

function openWindow(): void
{
    new Window(new WindowOptions(title: 'Todo', width: 460, height: 640, minWidth: 360, minHeight: 420, titleBarStyle: 'hidden',
        backgroundColor: 'light-dark(#f5f5f7, #1c1c1e)', // the page's --bg
        url: '/')); // the frontend: devUrl in development, frontendDist once built
}

// The name first: it names the user data directory.
App::setName('Todo');
Todos::$changed = new Event('todos:changed', Stats::class);
$store = new Todos(App::path(PathName::UserData) . '/todos.json');
Ipc::bind($store);
App::whenReady(function () use ($store) {
    App::setMenu(new Menu([
        new MenuItem(role: 'appMenu'),
        new MenuItem('File', submenu: [
            new MenuItem('New Window', fn() => openWindow(), accelerator: 'CmdOrCtrl+N'),
            new MenuItem('Export…', fn(MenuItem $i, ?Window $w) => $store->exportFrom($w), accelerator: 'CmdOrCtrl+E'),
            MenuItem::separator(),
            new MenuItem(role: 'close'),
        ]),
        new MenuItem(role: 'editMenu'),
        new MenuItem(role: 'viewMenu'),
        new MenuItem(role: 'windowMenu'),
    ]));
    openWindow();
});
// Kept alive in the Dock, and a window opened again when clicked.
App::onWindowAllClosed(function () {});
App::onActivate(function (bool $hasVisibleWindows) { if (!$hasVisibleWindows) { openWindow(); } });
App::run();
