# HTTP & URLSession (`URLSession` & `MyPHP\Http`)

MyPHP includes a non-blocking HTTP/HTTPS client powered by system `libcurl` and
PHP 8.5 `Fiber` concurrency. Requests run on the application's main event loop
without blocking window rendering or animations, and automatically use the
operating system's proxy settings and root certificates.

## 1. SwiftUI / Foundation style (`URLSession::shared()`)

The recommended API for all declarative apps is `URLSession::shared()` with
`URLRequest`:

<!-- no-test -->
```php
use MyPHP\{App, URLRequest, URLSession, Window, WindowOptions};
use MyPHP\UI\Content;

final class RepoState
{
    public bool $loading = true;
    public int $stars = 0;
    public string $error = '';

    public function load(): void
    {
        $this->loading = true;
        App::go(function () {
            try {
                $req = new URLRequest(
                    'https://api.github.com/repos/owner888/myphp',
                    headers: ['Accept' => 'application/vnd.github+json'],
                );
                $data = URLSession::shared()->json(for: $req);
                $this->stars = (int)($data['stargazers_count'] ?? 0);
            } catch (\RuntimeException $e) {
                $this->error = $e->getMessage();
            } finally {
                $this->loading = false; // Content::file auto-observes and redraws!
            }
        });
    }
}
```

### Common `URLSession` operations

<!-- no-test -->
```php
use MyPHP\{URLRequest, URLSession};

// 1. Simple GET returning [string $body, HTTPURLResponse $resp]
[$body, $resp] = URLSession::shared()->data(from: 'https://api.example.com/items');

// 2. GET or POST decoded as JSON array
$json = URLSession::shared()->json(from: 'https://api.example.com/items');

// 3. POST JSON with Bearer token
$req = (new URLRequest('https://api.example.com/items', 'POST'))
    ->setBearerToken($token)
    ->setJSONBody(['title' => 'Buy milk']);
[$body, $resp] = URLSession::shared()->data(for: $req);

// 4. Streaming multipart/form-data file upload (never base64-encode files into JSON)
$upReq = (new URLRequest('https://api.example.com/upload', 'POST'))
    ->setBearerToken($token);
[$body, $resp] = URLSession::shared()->upload(
    for: $upReq,
    from: ['type' => 'avatar', 'file' => '/path/to/photo.jpg'],
);
```

## 2. Low-level `MyPHP\Http` API

For direct low-level requests, `MyPHP\Http` provides `Http::get()`,
`Http::post()`, `Http::head()`, and `Http::request()`:

<!-- no-test -->
```php
use MyPHP\Http;

$r = Http::get('https://example.com/data.json', ['Accept' => 'application/json']);
if ($r->ok()) {
    $data = $r->json();
}
```