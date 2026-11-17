# WebView in native UI

`WebView($page)` embeds a system web view (`MyPHP\WebView`, created once on the window via `$window->newWebView()`) inside a declarative native UI layout, taking the room it is given, as SwiftUI's `WebView`:

<!-- no-test -->
```php
$view = fn() => VStack(spacing: 0)(
    HStack(spacing: 8)(
        Text('Documentation')->font(Font::headline),
        Spacer(),
        Button('Reload', action: fn() => $this->web->page()->reload()),
    )->padding(12),
    Divider(),
    WebView($this->web),
);
```

## Creating and controlling a `WebView`

Create the `MyPHP\WebView` instance once in your state or `App::whenReady` callback (not inside the per-frame view closure) with `$window->newWebView(new WebViewOptions(url: 'https://…'))`.

- **Native overlays above web content**: Menus, popovers, sheets, alerts, and toasts declared in your SwiftUI view tree render cleanly above the embedded web view.
- **Full IPC bridge**: `$web->page()` is a standard `MyPHP\Page` — it supports `loadURL()`, `loadHTML()`, `eval()`, `on('didNavigate', …)`, custom protocols, and `window.myphp.call()` RPC bindings (see [`examples/embed-webview`](../../examples/embed-webview)).
- **Tab switching**: When a `WebView` is not built in the current frame (for example, while another tab is selected), it hides automatically while keeping its live DOM and scroll state in memory.
