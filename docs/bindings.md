# Calling PHP from an Embedded WebView (`Ipc::bind` & `window.myphp`)

When an app embeds a system `WebView` (`$win->newWebView()`) showing local HTML
or an internal page (`file://`, `about:`, or a custom `Protocol` scheme), the
page can call bound PHP objects directly through the built-in `window.myphp`
bridge — with zero external JS packages required.

*(Note: Native SwiftUI views in `view.php` are already pure PHP and call your
state methods directly without `Ipc::bind`.)*

## 1. Bind a PHP Service

Pass any PHP object to `Ipc::bind()` before `App::run()`:

<!-- no-test -->
```php
use MyPHP\Ipc;

final class Notes
{
    private array $items = [];

    public function add(string $title): array
    {
        return $this->items[] = ['id' => count($this->items) + 1, 'title' => $title];
    }

    public function list(): array
    {
        return $this->items;
    }
}

Ipc::bind(new Notes());
```

## 2. Call PHP from HTML / JavaScript (`window.myphp`)

Every trusted page automatically receives the `window.myphp` bridge:

```html
<script>
  // 1. Call a bound PHP method (returns a Promise):
  window.myphp.call("Notes.add", "Groceries").then((note) => {
    console.log("Created:", note.id, note.title);
  });

  // 2. Listen to events emitted from PHP:
  window.myphp.on("progress", (payload) => {
    console.log("Progress:", payload);
  });

  // 3. Stream values from a PHP Generator or Channel parameter:
  const ch = new window.myphp.Channel((line) => console.log(line));
  window.myphp.call("Shell.tail", "/tmp/app.log", ch);
</script>
```

## 3. Emit Events or Run JS from PHP

- **Events (`new Event('name')`)**: Call `$event->emit($win, $payload)` or
  `$event->broadcast($payload)` to push data to `window.myphp.on('name', ...)`.
- **Evaluate JS (`$page->eval()`)**: Run JavaScript inside the `WebView` and
  receive the decoded result in PHP:
  <!-- no-test -->
  ```php
  $title = $this->web->page()->eval('document.title');
  ```

## 4. Security Boundary (`trustedOrigins`)

By default, only the app's own local pages (`myphp://localhost`, `file:`,
`about:`) can call `window.myphp`. External websites loaded in a `WebView`
(such as `https://example.com`) are isolated and cannot invoke PHP methods
unless explicitly listed in `PageOptions::$trustedOrigins`.