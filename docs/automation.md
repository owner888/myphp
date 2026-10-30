# Automation

The automation plugin (`src/Plugins/Automation`, mygo's
`plugins/automation`) lets another process drive a running app: an
end-to-end test runner or an agent lists its windows, reads what their
native UI shows as elements, acts on them, sends pointer, wheel, key and
text input, chooses items of the application menu, evaluates scripts in
pages, captures windows as PNG images, and waits for them to settle. It
speaks newline-delimited JSON over a Unix domain socket.

Elements are the ones myphp gives assistive technology, so a client sees
what VoiceOver sees and acts as it does, with the runtime's IDs and the
keys the app gave nodes with `Context::key`. The headless harness of
`tests/` remains the way to test a view inside the test suite; this
plugin is for the running app, from outside.

## Turning it on

An app adds the plugin, in the runs it is driven in only:

```php
use MyPHP\Plugins\Automation\Automation;

if (getenv('MYPHP_AUTOMATION') !== false) {
    App::use(Automation::plugin());
}
```

```sh
MYPHP_AUTOMATION=/tmp/app.sock php app.php
```

It listens only when `MYPHP_AUTOMATION` names the path of the socket, and
does nothing otherwise. A socket left at the path by an app that ended
without removing it is replaced; one another app listens on is not, nor a
file that is no socket (the app says so on its standard error and runs
without automation). The app removes its socket when it quits.

## The protocol

A client connects to the socket and writes requests, one JSON object per
line. The app answers each on a line of its own, in order, each once the
one before it is answered. A connection waiting for `idle` holds its own
requests, not other connections': clients that want requests to run side
by side, as `idle` with one window while another acts, open more
connections.

```json
{"id": 1, "method": "tree", "params": {"window": 1}}
```

`id` is any JSON value, which the answer repeats. An answer has a
`result`, or an `error` with a `code` to test and a `message` to read:

```json
{"id": 1, "result": {"window": 1, "nodes": [...]}}
{"id": 2, "error": {"code": "no_window", "message": "no window 7"}}
```

| Code | Meaning |
| --- | --- |
| `parse_error` | The line is not a JSON object (its `id` is then `null`), or it is longer than 16 MiB. |
| `invalid_request` | No method, params the method does not take, or values it cannot use. |
| `unknown_method` | No such method. |
| `no_window` | No window has the ID, or the app has none. |
| `no_element` | No element of the window's last frame has the ID or the key. |
| `unsupported` | The window cannot do it, as reading the elements of a page. |
| `timeout` | `idle` gave up after its `timeout`. |
| `failed` | The app could not do it, as a script that threw. |

Every method but `windows` takes a `window`, the ID `windows` lists; left
out, it is the focused window, else the first. Coordinates and sizes are
in points, relative to the window's content, as the elements' bounds are.

| Method | Params | Result |
| --- | --- | --- |
| `windows` | | `{windows: [{id, title, kind, url?, bounds, contentBounds, focused, visible}]}` |
| `tree` | `window` | `{window, focus?, nodes: [...], announcements?}` |
| `act` | `window`, `id` or `key`, `action`, `text?`, `from?`, `to?` | `{}` |
| `input` | `window`, `events` | `{}` |
| `menu` | `window`, `path` | `{}` |
| `capture` | `window` | `{png, width, height}` |
| `eval` | `window`, `script` | `{value}` |
| `idle` | `window`, `timeout?` | `{}` |

### windows

Lists the open windows. `kind` is `content` for a window of native UI and
`page` for one showing a web page, whose `url` it gives; `bounds` is the
window's frame on screen and `contentBounds` its content's, each `{x, y,
width, height}`.

### tree

The elements of the window's last frame, in tree order, a parent before
its children:

```json
{"id": 2, "method": "tree", "params": {"window": 1}}
{"id": 2, "result": {"window": 1, "focus": "5878896818624359798", "nodes": [
  {"id": "15956256791619451359", "role": "text", "label": "Count: 0", "bounds": {"x": 20, "y": 20, "width": 360, "height": 18}},
  {"id": "5424175759328386547", "role": "button", "key": "add", "label": "Add", "bounds": {"x": 20, "y": 48, "width": 360, "height": 32}, "states": ["focusable"], "actions": ["press", "focus"]}
]}}
```

A node has its `id`, the `parent`'s ID unless it is at the top, its
`role`, and those of `key`, `label`, `value`, `placeholder`,
`description`, `bounds`, `states`, `actions`, `min`, `max`, `now` and
`step` (of sliders, progress bars, meters and steppers), `selection` (of
text fields and comboboxes, in characters), `level`, `posInSet` and
`setSize` that it has. IDs are strings of digits: they are 64-bit, which
JavaScript's numbers would round.

`key` is the key the app gave the node with `Context::key`, a string or
an integer. Unlike an ID, which a frame may change as the view changes,
it is the app's own name for the element, made to last; give the
elements tests look for keys of their own:

```php
if ($c->clicked($c->key('add')->button('Add'))) { $count++; }
$c->key('name')->input($name);
```

Only the elements assistive technology reads are in the tree: a row or a
column that lays others out has no node, keyed or not. Roles are those of
`UI\Role` in lowerCamel (`group`, `text`, `button`, … `heading`), states
those of `AccessNode` (`focusable`, `disabled`, `checked`, … `vertical`).
A window showing a web page has no tree: `tree` answers `unsupported`,
and clients read the page with `eval`.

### act

Performs an action on an element, as assistive technology does: the
element is found by its `id`, or by its `key` when exactly one element
has it.

```json
{"id": 3, "method": "act", "params": {"window": 1, "key": "add", "action": "press"}}
{"id": 4, "method": "act", "params": {"window": 1, "key": "name", "action": "setValue", "text": "Grace"}}
```

| Action | Does |
| --- | --- |
| `press` | Clicks a button, checks a check box, opens a menu button. |
| `focus` | Gives the element the keyboard focus. |
| `increment`, `decrement` | Steps a slider or a stepper. |
| `setValue` | Replaces a text field's text with `text`. |
| `insertText` | Types `text` at a text field's caret. |
| `selectText` | Selects characters `from` to `to` of a text field. |
| `scrollIntoView` | Scrolls the containers around the element to show it. |
| `expand`, `collapse` | Opens or closes an item of a tree. |

### input

Sends events to the window's content through the path the system's take,
in order:

```json
{"id": 5, "method": "input", "params": {"window": 1, "events": [
  {"type": "click", "x": 200, "y": 99},
  {"type": "text", "text": "Grace"},
  {"type": "key", "key": "CmdOrCtrl+A"}
]}}
```

| Type | Fields |
| --- | --- |
| `pointerMove` | `x`, `y`, `mods?`: a drag while a button is down |
| `pointerDown`, `pointerUp` | `x`, `y`, `button?`, `clicks?`, `mods?` |
| `click` | `x`, `y`, `button?`, `clicks?`, `mods?`: a move, then a press and a release for each click |
| `wheel` | `x`, `y`, `dx`, `dy`, `precise?`, `mods?`: scrolls by `dx`, `dy` points as a touchpad when `precise`, else by as many lines as a mouse wheel's |
| `keyDown`, `keyUp`, `key` | `key`: a key and its modifiers as menu accelerators write them, as `Enter`, `Shift+Tab` or `CmdOrCtrl+Z`; `key` presses and releases it |
| `text` | `text`: text typed, or committed by an input method |

`button` is `left` (the default), `right` or `middle`; `clicks` counts
the presses of a double or triple click; `mods` lists `shift`, `ctrl`,
`alt`, `cmd` and `cmdOrCtrl` (Command). A key event types no text: send
the text with a `text` event. Keys go through the text system as the
keyboard's do, so Backspace, the arrows, Enter and the bindings with
Control or Command edit the focused field.

The content takes the events of a request one after the other, before it
draws its next frame, as it would events that came together. When an
event depends on what an earlier one showed, as typing into a field a
click focused, wait with `idle` in between. Input goes to windows of
native UI; pages answer `unsupported`, and clients drive them with
`eval`.

### menu

Chooses an item of the application menu by the titles along its path, as
the menu bar does, and answers once its action returned:

```json
{"id": 6, "method": "menu", "params": {"path": ["File", "New Window"]}}
```

The menus of the Dock and of tray icons, and open context menus, are
left out: the system shows a context menu in a loop of its own, so don't
open one with a right click through `input`.

### capture

The window as a PNG image, in base64, `width` × `height` pixels: native
UI as its last frame drew it, or a page's snapshot.

### eval

Evaluates JavaScript in the page of a window showing one, as
`Page::eval` does: an expression's value, with promises awaited, or what
statements `return`.

```json
{"id": 7, "method": "eval", "params": {"window": 2, "script": "document.title"}}
{"id": 7, "result": {"value": "Todo"}}
```

### idle

Answers once the window settled, so that clients wait for the UI rather
than sleep: for native UI, once the content asks for no frame it has not
drawn; for a page, once it has loaded and answered a script. It waits for
nothing `Context::after` asked for later. Content that never settles, as
an indicator that keeps moving, never answers: give `timeout`, in
milliseconds, to have it fail with `timeout` instead.

```json
{"id": 8, "method": "idle", "params": {"window": 1, "timeout": 5000}}
```

## A client

Any language that opens Unix domain sockets can be a client. In PHP:

```php
$conn = stream_socket_client('unix:///tmp/app.sock');
$call = function (string $method, array $params = []) use ($conn): mixed {
    fwrite($conn, json_encode(['id' => 1, 'method' => $method, 'params' => (object)$params]) . "\n");
    $answer = json_decode(fgets($conn), true);
    if (isset($answer['error'])) {
        throw new RuntimeException("$method: {$answer['error']['message']}");
    }
    return $answer['result'];
};
$call('act', ['key' => 'add', 'action' => 'press']);
$call('idle');
$tree = $call('tree');
```

and in a shell, with `socat`:

```sh
echo '{"id": 1, "method": "windows"}' | socat - UNIX-CONNECT:/tmp/app.sock
```

## Security

Whoever opens the socket drives the app as its user would, and runs
scripts in its pages: keep the plugin out of the builds you ship. It is
opt-in twice: the app adds it with `App::use`, and it listens only when
`MYPHP_AUTOMATION` is set. The socket is the user's alone, made with mode
`0600`; put it in a directory only the user can write to, not one shared
with others.
