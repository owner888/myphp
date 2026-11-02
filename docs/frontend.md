# The frontend

Windows that show web pages show the pages of the app's frontend: HTML,
CSS and JavaScript built with any tools. New projects of the web template
use TypeScript and [Vite](https://vite.dev), but MyPHP only needs a URL to
load, during development, and a directory of built files, for builds.
Windows of [native UI](ui/README.md) need no frontend, and an app that only
has those has none.

## How pages load

Windows load pages of the frontend with URLs without a scheme, such as `/`
or `/settings?tab=general`:

```php
new Window(new WindowOptions(url: '/'));
$win->page()->loadURL('/settings');
```

They resolve against:

- during `myphp dev`, the dev server at `devUrl` in myphp.config.ts or
  myphp.json, which `devCommand` starts, e.g. `http://localhost:5173/`, so
  the dev server's hot reload works in the app;
- in builds, `myphp://localhost/`, which serves the files of
  `frontendDist` that `myphp build` puts in the bundle after running
  `buildCommand`.

```json
{
  "devUrl": "http://localhost:5173",
  "devCommand": "bun run dev:web",
  "buildCommand": "bun run build:web",
  "frontendDist": "dist"
}
```

Without `devUrl`, `myphp dev` serves `frontendDist` from disk instead.
Paths without a file extension that match no file serve `index.html`, so
client-side routers work.

The frontend is a secure context, and its origin is `myphp://localhost`.
Storage such as `localStorage` and IndexedDB belongs to an origin, so data
a page stores under `myphp dev` (the dev server's origin) is not what the
built app sees. Keep data you care about in PHP, for example in a file in
`App::path(PathName::UserData)`, and hand it to pages through bound
methods.

Windows can also load other content into their page:

- `$win->page()->loadURL('https://example.com')`, a web page;
- `$win->page()->loadHTML($html, $baseURL)`, an HTML string, or
  `WindowOptions::$html`;
- `$win->page()->loadFile('docs/index.html')`, a local file.

Pages that are not the app's own cannot call PHP methods: see [who may
call](bindings.md#who-may-call).

### Without the CLI

`App::setFrontend($dir)` serves a directory as `myphp://localhost/`, for a
script run without `myphp build`:

```php
App::setFrontend(__DIR__ . '/dist');
```

## Custom protocols

`Protocol::handle()` serves a URL scheme from a PHP function, for content
your app produces or reads at run time: pages, images, files of the user.
A handler gets a `ProtocolRequest` and returns a `ProtocolResponse`, on the
main thread; a body that is a generator streams, so a large file is never
held in memory whole. Pages load such URLs like web URLs, with `fetch`,
`<img>`, ES modules and relative URLs:

```php
// Thumbnails generated into the cache directory.
Protocol::handle('thumbs', Protocol::fileServer(App::path(PathName::Cache)));

// Anything a function answers.
Protocol::handle('api', function (ProtocolRequest $r): ProtocolResponse {
    return new ProtocolResponse(200, ['Content-Type' => 'application/json'], json_encode(['path' => $r->path()]));
});
```

```html
<img src="thumbs://localhost/photo-42.png" />
```

Register schemes before creating the windows that use them.
`Protocol::fileServer()` serves a directory with content types and range
requests, falling back to `index.html` like the frontend. Handling the
`myphp` scheme replaces the frontend with your handler. Pages of custom
schemes are the app's own: they may call PHP methods, see [trust](#trust).

Audio and video are the exception on Linux: WebKitGTK's media player does
not play from a custom scheme. Serve media from a loopback HTTP server
instead (`php -S 127.0.0.1:0` in a [process](native.md) of the app's, say),
and give the page its URL. Every program of the machine can reach such a
server: serve it only what they may read.

Each scheme is its own origin, so a page fetching another scheme makes a
cross-origin request. Responses to the app's own pages, those that may
call PHP methods, carry the CORS headers that let them read the response,
and MyPHP answers their preflights. Other sites get none, unless the window
trusts them (`PageOptions::$trustedOrigins`), and handlers may set their
own.

### Trust

A window trusts the app's own pages: its frontend, pages of custom
schemes, `file:` and `about:` pages, origins in
`PageOptions::$trustedOrigins`, and in development pages served from
localhost. Trust follows the page's URL, in a window or a [web
view](ui/web-view.md), and changes as it navigates. A trusted page may:

- call every method bound with `Ipc::bind()`, and the plugins the app
  uses;
- call `window.myphp.window` to set the title of its window, minimize,
  maximize, full-screen or close it;
- get the camera, the microphone, the location and notifications without
  a permission handler;
- read the responses of every custom scheme, which carry CORS headers for
  it;
- hear the paths of the files dropped on it.

So never serve third-party or user-supplied HTML from a custom scheme, nor
load it with `loadHTML()` without a base URL, in a page that trusts it.
Show it in an untrusted page instead, or from an origin the window does
not trust, such as an `https` site or a `data:` URL: it calls no PHP
method, and gets no permission unless a permission handler grants it.

#### Untrusted pages

`PageOptions::$untrusted` makes a page someone else's whatever its URL,
for a window or a [web view](ui/web-view.md) showing a plugin's UI from a
custom scheme. It trusts no origin, `trustedOrigins` included: its calls,
to bound methods and to `window.myphp.window`, are rejected, and its drag
regions do nothing; `broadcast()` does not reach it, nor `emit()` to the
window its web view is in, so send to it with `emitPage()`. It gets no
permission without `setPermissionHandler()`, opens no window nor the
browser without `setWindowOpenHandler()`, downloads nothing without
`willDownload` listeners, and goes to no other origin than its page's
without `willNavigate` listeners. It still reads its own scheme, whose
handler answers it.

## The myphp-runtime package

MyPHP injects its runtime into every page as `window.myphp`. The
`myphp-runtime` npm package, which new projects depend on, gives it types
and imports; the generated client is built on it.

```ts
import { currentWindow, isMyPHP, runtime } from "myphp-runtime";

if (isMyPHP() && runtime().platform === "darwin") {
  document.documentElement.classList.add("mac");
}

await currentWindow.toggleMaximize();
```

| Export | What it does |
|---|---|
| `call(method, ...args)`, `on(event, listener)`, `once(event, listener)`, `event<T>(name)` | untyped calls and events, see [bindings](bindings.md#without-the-generated-client) |
| `Channel` | a stream of values from a PHP method, see [channels](bindings.md#channels) |
| `isCallError(err)` | whether a rejection came from a PHP exception |
| `isMyPHP()` | whether the page runs in a MyPHP window, not in a browser tab of the dev server |
| `runtime()` | the runtime: `platform`, `windowId` (the window's `id()`) and `version`; throws outside MyPHP |
| `currentWindow` | the page's window: `minimize`, `maximize`, `unmaximize`, `toggleMaximize`, `isMaximized`, `toggleFullScreen`, `close` and `setTitle` |
| `onFileDrop(listener)` | files dropped on the window, with their paths |
| `preferences()`, `onPreferences(listener)` | the accent color and the accessibility settings of the desktop, and their changes |
| `locale()`, `direction()`, `onLocaleChange(listener)` | the language the app shows (`App::locale()`), its direction, `"ltr"` or `"rtl"`, and its changes |
| `onDocumentState(listener)`, `onDocumentReload(listener)` | the state of the window's [document](documents.md) |

`myphp-runtime/i18n` formats messages of ICU MessageFormat, as
`MyPHP\Message` does in PHP. Opened in a regular browser, which is handy
for working on the layout, pages have no runtime: calls reject and
`isMyPHP()` is false.

## Custom title bars

A frameless window has no title bar or borders, and the page draws its own.
Mark the elements that drag the window with the CSS property
`--app-region: drag`:

```php
new Window(new WindowOptions(url: '/', frameless: true));
```

```css
.titlebar {
  --app-region: drag;
  height: 40px;
}
.titlebar .search {
  --app-region: no-drag;
}
```

```ts
import { currentWindow } from "myphp-runtime";

closeButton.onclick = () => currentWindow.close();
```

A press on a drag region moves the window, and a double click zooms it as
macOS is set to; controls inside stay controls.

### Hidden title bars

`titleBarStyle: 'hidden'` keeps the window buttons over the page, which
draws the rest of the title bar under them. The page gets the room the
buttons take as CSS variables, `--myphp-titlebar-height`,
`--myphp-titlebar-inset-left` and `--myphp-titlebar-inset-right`, which
are zero in full screen:

```css
.titlebar {
  --app-region: drag;
  height: var(--myphp-titlebar-height, 32px);
  padding-left: var(--myphp-titlebar-inset-left, 0);
  padding-right: var(--myphp-titlebar-inset-right, 0);
}
```

`trafficLightPosition` moves the buttons (macOS), and `hideWindowButtons`
hides them for the page to draw its own.

## Dropped files

Files dropped on a page reach it with their paths, through `onFileDrop`
of `myphp-runtime`, and PHP through the window's `fileDrop` event:

```ts
import { onFileDrop } from "myphp-runtime";

onFileDrop(({ paths }) => importFiles(paths));
```

## Dark mode

Pages follow the system's appearance with `prefers-color-scheme`, and
`color-scheme: light dark` gives their controls the matching look. A
window's `backgroundColor` given as `light-dark(<light>, <dark>)`
switches with them. `Theme::setSource()` fixes the appearance of the app's
pages and windows (see [dark mode](native.md#dark-mode)).

## Accent color and accessibility

`preferences()` of `myphp-runtime` returns the accent color the user chose
and whether they asked for less motion, less transparency or more
contrast, and `onPreferences()` hears them change. CSS's
`prefers-reduced-motion`, `prefers-reduced-transparency` and
`prefers-contrast` work as in a browser, and `accent-color: auto` takes
the system's accent.

## Pages that size their window

A [flyout](windows.md#size-a-flyout-to-its-content) or a window with
`fitContent` takes the size of its page's content and follows it.

## Links and new windows

`window.open` and links with `target="_blank"` go through the page's open
handler. Without one, `http` and `https` URLs open in the default browser
and the rest is denied. `setWindowOpenHandler()` decides: it gets a
`WindowOpenRequest` (its `url`, `frameName`, size and whether the user
started it) and returns the options of the window to open the page in, or
null to deny it:

```php
$win->page()->setWindowOpenHandler(function (WindowOpenRequest $r): ?WindowOptions {
    if (str_starts_with($r->url, 'myphp://')) {
        return new WindowOptions(width: 600, height: 400);
    }
    Shell::openExternal($r->url);
    return null;
});
```

The new page keeps its opener (`window.opener`).

## Preload scripts

`PageOptions::$preloadScript` runs JavaScript before every page's own
scripts, with `window.myphp` ready: for polyfills, globals the pages
expect, or a page of another site the app adapts. `preloadAllFrames` runs
it in the page's frames too.

## The web inspector

In development, right-click a page and choose Inspect Element, or call
`$win->page()->openDevTools()`. `PageOptions::$devTools` turns it on or off
for a window (`DevTools::Enabled`, `Disabled`), and the menu role
`toggleDevTools` toggles it.
