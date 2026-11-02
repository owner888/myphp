# Menus and the tray

Menus are built from templates of `MyPHP\MenuItem` and used as the menu
bar, context menus, the Dock menu and the menus of tray icons. (A view of
native UI builds its own menus with `MyPHP\UI\Menu`: see [context
menus](ui/context-menu.md).)

## Templates

```php
use MyPHP\{App, Menu, MenuItem, Window};

$menu = new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem(label: 'File', submenu: [
        new MenuItem(label: 'New Note', accelerator: 'CmdOrCtrl+N', click: function (MenuItem $item, ?Window $win) {
            newNote();
        }),
        new MenuItem(label: 'Export…', accelerator: 'CmdOrCtrl+E', click: fn() => exportNotes()),
        MenuItem::separator(),
        new MenuItem(role: 'close'),
    ]),
    new MenuItem(role: 'editMenu'),
    new MenuItem(label: 'View', submenu: [
        new MenuItem(id: 'sidebar', label: 'Show Sidebar', type: MenuItem::CHECKBOX, checked: true, click: fn(MenuItem $i) => toggleSidebar($i->isChecked())),
        MenuItem::separator(),
        new MenuItem(label: 'List', type: MenuItem::RADIO, checked: true),
        new MenuItem(label: 'Grid', type: MenuItem::RADIO),
        MenuItem::separator(),
        new MenuItem(role: 'toggleDevTools'),
        new MenuItem(role: 'togglefullscreen'),
    ]),
    new MenuItem(role: 'windowMenu'),
]);
```

A `MenuItem` has:

- `label`, and an `accelerator`, its keyboard shortcut (see
  [accelerators](#accelerators));
- `click`, called on the main thread when the item is chosen, with the
  item and the window it was chosen for, the focused window, or null;
- `type`: `MenuItem::NORMAL`, `CHECKBOX`, `RADIO`, `SEPARATOR` or
  `SUBMENU`; items with a `submenu` are submenus, others normal items
  unless set. Adjacent radio items form a group, in which checking one
  unchecks the others. Checkbox and radio items toggle `checked` before
  `click` runs; `MenuItem::header()` titles the group under it (macOS 14);
- `disabled`, `hidden` and `toolTip`;
- `icon`, an image shown beside the label (see [icons](#icons));
- `id`, to find the item with `Menu::itemById()`;
- `role`, a built-in behavior with its label and accelerator;
- `onOpen`, called as the menu holding the item opens, to change it.

### Roles

Roles do what users expect of standard items, with their usual label and
shortcut, which `label` and `accelerator` override:

- editing: `undo`, `redo`, `cut`, `copy`, `paste`, `delete`, `selectAll`,
  and on macOS `pasteAndMatchStyle`. Their keys go to the content first, as
  in a browser;
- formatting: `bold`, `italic`, `underline`, `strikethrough`, `alignLeft`,
  `alignCenter` and `alignRight`, which format the selection of a [rich
  text editor](ui/rich-text-editor.md) of native UI, or of an editable page
  (`document.execCommand`);
- the page: `reload`, `forceReload`, `toggleDevTools` (in a window of
  native UI, its [inspector](inspector.md)), `resetZoom`, `zoomIn`,
  `zoomOut`;
- the window: `togglefullscreen`, `minimize`, `zoom`, `close`;
- [documents](documents.md): `new`, `open`, `openRecent`, `save`,
  `saveAs`, `duplicate`, `rename`, `moveTo`, `revert`;
- the app: `quit`, and on macOS `about`, `hide`, `hideOthers`, `unhide`,
  `front`, `services`, `startSpeaking`, `stopSpeaking`;
- whole menus: `fileMenu`, `editMenu`, `formatMenu` (Bold, Italic,
  Underline, Strikethrough and the alignments), `viewMenu` and
  `windowMenu`, and on macOS `appMenu` (the menu named after the app),
  `window` (the menu listing the windows) and `help` (the menu with the
  search field).

Items with the roles of macOS are left out on other platforms, so one
template serves every platform.

### Changing items

Change items of a menu in use with their methods, so that the native menu
follows: `setLabel()`, `setEnabled()`, `setVisible()`, `setChecked()`,
`setAccelerator()`, `setIcon()` and `setSubmenu()`:

```php
$menu->itemById('sidebar')->setChecked(false);
```

`Menu::append()` and `Menu::insert()` add items, and `remove(...$ids)`
takes them out.

### Icons

An item's `icon` is an image shown beside its label, as the flag of each
server of a VPN's tray menu. `MenuIcon::of($png)` makes one of a PNG
image, in its own colors, and throws for data that is not a PNG image;
make each once and give it to as many items as show it:

```php
$japan = MenuIcon::of(file_get_contents(__DIR__ . '/flags/jp.png'));
$servers = new Menu([
    new MenuItem(id: 'tokyo', label: 'Tokyo', type: MenuItem::RADIO, checked: true, icon: $japan),
    new MenuItem(id: 'osaka', label: 'Osaka', type: MenuItem::RADIO, icon: $japan),
    new MenuItem(id: 'direct', label: 'Direct', type: MenuItem::RADIO),
]);
```

The image fits a box of 16 by 16 points, keeping its aspect: give it at
least 32 by 32 pixels, so that it stays sharp on high-resolution displays.
A checkbox or radio item shows its check as well. `setIcon()` replaces an
item's icon in menus in use, and null removes it. `MenuIcon::of()`'s
second argument, template, makes a black and transparent image take the
color of the menu's text, as macOS tints its template images. Linux shows
template icons as they are.

## The menu bar

```php
App::setMenu($menu);
```

On macOS the menu bar belongs to the app. Apps get a default one
(`Menu::default()`), with the app, File, Edit, View and Window menus, until
they set their own: keep `appMenu` and `editMenu` in yours, which make
Cmd+Q and copy and paste work.

On Linux, `App::setMenu()` gives its menu to every window as its menu bar.
A window in full screen leaves the whole screen to the page: its menu bar
hides until it leaves full screen.

## Context menus

`Menu::popup($window)` shows a menu at the mouse over a window;
`popupAt($window, $x, $y)` shows it at a position in the window. Show one
from a bound method, which the page calls on right-click:

```php
final class Notes
{
    /** Shows the context menu of a note. */
    public function showNoteMenu(CallContext $ctx, int $id): void
    {
        $menu = new Menu([
            new MenuItem(label: 'Rename', click: fn() => $this->startRename($id)),
            new MenuItem(label: 'Delete', click: fn() => $this->delete($id)),
        ]);
        $menu->popup($ctx->window());
    }
}
```

```ts
note.addEventListener("contextmenu", (e) => {
  e.preventDefault();
  Notes.showNoteMenu(id);
});
```

Without a handler of their own, pages show the webview's standard context
menu, with Copy and Paste on text and Inspect Element in development
builds. Windows showing [native UI](ui/context-menu.md) give nodes context
menus with `contextMenu()`, built like the rest of their interface.

### The webview's menu

The page's `contextMenu` event changes that standard menu as it is about
to open, as Electron's `context-menu` event does. The `ContextMenuEvent`
tells what was right-clicked (`linkURL`, `linkText`, `imageURL`,
`mediaURL`, `selectionText`, `editable`, `frameURL`), and its `menu` holds
the webview's own items. Hide, rename, disable or remove them, and add
items of the app's, which work as in any other menu:

```php
$page->on('contextMenu', function (ContextMenuEvent $e) {
    $e->menu->remove(ContextMenuEvent::RELOAD, ContextMenuEvent::INSPECT);
    if ($e->linkURL !== '') {
        $e->menu->append(MenuItem::separator(), new MenuItem(
            label: 'Open in Browser',
            click: fn() => Shell::openExternal($e->linkURL),
        ));
    }
});
```

`$e->preventDefault()` shows no menu, and setting `$e->menu` to a menu of
the app's shows that one instead. The webview's items keep doing what the
webview does, wherever they are moved, unless the app gives one a
`click`, which then runs in its place. A page whose own `contextmenu`
handler calls `preventDefault` opens no menu, and the listeners are not
called.

The webview's items have the same IDs on every platform that has them,
the constants of `ContextMenuEvent`:

| ID | item |
|---|---|
| `BACK`, `FORWARD`, `RELOAD`, `STOP` | the page's navigation |
| `INSPECT` | Inspect Element, in builds with the web inspector |
| `UNDO`, `REDO`, `CUT`, `COPY`, `PASTE`, `PASTE_AND_MATCH_STYLE`, `DELETE`, `SELECT_ALL` | editing |
| `SPELLING_GUESS` | each word suggested for a misspelled one |
| `OPEN_LINK`, `OPEN_LINK_IN_NEW_WINDOW`, `DOWNLOAD_LINK`, `COPY_LINK` | links |
| `OPEN_IMAGE_IN_NEW_WINDOW`, `DOWNLOAD_IMAGE`, `COPY_IMAGE`, `COPY_IMAGE_URL` | images |
| `OPEN_MEDIA_IN_NEW_WINDOW`, `DOWNLOAD_MEDIA`, `COPY_MEDIA_URL`, `MEDIA_CONTROLS`, `MEDIA_LOOP`, `MEDIA_FULL_SCREEN`, `PICTURE_IN_PICTURE` | video and audio |
| `OPEN_FRAME_IN_NEW_WINDOW`, `LOOK_UP`, `SEARCH_WEB`, `SHARE`, `EMOJI`, `PRINT` | the rest |

The other items keep the platform's own name as their ID: WebKit's
identifier on macOS (`WKMenuItemIdentifierTranslate`), the stock action's
nick on Linux (`unicode`). On macOS WebKit's menu is changed in place as
it opens, so Look Up, Translate, Services and the spelling suggestions keep
working.

## The Dock menu

On macOS `Dock::setMenu()` adds items above the standard ones of the menu
that right-clicking the Dock icon opens. Like `App::setMenu()`, it may be
called before `App::run()`:

```php
Dock::setMenu(new Menu([
    new MenuItem(label: 'New Window', click: fn() => openMainWindow()),
]));
```

## Tray icons

A tray icon sits in the menu bar on macOS and in the notification area on
Linux. Create one once the app is ready:

```php
App::whenReady(function () {
    $tray = new Tray(new TrayOptions(
        icon: file_get_contents(__DIR__ . '/resources/tray.png'),
        iconIsTemplate: true, // macOS tints it to match the menu bar
        toolTip: 'Notes',
        menu: new Menu([
            new MenuItem(label: 'Open Notes', click: fn() => openMainWindow()),
            MenuItem::separator(),
            new MenuItem(role: 'quit'),
        ]),
    ));
});
```

The icon is a PNG, ideally 16×16 points (a 32×32 image for high-resolution
displays); a template icon, black on transparent, lets macOS tint it for
light and dark menu bars. A tray with a menu shows it on click. Without a
menu, clicks reach `onClick()`, and `onRightClick()` gets right-clicks,
and on macOS Control-clicks. Change the tray with `setIcon()`,
`setMenu()`, `setToolTip()` and, on macOS, `setTitle()`, the text next to
the icon; `popUpMenu()` shows its menu, and `destroy()` removes it.

A title that shows a changing number, such as a network speed, should keep
its width, or the item grows and shrinks and the icon and the items beside
it move. On macOS the title's digits all have the same width, so a value
padded to a fixed number of characters keeps it: pad with figure spaces
(U+2007), which are as wide as a digit.

A [flyout](windows.md#flyouts) can hang from a tray icon, as the panel of a
menu bar app.

## Accelerators

Accelerators are keys with modifiers joined by `+`, such as `CmdOrCtrl+N`
or `Alt+Shift+F4`, in any case.

Modifiers:

| Modifier | Key |
|---|---|
| `CmdOrCtrl` (`CommandOrControl`) | Command on macOS, Control elsewhere |
| `Cmd` (`Command`), `Super`, `Meta` | Command on macOS, the Super key elsewhere |
| `Ctrl` (`Control`) | Control |
| `Alt` (`Option`) | Alt, or Option on macOS |
| `Shift` | Shift |

Keys:

- a character: a letter, a digit or a sign such as `,` or `/` (`Plus` for
  `+`);
- `F1` to `F24`;
- `Enter` (`Return`), `Tab`, `Space`, `Backspace`, `Delete` (`Del`),
  `Insert` (`Ins`), `Escape` (`Esc`);
- `Up`, `Down`, `Left`, `Right`, `Home`, `End`, `PageUp`, `PageDown`.

The same syntax registers [global shortcuts](native.md#global-shortcuts).
