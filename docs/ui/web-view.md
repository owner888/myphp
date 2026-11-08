# Web view

`WebView($page)` puts a web page among the views, taking the room it is
given, as SwiftUI's `WebView`; `$page` a `MyPHP\WebView` the window makes
(`Window::newWebView()`). What shows over it (menus, sheets) shows over
the page.

<!-- no-test -->
```php
$view = fn() => VStack(spacing: 0)(
    Text('Docs')->font(Font::headline)->padding(),
    WebView($this->page),
);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

`$c->webView()` shows a web page in a window of native UI: a document the
web engine renders beside native controls, a page per tab, a preview of
HTML the app makes. The page is the system's web view (WKWebView,
WebKitGTK), made with `Window::newWebView()`, and works as a window's page
does: it loads the app's frontend, calls bound PHP methods with the typed
client and hears events. What the view paints after the web view shows
over the page: a menu opening from a button above it, a select's options,
a dialog and its dimmed backdrop, tooltips, toasts, a badge over a corner.

<!-- no-test -->
```php
final class Browser
{
    public ?Window $win = null;
    public ?WebView $docs = null;
    public bool $menu = false;

    public function view(Context $c): void
    {
        $c->column()->fill()->alignItems(Node::STRETCH);
          $c->row(8, 8);
            $menu = $c->button('Menu ▾');
            if ($c->clicked($menu)) {
                $this->menu = !$this->menu;
            }
            // The popover shows over the page.
            $c->popover($menu, $this->menu, fn() => $c->text('Over the page'));
          $c->end();
          if ($this->docs !== null) {
              $c->webView($this->docs)->grow(1)->radius(8);
          }
        $c->end();
    }
}

$app = new Browser();
App::whenReady(function () use ($app) {
    $app->win = new Window(new WindowOptions(title: 'Docs', content: Content::view(fn(Context $c) => $app->view($c))));
    $app->docs = $app->win->newWebView(new WebViewOptions(url: '/docs'));
    $app->win->invalidate();
});
App::run();
```

## Making web views

`Window::newWebView()` makes a web view of a window showing `content`,
which it closes with. Its options are the page's (`page`, a
`PageOptions`), the `url` to load, a `backgroundColor` to fill it with
until the page paints, `transparent`, which shows what is behind the web
view through the transparent parts of its page: the window's background or
material, as native UI paints nothing under a web view, and
`scrollToContainer`, which gives the wheel over the page to the native UI
around it ([below](#scrolling-the-native-ui-around-it)). `page()` returns
its page, whose methods are a window's page's: `loadURL()`, `loadHTML()`,
`eval()`, `goBack()`, `on('didNavigate', …)` and the others. `focus()`
gives it the keyboard, `setBackgroundColor()` changes the color it fills
with, as when the app's own theme changes (one given as `light-dark(<light>,
<dark>)` follows the appearance by itself), `capturePage()` takes a
screenshot of its page, and `destroy()` closes it.

A web view trusts the app's own pages as a window does, pages of custom
schemes included: show third-party or user-supplied HTML in an [untrusted
page](../frontend.md#untrusted-pages), or from an origin it does not
trust, see [trust](../frontend.md#trust).

Make a web view once, as a window, not in the view: the view only shows
it. One the view does not build hides and keeps its page, so a view of
tabs builds the web view of the tab shown, and switching back finds the
page where it was.

<!-- no-test -->
```php
if ($app->tab === 'docs') {
    $c->webView($app->docs)->grow(1);
} else {
    $c->webView($app->changelog)->grow(1);
}
```

## Its box

A web view has no size of its own. The node stretches across its container
and takes the room along it with `grow()`, or a size. The page shows in the
node's content box: inside its border and padding, which paint around it
with the node's background, rounded as its `radius()` makes the border's
inner edge. Scroll containers and clips around it cut it as they cut any
node, rounded corners included, and a web view scrolled out of view hides.
`opacity()` fades the page with the node, as a page of a router fading in
does: the page blends over what is painted under the node.

<!-- no-test -->
```php
$c->webView($app->preview)->height(320)->radius(12)->border(1, $t->border);
```

## What shows over it

The web view is under the window's native UI: the node cuts a hole through
what the window paints, where the page shows. Everything painted after the
node paints over the page, as it would over any node: nodes absolutely
positioned over it, and the overlay's popovers, menus, selects,
comboboxes, dialogs, tooltips and toasts. Shadows and translucent
backgrounds blend over the page.

<!-- no-test -->
```php
$c->column()->grow(1);
  $c->webView($app->page)->grow(1);
  // A badge over the page's corner.
  $c->text('Draft')->absolute()->top(8)->right(8)->padding(2, 8)->radius(8)->background($t->accent);
$c->end();
```

The pointer goes to the page where it shows and nothing painted over it
takes the pointer: a dialog's backdrop or a select's keeps the pointer from
the page while open, a popover keeps it only over its panel, and a node
with `passThrough()` lets it through. Files dragged there drop on the page.
A press on the page closes the popovers it is outside of
(`pressedOutside()`), and takes the keyboard from the native UI, which
gets it back as the user clicks it. The page sets the cursor over it.

Tab stops at the web view among the native UI's controls: its page takes
the keyboard at its first element, or its last with Shift+Tab, Tab moves
through its elements, and past its last one goes on to the control after
the web view. `focus()` gives the page the keyboard where it had it.

Assistive technology finds the page inside the web view's node, where it is
among the native UI: VoiceOver and Orca read and move through it as through
the controls around it.

## Scrolling the native UI around it

A reader shows a document in a native scroll container: an article
between a native header and its comments, a message in a mail thread, a
rendered note among native cards. The web view is as tall as its page,
which has nothing of its own to scroll, and the wheel over the page should
scroll the container, as it does over the native nodes around it.
`WebViewOptions::$scrollToContainer` does that: the wheel and the touchpad
over the web view scroll the native UI under the pointer as over any of
its nodes, by lines for a mouse's wheel and by pixels for a touchpad, in
the same direction, with the modifier keys held. The page never scrolls
with them; it still takes the pointer's presses, the keyboard, selection
and a touchpad's pinch.

<!-- no-test -->
```php
$article = $win->newWebView(new WebViewOptions(url: '/article', scrollToContainer: true));

// In the view:
$c->scroll()->fill();
  header($c);
  $c->webView($article)->height($app->articleHeight); // the page's height, which it tells the app
  comments($c);
$c->end();
```

Without it, which is the default, the page takes the wheel where it shows,
as a page that scrolls itself needs: docs, a browser's tab, an editor.
On macOS the web view's scroll events, a touchpad's momentum included, go
to the native UI instead of WebKit; on Linux they go to the native UI
before WebKitGTK sees them.

## Its page

The page of a web view works as a window's does (see
[Bindings](../bindings.md)). A bound method that takes a `CallContext`
gets the page that called it, `page()`, whose `webView()` is its web view,
and `window()`, the window the web view is in, which the page's window
controls act on. `Event::emit()` to the window reaches the pages of its web
views, `emitPage()` one page, and `broadcast()` every page.

<!-- no-test -->
```php
final class Notes
{
    public function save(CallContext $ctx, string $text): void
    {
        $page = $ctx->page(); // the web view's
        // ...
    }
}
```

The page's `window.close()` leaves the window open. Content rules block
what a web view's page loads, as they do a window's ([blocking
content](../windows.md#blocking-content)).

### The typed client

The page calls PHP with the client `myphp dev` writes, as a window's page
does: the client knows nothing of web views. A project of native UI has no
frontend, and so no client: give it one, say a Vite app in a `frontend`
directory, with the fields of [the
frontend](../configuration.md#development-and-the-frontend) in its
myphp.json:

```json
{
  "name": "Notes",
  "devUrl": "http://localhost:5173",
  "devCommand": "bun run --cwd frontend dev",
  "buildCommand": "bun run --cwd frontend build",
  "frontendDist": "frontend/dist"
}
```

A web view loading `/` shows the frontend, from the dev server during
`myphp dev` and from the bundle in builds, and its page calls the services
bound with `Ipc::bind()` and hears the events declared with `new Event()`:

<!-- no-test -->
```php
/** Bound for the pages. */
final class Likes
{
    private int $likes = 0;
    public static Event $liked; // tells pages how many likes there are

    /** Counts a like and tells every page. */
    public function like(): int
    {
        self::$liked->broadcast(++$this->likes);
        return $this->likes;
    }
}
Likes::$liked = new Event('liked', 'int');

$page = null;
Ipc::bind(new Likes());
App::whenReady(function () use (&$page) {
    $win = new Window(new WindowOptions(title: 'Notes', content: Content::view(function (Context $c) use (&$page) {
        if ($page !== null) {
            $c->webView($page)->fill();
        }
    })));
    $page = $win->newWebView(new WebViewOptions(url: '/'));
    $win->invalidate();
});
App::run();
```

```ts
import { Likes, events } from "./myphp";

likeButton.onclick = async () => {
  count.textContent = String(await Likes.like());
};
events.liked.on((likes) => (count.textContent = String(likes)));
```

Calls with a `CallContext`, errors, [channels](../bindings.md#channels)
and events work as in a window. A page without a build, such as HTML the
app loads with `loadHTML()`, calls by name with the runtime the bridge puts
in every page, `myphp.call("Likes.like")` and `myphp.on("liked", …)`.
Pages that are not the app's own, as a web view showing
`https://example.com`, cannot call PHP at all: see [who may
call](../bindings.md#who-may-call).

## Limits

The page is the system's web view, which the window composites with the
native UI; MyPHP does not draw it. As with SwiftUI's `WebView`:

- Effects reading what is behind them, as the glass plugin's blur, see
  none of the page: it is not part of the window's frames.
- `Window::capturePage()` of the window shows a hole where the page is;
  `WebView::capturePage()` captures the page.
