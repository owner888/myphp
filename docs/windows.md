# Windows

A window is a native window that shows a web page, loaded from its `url`,
or [native UI](ui/README.md) that MyPHP draws, from its `content`. Create
windows with `new Window(new WindowOptions(...))` once the app is ready:

```php
App::whenReady(function () {
    $win = new Window(new WindowOptions(
        title: 'Editor',
        url: '/',
        width: 1200,
        height: 800,
        minWidth: 600,
        minHeight: 400,
        stateKey: 'editor',
    ));
    $win->on('closed', fn() => Logger::debug('closed', 'app'));
});
```

A window of native UI takes `content` in place of `url`:

```php
new Window(new WindowOptions(title: 'Inspector', content: Content::file(__DIR__ . '/view.php', $app)));
```

Everything in this guide applies to windows of both kinds, apart from
[pages](#pages), which only windows showing web pages have, and the options
about them.

## Options

The default `WindowOptions` is a visible, resizable window of 800×600
centered on the screen. Sizes and positions are in points.

| Option | |
|---|---|
| `title` | the window title; defaults to the app name, and a page's `<title>` replaces it |
| `url` | loaded once the window exists; `/` and other URLs without a scheme are pages of the [frontend](frontend.md#how-pages-load); `html` loads a string of HTML instead |
| `content` | shows [native UI](ui/README.md) that MyPHP draws, instead of a page |
| `width`, `height` | the size of the window, or of what it shows with `useContentSize` |
| `x`, `y` | the top-left corner; the window is centered when both are 0 |
| `minWidth`, `minHeight`, `maxWidth`, `maxHeight` | limits of resizing; 0 means none |
| `fitContent` | sizes the page area to its page's content, and follows it, within the limits above |
| `stateKey` | remembers the window's bounds and state under this key, see [below](#remember-where-windows-were) |
| `hidden` | creates the window without showing it |
| `maximized`, `fullScreen` | creates it maximized or in full screen |
| `frameless` | no title bar and borders: the page draws them, see [custom title bars](frontend.md#custom-title-bars), or the [native UI](ui/windows.md) |
| `titleBarStyle` | `'hidden'` hides the title bar but keeps the window buttons over the page, or over [native UI](ui/windows.md) |
| `trafficLightPosition` | moves the window buttons of a hidden title bar (macOS) |
| `hideWindowButtons` | hides the window buttons of a hidden title bar, which keeps its resizing top edge, shadow and corners: the page draws its own and calls `minimize()`, `maximize()`, `unmaximize()` and `close()` |
| `transparent`, `vibrancy` | a transparent window, and the material behind a transparent page or [native UI](ui/windows.md) (macOS): `'sidebar'`, `'window'`, `'content'`, `'header'`, `'titlebar'`, `'under-window'`, `'under-page'`, `'menu'`, `'popover'`, `'hud'`, `'sheet'`, `'tooltip'`, `'selection'`, `'fullscreen-ui'` |
| `backgroundColor` | fills the window until the page paints, in CSS syntax such as `'#1e1e1e'`, or `'light-dark(#f5f5f7, #1e1e1e)'` to follow the appearance |
| `opacity` | between 0 and 1 |
| `disableResize`, `disableMove`, `disableMinimize`, `disableMaximize`, `disableClose`, `disableFullScreen`, `disableShadow` | take abilities away |
| `alwaysOnTop` | keeps the window above others |
| `parent`, `modal` | a child window, modal to its parent (a sheet on macOS); for one placed next to a part of it, see [flyouts](#flyouts) |
| `panel` | the window of a launcher or a quick entry, floating above all apps, see [panels](#panels) |
| `page` | the options of the window's [page](#pages), a `PageOptions`: its preload script, trusted origins, web inspector, zoom, user agent and its suffix, swipes and pinches |

## Show windows without flashing

A window showing a web page shows its `backgroundColor`, white by default,
until the page paints, so make it the page's background. When the page
follows dark mode, give both colors with `light-dark(<light>, <dark>)`: the
window switches with the appearance. To show windows only once their page
is ready, create them hidden and show them on `readyToShow`:

```php
$win = new Window(new WindowOptions(url: '/', hidden: true, backgroundColor: '#1e1e1e'));
$win->on('readyToShow', fn() => $win->show());
```

Windows of native UI need neither: they have no page to load, and show
with their first frame. One created `hidden` is ready to show once it drew
its first frame, which it draws while hidden, so `readyToShow` shows it as
it does a page's.

## Remember where windows were

With a `stateKey`, a window remembers its position, size, and maximized
and full screen state in `window-state.json` in the user data directory,
which is written when the window closes and when the app quits. The next
window created with the same key, at the next launch for example, gets
them back, as long as it would show on a connected display. They override
`x`, `y`, `width`, `height`, `useContentSize`, `maximized` and
`fullScreen`, which stay the defaults of the first launch. Give each kind
of window its own key: `'main'`, `'preferences'`.

An app that keeps its data elsewhere, such as in its own database, keeps
the states there with a `WindowStateStore`, set before the first window
with a `stateKey`, and no `window-state.json` is written:

```php
final class WindowStates implements WindowStateStore
{
    public function __construct(private PDO $db) {}

    /** @return array<string, WindowState> */
    public function loadWindowStates(): ?array
    {
        $json = $this->db->query("SELECT value FROM settings WHERE key = 'windows'")->fetchColumn();
        if ($json === false) {
            return null;
        }
        return array_filter(array_map(WindowState::fromArray(...), json_decode($json, true)));
    }

    /** @param array<string, WindowState> $states */
    public function saveWindowStates(array $states): void
    {
        $json = json_encode(array_map(fn(WindowState $s) => $s->toArray(), $states));
        $this->db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('windows', ?)")->execute([$json]);
    }
}

App::setWindowStateStore(new WindowStates($db));
```

MyPHP loads the states once, when the first window with a `stateKey` is
created, and saves them all when such a window closes and when the app
quits. Windows open with their options when there are no states.

### Bring a page back

A window's position comes back with its `stateKey`; its page comes back
with its session state: `Page::sessionState()` saves the back/forward
list, with the scroll positions and form fields the engine saved, and
`PageOptions::$sessionState` brings it back in a new window or web view, in
place of its `url`, Back included:

```php
$win->on('close', function (CloseEvent $e) use ($win, $sessionFile) {
    file_put_contents($sessionFile, $win->page()->sessionState());
});

// At the next launch:
$state = @file_get_contents($sessionFile) ?: null;
$win = new Window(new WindowOptions(stateKey: 'main', url: '/', page: new PageOptions(sessionState: $state)));
```

The state is opaque bytes in a format MyPHP versions: the page's URL and
the engine's own state. A state the platform can't read brings back the
page it was at alone, and none loads `url`. It may hold what the user typed
into forms: keep it as the user's data.

| Platform | Session state |
|---|---|
| macOS | `WKWebView`'s `interactionState`, macOS 12 or later; the page's URL alone before |
| Linux | `webkit_web_view_get_session_state`, serialized, and `webkit_web_view_restore_session_state` |

## Size, position and state

```php
$win->setSize(1024, 768);
$win->setContentSize(1024, 700); // the page area
$win->setPosition(100, 100);
$win->setBounds(new Rectangle(100, 100, 1024, 768));
$win->center();

$win->maximize();          // unmaximize, toggleMaximize, isMaximized
$win->minimize();          // restore, isMinimized
$win->setFullScreen(true); // toggleFullScreen, isFullScreen
$win->hide();              // show, showInactive (without focusing), isVisible
$win->focus();             // blur, isFocused
```

`bounds()`, `contentBounds()`, `size()`, `contentSize()` and `position()`
read them back; [`Screen`](native.md#displays) tells where the displays
are. `bounds()` are the whole window's, title bar and borders included,
and `contentBounds()` those of the area the page or native UI shows in. On
Linux under Wayland the compositor decides where windows go and ignores
the positions apps set.

Other properties:

- `setTitle()`, `setResizable()`, `setMovable()`, `setMinimizable()`,
  `setMaximizable()`, `setClosable()`, `setMinimumSize()`,
  `setMaximumSize()`, `setAspectRatio()`, `setAlwaysOnTop()`,
  `setOpacity()`, `setHasShadow()`, `setBackgroundColor()` and
  `setVibrancy()` change what the options set.
- `setVisibleOnAllWorkspaces()` shows it on every Space or virtual desktop
  (macOS, Linux).
- `setContentProtection(true)` keeps it out of screenshots and screen
  recordings.
- `setIgnoreMouseEvents(true)` lets clicks through to what is behind it.
- `setRepresentedFile()` and `setDocumentEdited()` show the file a window
  edits in its title bar, and whether it has unsaved changes (macOS).
- `setProgressBar()` shows the progress of a task on the Dock icon
  (macOS) or the launcher entry (Linux docks that support it):

  ```php
  $win->setProgressBar(new ProgressBar(value: 0.4));
  $win->setProgressBar(new ProgressBar(state: ProgressBar::INDETERMINATE));
  $win->setProgressBar(new ProgressBar(state: ProgressBar::NONE)); // done
  ```

- `flashFrame(true)` asks for attention: the window is marked urgent
  (Linux) or the Dock icon bounces (macOS).

## Child and modal windows

A window with a `parent` stays above it and closes with it. With `modal`,
it also blocks its parent until it closes, like a dialog, as a sheet on
macOS:

```php
$prefs = new Window(new WindowOptions(
    url: '/preferences',
    parent: $win,
    modal: true,
    width: 500,
    height: 400,
));
```

## Flyouts

A flyout is a window without a frame, a background or a taskbar button,
owned by its parent and placed next to a rectangle of it, its anchor: the
list of a combo box, a hover card, a popover with a form. Unlike an
[overlay](ui/overlays.md) of native UI or a popover of the page, it may
extend beyond the parent's edges, and the work area of the display decides
where it goes, not the parent's content. Its page or [native
UI](ui/README.md) draws all of it, its background included:

```php
$fl = Window::flyout(new FlyoutOptions(
    parent: $win,
    anchor: new Rectangle(20, 40, 120, 28), // in $win's content area
    placement: FlyoutOptions::BOTTOM_START,
    gap: 4,
    width: 240,
    height: 300,
    content: Content::file(__DIR__ . '/suggestions.php', $app),
));
```

The anchor is in points relative to the parent's content area, which is
what `$c->bounds($node)` gives for the node that opens the flyout; a
rectangle of zero size anchors it to a point. `placement` names the side of
the anchor the flyout goes to and how it lines up with it along that side,
as `'bottom-start'`, `'right'` or `'top-end'`. Where the work area has no
room for it there, the flyout flips to the other side, or the other end, if
that has room, then slides back into the work area, then shrinks to fit
it. It stays above its parent, follows it as it moves and closes with it.
`setAnchor()` places it against another rectangle, and `setSize()` resizes
it where it goes; `isFlyout()` tells a flyout from other windows.

With `focusable`, a flyout takes the keyboard as it shows and closes as a
menu does when the user presses outside it, another window takes the
keyboard or the app is deactivated; `close` listeners hear it and may keep
it open. Without it, a flyout never takes the keyboard, which stays in the
parent, and it stays until it is closed: a tooltip, a hover card, or the
suggestions of a text field in the parent. Either closes on an Escape its
content leaves.

`shadow` gives it the system's window shadow on macOS. On macOS,
`popover` gives it the look of the system's popovers instead: it shows in
an `NSPopover`, which draws its material, rounded corners and an arrow
pointing at the middle of the anchor, and animates; its content draws no
background over the material (`$c->vibrancy()` reports it).

A flyout can hang from a tray icon instead of a window, as the panel of a
menu bar app: give it the `Tray` in place of a `parent`. It goes next to
the icon, on the side `placement` names, and floats over the windows of
other apps. Keep the app running as the flyout, its only window, closes:

```php
App::onWindowAllClosed(function () {});
$tray->onClick(function () use ($tray, &$panel, $app) {
    if ($panel !== null) {
        $panel->close();
        return;
    }
    $panel = Window::flyout(new FlyoutOptions(
        tray: $tray,
        placement: FlyoutOptions::BOTTOM,
        width: 280,
        height: 230,
        focusable: true,
        popover: true,
        content: Content::file(__DIR__ . '/panel.php', $app),
    ));
    $panel->on('closed', function () use (&$panel) { $panel = null; });
});
```

`examples/menubar-flyout` is such an app, and `examples/flyout` opens a
list of colors, a note editor and an About box.

### Size a flyout to its content

With `fitContent`, a flyout takes the size of its page's content and
follows it as it changes, as browsers size the popups of their extensions.
`width` and `height` are its size only until the page tells its own.
`minWidth`, `minHeight`, `maxWidth` and `maxHeight` bound it, and the work
area, as any flyout. Native UI (`content`) does not fit.
`WindowOptions::$fitContent` does the same for the page area of a window.

## Panels

A panel is the window of a launcher, a command palette or a quick entry,
as Spotlight's and Raycast's are: summoned from anywhere by a [global
shortcut](native.md#global-shortcuts), it floats above the windows of every
app, on whichever Space the user is on, over apps in full screen too, and
stays out of the Dock's windows and the app switcher. On macOS, showing it
gives it the keyboard without activating the app.

```php
App::setActivationPolicy(ActivationPolicy::Accessory); // no Dock icon (macOS)
App::onWindowAllClosed(function () {});                 // keep running with no window

App::whenReady(function () {
    $panel = new Window(new WindowOptions(
        panel: true,
        hidden: true,
        frameless: true,
        transparent: true,
        vibrancy: 'hud',
        width: 680,
        height: 420,
        url: '/launcher',
    ));
    // Hide rather than close, so that it shows at once the next time.
    $panel->on('close', function (CloseEvent $e) use ($panel) {
        $e->cancel();
        $panel->hide();
    });
    $panel->on('blur', fn() => $panel->hide());

    GlobalShortcut::register('Alt+Shift+Space', function () use ($panel) {
        if ($panel->isVisible()) {
            $panel->hide();
            return;
        }
        $panel->center();
        $panel->show();
    });
});
```

An Escape the page does not handle (`preventDefault()`) or native UI leaves
closes a panel, as the user closing it, which `close` listeners may turn
into hiding it, as above.

## Events

`$win->on($event, $fn)` adds a listener, which runs on the main thread, and
returns a function that removes it:

| Event | When |
|---|---|
| `close` (with a `CloseEvent`) | the window is about to close, by the user or `close()`; `$e->cancel()` keeps it open |
| `closed` | the window closed |
| `focus`, `blur` | it gained or lost the keyboard focus |
| `show`, `hide` | it was shown or hidden |
| `resize`, `move` | it was resized or moved |
| `maximize`, `unmaximize`, `minimize`, `restore` | its state changed |
| `enterFullScreen`, `leaveFullScreen` | it entered or left full screen |
| `readyToShow` | its first page is ready to be displayed, or its native UI drew its first frame |
| `fileDrop` (with a `FileDropEvent`) | files were dropped on its page, or on native UI that did not take them, see [dropped files](ui/input.md#dropped-files) |

Ask before closing a window with unsaved changes:

```php
$win->on('close', function (CloseEvent $e) use ($win, $doc) {
    if (!$doc->dirty()) {
        return;
    }
    $e->cancel();
    App::later(function () use ($win, $doc) {
        $res = Dialog::message(new MessageOptions(
            parent: $win,
            type: 'question',
            message: 'Save changes before closing?',
            buttons: ['Save', "Don't Save", 'Cancel'],
        ));
        if ($res->button === 0) {
            $doc->save();
            $win->destroy();
        } elseif ($res->button === 1) {
            $win->destroy();
        }
    });
});
```

`close()` closes a window as the user would, asking `close` listeners;
`destroy()` closes it at once.

## Pages

A window shows one page at a time and has a history, like a browser tab.
`$win->page()` returns it, or null for a window that shows [native
UI](ui/README.md):

```php
$page = $win->page();
$page->loadURL('/settings'); // loadHTML, loadFile
$page->reload();             // reloadIgnoringCache, stop
$page->goBack();             // goForward, canGoBack, canGoForward
$page->url();                // the current page
$page->setZoomFactor(1.25);  // zoomFactor
```

`WindowOptions::$page` sets up the page:

```php
new Window(new WindowOptions(
    url: '/',
    page: new PageOptions(zoomFactor: 1.25, devTools: DevTools::Enabled),
));
```

| Option | |
|---|---|
| `preloadScript` | JavaScript run before every page's own scripts |
| `preloadAllFrames` | runs `preloadScript` in the page's iframes too, of every origin |
| `trustedOrigins` | other origins whose pages may call PHP, see [who may call](bindings.md#who-may-call) |
| `untrusted` | a page that may never call PHP, for third-party or user-supplied HTML |
| `devTools` | `DevTools::Auto` (the inspector in development), `Enabled` or `Disabled`; for a window of native UI, its [inspector](inspector.md) |
| `zoomFactor`, `userAgent` | the page's zoom (1 is 100%) and user agent |
| `userAgentSuffix` | appended to the user agent instead of replacing it, such as `'MyApp/1.2'` |
| `pictureInPicture` | lets the page's videos go into picture in picture (macOS) |
| `swipeNavigation` | a horizontal swipe goes back and forward through the page's history, as in browsers |
| `pinchZoom` | a pinch magnifies the page, as in browsers |
| `contentRules` | rules that block what the page loads and hide parts of it (`ContentRules`), see [blocking content](#blocking-content) |
| `backgroundThrottling` | `BackgroundThrottling::On` (the web view's own) or `Off`, which keeps the page running while its window is hidden, minimized or covered |
| `autoplay` | `Autoplay::UserGesture` (the web view's own policy) or `Allow`, which lets the page play audible media before the user clicked or typed in it |
| `session` | the store of the page's cookies, storage and caches, apart from other sessions', see [sessions](app.md#sessions); null is the default session |
| `sessionState` | the back/forward list to bring back, see [above](#bring-a-page-back) |

Page events, with `$win->page()->on($event, $fn)`:

| Event | When |
|---|---|
| `willNavigate` (with a `NavigateEvent`) | the page is about to navigate, for example after a click on a link; `$e->preventDefault()` cancels it. `$e->userInitiated` says whether a user's gesture started it. Not called for `loadURL()` and its siblings. |
| `didNavigate` (with the URL) | a navigation committed |
| `domReady` | the page's DOM is ready (`DOMContentLoaded`) |
| `didFinishLoad` | the page finished loading |
| `didFailLoad` (with a `LoadError`) | the page failed to load |
| `pageTitleUpdated` (with a `TitleEvent`) | the page's `<title>` changed; preventing the event keeps the window title as it is |
| `themeColorChanged` | the page's theme color changed (`themeColor()`) |
| `renderProcessGone` | the web content process crashed or was killed; `reload()` recovers |
| `audibleChanged` | the page started or stopped making sound (`isAudible()`, `setAudioMuted()`) |
| `captureStateChanged` | the page started or stopped capturing the camera, the microphone or the display (`captureState()`, `setCaptureState()`) |
| `willDownload`, `downloadDone` | a download starts or ends (`DownloadEvent`, `Download`) |
| `contextMenu` | the page's context menu is about to open (`ContextMenuEvent`) |

Keep a window on the app's own pages and open other links in the browser:

```php
$win->page()->on('willNavigate', function (NavigateEvent $e) {
    $scheme = parse_url($e->url, PHP_URL_SCHEME);
    if (in_array($scheme, ['http', 'https'], true) && $e->userInitiated) {
        $e->preventDefault();
        Shell::openExternal($e->url);
    }
});
```

During development the app's pages come from the dev server, an `http`
URL: check the host, or `App::isDev()`, when your rule depends on the
scheme. Links that open new windows go through the window's open handler,
`setWindowOpenHandler()`, see [links and new
windows](frontend.md#links-and-new-windows).

### More of a page

- **Find in page**: `findInPage($text, new FindOptions(...))` returns a
  `FindResult`, and `stopFindInPage()` clears the highlights.
- **Printing, PDF and screenshots**: `print()` shows the print panel,
  `printToPDF(new PDFOptions(...))` returns the PDF's bytes, and
  `capture()` a PNG of the page, as `Window::capturePage()` does of the
  window.
- **Permissions**: `setPermissionHandler(fn(PermissionRequest $r) => …)`
  answers what the page asks for, as the camera, the microphone or
  notifications.
- **Sign-in and certificates**: `setAuthHandler()` answers HTTP
  authentication (`AuthRequest`, `AuthResponse`), and
  `setCertificateErrorHandler()` a certificate the engine does not trust
  (`CertificateError`).
- **Picture in picture**: `togglePictureInPicture()` and
  `isPictureInPictureActive()`, with `PageOptions::$pictureInPicture`.
- **Evaluating JavaScript**: `eval($code)` runs code in the page and
  returns its JSON result, waiting for a promise.
- **The web inspector**: `openDevTools()`, `closeDevTools()`,
  `toggleDevTools()`, in development or with `DevTools::Enabled`.
- **The page's process**: `processID()`.

### Blocking content

Content rules block what the page loads and hide parts of it, as WebKit's
content blockers do: `ContentRules` in `PageOptions::$contentRules`, or
`setContentRules()` for a page already open. See the `ContentRules` class
for their format, which is WebKit's.

## Finding windows

- `Window::all()` lists the open windows, `Window::focused()` returns the
  focused one and `Window::byId($id)` the one with an `id()`, which pages
  know as `window.myphp.windowId`.
- A bound method that takes a `CallContext` gets the window whose page
  called it, `window()`, and the page, `page()`: the window's, or that of a
  web view in a window of native UI ([Web view](ui/web-view.md)).
- `App::onWindowCreated()` is called for every new window.

## Native access

`$win->nativeHandle()` returns the native window, an `NSWindow` on macOS,
for APIs MyPHP does not cover, which `MyPHP\Native\ObjC` calls.
