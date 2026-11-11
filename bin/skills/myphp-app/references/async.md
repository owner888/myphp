# Network, disk and other slow work

PHP runs the app on one thread: the view, input and every callback share
it. Anything that waits must wait in a fiber, so the window keeps drawing.

## Networking with URLSession (SwiftUI / Foundation first)

Always write networking in SwiftUI / Foundation style using `URLSession::shared()`,
`URLRequest`, and `HTTPURLResponse` (globally aliased in declarative apps, or
`use MyPHP\{URLSession, URLRequest, HTTPURLResponse};`). Reach for the low-level
`MyPHP\Http` transport only when a low-level custom protocol option is needed.
The fetch plugin itself is for web pages.

```php
use MyPHP\{App, URLRequest, URLSession};

// 1. Simple GET / JSON / typed model decoding:
[$body, $resp] = URLSession::shared()->data(from: 'https://api.example.com/items');
$items = URLSession::shared()->json(from: 'https://api.example.com/items');
$user = URLSession::shared()->decode(
    User::class,
    for: (new URLRequest('https://api.example.com/me'))->setBearerToken($token),
);

// 2. POST JSON with URLRequest:
$req = (new URLRequest('https://api.example.com/login', 'POST'))
    ->setBearerToken($apiKey)
    ->setJSONBody(['account' => $account, 'password' => $password]);
[$body, $resp] = URLSession::shared()->data(for: $req);

// 3. Multipart file / photo upload (streams file from path, never base64-encode in JSON):
$uploadReq = (new URLRequest('https://api.example.com/uploads', 'POST'))
    ->setBearerToken($apiKey)
    ->setValue($teacherToken, 'X-Teacher-Token');
[$body, $resp] = URLSession::shared()->upload(
    for: $uploadReq,
    from: ['kind' => 'schedule', 'file' => $localFilePath],
);

// 4. Inside App::go() fiber with state update:
App::go(function () use ($app, $win, $query, $seq) {
    try {
        $req = new URLRequest(
            'https://api.github.com/search/repositories?q=' . rawurlencode($query),
            headers: ['Accept' => 'application/vnd.github+json'],
        );
        [$body, $resp] = URLSession::shared()->data(for: $req);
        $data = $resp->ok() ? json_decode($body, true) : null;
        $error = $resp->ok() ? null : "HTTP {$resp->statusCode}";
    } catch (\RuntimeException $e) { // no connection, DNS, timeout
        [$data, $error] = [null, $e->getMessage()];
    }
    $win->update(function () use ($app, $data, $error, $seq) {
        if ($seq !== $app->seq) { return; } // a newer query was sent: drop this answer
        [$app->results, $app->error, $app->loading] = [$data['items'] ?? [], $error, false];
    });
});
```

- Inside `App::go()` (or a view's `->task()`) a request suspends only its
  fiber; the window draws and takes input meanwhile. Outside a fiber it
  turns the run loop until the answer comes; before `App::run()` it blocks.
- `URLSession::shared()->data()` and `upload()` return `[$body, $resp]`
  where `$resp` is an `HTTPURLResponse` (`statusCode`, `allHeaderFields`,
  `value(forHTTPHeaderField:)`, `ok()`); failures to reach the server throw
  `RuntimeException`.
- For remote images (avatars, covers, photos), always use the declarative
  `AsyncImage($url, content: fn($img) => $img->resizable()->scaledToFill(), placeholder: fn() => ...)`
  view rather than manually downloading bytes into `Image::fromBytes()`.
- Change state from a fiber only through `$window->update(fn() => ...)`,
  which also draws the next frame.

## Stale answers, debouncing, states

- Number every request (`$app->seq++` as it is sent) and drop answers
  whose number is not the latest: answers can arrive out of order, and
  the window may have closed (`$win->isDestroyed()`).
- Debounce typing: `TextField('Search', text: $this->query)->onChange(of:
  $this->query, action: fn() => $this->typed())`, where `typed()` numbers
  the query (`$seq = ++$this->seq`) and starts `App::go(function () use
  ($seq) { App::sleep(0.35); if ($seq !== $this->seq) { return; } … })`:
  a newer keystroke makes the waiting one give up.
- Show every state the work can be in: idle, loading, an error with a way
  to retry, empty results, results.

## Testing it

- Put requests behind a small interface of your own (`search($q):
  array`), so a headless test hands the view answers directly, in any
  order, and checks loading, stale answers and errors without a network.
- Test the real client against a local server of saved responses
  (`php -S 127.0.0.1:8765 router.php`), with the base URL read from an
  environment variable, so tests and screenshots never hit the real API.
- In a test without a running app, `App::wait()` turns the loop until a
  condition holds (`docs/ui/testing.md`).

## Child processes

`MyPHP\Process::run($argv, $stdin, fn($out, $err, $code) => ...)` runs a
program without blocking, its callback on the main thread; `kill()`
stops it (`docs/native.md`, Processes).

## Secrets, SQLite and files

- **Secrets (tokens, API keys, passwords)**: `MyPHP\Secrets::set($service, $account, $secret)`,
  `Secrets::get($service, $account)`, `Secrets::delete($service, $account)`
  store credentials in the OS keychain (macOS/iOS Keychain, Windows Credential
  Manager, Linux Secret Service, Android AES-256-GCM encrypted store).
- **SQLite and files**: `Sqlite::open($path)` (`docs/plugins/sqlite.md`)
  returns a `DB` whose `execute()` and `query()` run synchronously on the
  main thread: fine for the small queries of a local database, but not for
  scans of large tables in the middle of a frame. Keep data in
  `App::path(PathName::UserData)`, and call `App::setName()` before the
  first `App::path()`: the folder is named after the app.
