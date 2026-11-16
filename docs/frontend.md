# Embedded WebViews & Custom Protocols

While MyPHP applications build their primary user interface in pure PHP using
SwiftUI-style declarative views, you can embed a native system `WebView`
anywhere inside your declarative view tree to display external web pages (such
as OAuth sign-in flows, terms of service, documentation, or payment pages) or
local HTML content, and serve custom URL schemes via `MyPHP\Protocol`.

## 1. Embedding a `WebView` in a Declarative View

Create a `WebView` from your `Window` once (`$win->newWebView()`), and place
`WebView($this->page)` inside any `VStack`, `HStack`, `TabView`, or sheet:

<!-- no-test -->
```php
use MyPHP\{App, WebView, WebViewOptions, Window, WindowOptions};
use MyPHP\UI\Content;

final class BrowserState
{
    public ?WebView $web = null;
}

$app = new BrowserState();
App::whenReady(function () use ($app) {
    $win = new Window(new WindowOptions(
        title: 'Embedded WebView',
        width: 960,
        height: 680,
        content: Content::file(__DIR__ . '/view.php', $app),
    ));
    $app->web = $win->newWebView(new WebViewOptions(url: 'https://myphp.ai/docs'));
});
App::run();
```

In `view.php`:

<!-- no-test -->
```php
return fn() => VStack(spacing: 0)(
    HStack(spacing: 8)(
        Button('Back', action: fn() => $this->web?->page()->goBack()),
        Button('Reload', action: fn() => $this->web?->page()->reload()),
        Spacer(),
    )->padding(8),
    Divider(),
    $this->web !== null ? WebView($this->web) : ProgressView(),
);
```

## 2. Controlling the Page and Running JavaScript (`$page->eval`)

`$this->web->page()` returns the underlying `MyPHP\Page` instance:

- **`$page->loadURL(string $url)` / `$page->loadHTML(string $html)`**: Navigate
  to a URL or render an HTML string.
- **`$page->eval(string $code): mixed`**: Evaluates a JavaScript expression or
  async statement block inside the page and returns its JSON-decoded result to
  PHP:
  <!-- no-test -->
  ```php
  $title = $this->web->page()->eval('document.title');
  ```
- **`$page->on('didFinishLoad', fn() => ...)` / `$page->on('willNavigate', ...)`**:
  Listen to navigation and lifecycle events.

## 3. Custom URL Schemes (`MyPHP\Protocol`)

`Protocol::handle($scheme, $handler)` registers a custom in-app protocol
(such as `myapp://...` or `thumbs://...`) served directly by PHP without opening
a network port:

<!-- no-test -->
```php
use MyPHP\{Protocol, ProtocolRequest, ProtocolResponse};

Protocol::handle('thumbs', function (ProtocolRequest $req): ProtocolResponse {
    return new ProtocolResponse(
        status: 200,
        headers: ['Content-Type' => 'text/plain; charset=utf-8'],
        body: 'Served directly from PHP for ' . $req->url,
    );
});
```