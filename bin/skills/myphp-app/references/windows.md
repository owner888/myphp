# Several windows

Read `docs/windows.md` (options, events, child windows, panels) and
`docs/menus.md` with this.

## Who owns the windows

Keep one controller object that opens and remembers the windows, apart
from the views: editors in an array keyed by document id, a `?Window` for
each window there is one of (preferences, about). Open them through a
closure the controller is given, so a headless test can pass one that
returns a fake and check which windows the app asked for.

```php
final class Windows
{
    /** @var array<int, Window> the editors, by document id */
    private array $editors = [];
    private ?Window $prefs = null;

    /** @param \Closure(WindowOptions): Window $open */
    public function __construct(private \Closure $open, private Library $lib) {}

    public function edit(int $id): void
    {
        if (isset($this->editors[$id])) { $this->editors[$id]->focus(); return; }
        $doc = $this->lib->open($id);
        $win = ($this->open)(new WindowOptions(title: $doc->title, width: 640, height: 480,
            content: Content::view(fn(Context $c) => $doc->view($c))));
        $win->on('close', function (CloseEvent $e) use ($doc) {
            if ($doc->dirty() && !$doc->mayClose) { $e->cancel(); $doc->askToSave = true; }
        });
        $win->on('closed', function () use ($id) { unset($this->editors[$id]); });
        $this->editors[$id] = $win;
    }

    public function preferences(): void
    {
        if ($this->prefs !== null) { $this->prefs->focus(); return; }
        $this->prefs = ($this->open)(new WindowOptions(title: 'Preferences', width: 420, height: 300,
            content: Content::view(fn(Context $c) => $this->lib->settingsView($c))));
        $this->prefs->on('closed', function () { $this->prefs = null; });
    }
}
```

In `app.php`: `new Windows(fn(WindowOptions $o) => new Window($o), $lib)`.

## Asking before a window closes

The `close` event comes before the window goes; `$e->cancel()` keeps it.
Show the question in that window, as `$c->alertDialog($open, $title,
$message, 'Save', "Don't Save", 'Cancel')`, which returns the button
chosen; on Save or Don't Save set `mayClose` and call `$win->close()`
again (or `destroy()`, which closes without asking).

## Settings every window shows

Change the shared state, then `invalidate()` each open window, which draws
it again. The appearance of every window follows `Theme::setSource(
ThemeSource::Light | Dark | System)`; `App::setTheme($theme)` sets the
colors and sizes themselves for all windows (`docs/ui/styling.md`).

## The menu bar

`App::setMenu(new Menu([...]))` sets the app's menu (on Linux each
window's menu bar shows it). Items take an `accelerator` such as
`'CmdOrCtrl+N'` and a `click` closure, which gets the item and the
focused window. Give a submenu that changes (Open Recent) an `id`, and
rebuild it with `$menu->itemById('recent')->setSubmenu([...])`.

## Checking it

- Headless: test the controller with fake windows (which windows open,
  which get the focus, what closing a dirty one does), and each window's
  view with `Headless` as usual.
- `myphp screenshot` captures the focused window, or the one `-window N`
  names (IDs in the order the windows opened, as the automation plugin's
  `windows` lists them). To capture an editor, open it at launch when an
  environment variable your `app.php` reads asks for it.
- End to end: run the app with `MYPHP_AUTOMATION=/tmp/x.sock` and drive it
  through `docs/automation.md`'s requests (`menu`, `act`, `input`,
  `idle`, `capture`).
