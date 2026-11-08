# Network, disk and other slow work

PHP runs the app on one thread: the view, input and every callback share
it. Anything that waits must wait in a fiber, so the window keeps drawing.

## HTTP

`MyPHP\Http` (`docs/plugins/fetch.md`, "From PHP or native UI") is the
client for PHP and native UI; the fetch plugin itself is for web pages.

```php
use MyPHP\{App, Http};

App::go(function () use ($app, $win, $query, $seq) {
    try {
        $r = Http::get('https://api.github.com/search/repositories?q=' . rawurlencode($query),
            ['Accept' => 'application/vnd.github+json']);
        $data = $r->ok() ? $r->json() : null;
        $error = $r->ok() ? null : "HTTP {$r->status}";
    } catch (\RuntimeException $e) { // no connection, DNS, timeout
        [$data, $error] = [null, $e->getMessage()];
    }
    $win->update(function () use ($app, $data, $error, $seq) {
        if ($seq !== $app->seq) { return; } // a newer query was sent: drop this answer
        [$app->results, $app->error, $app->loading] = [$data['items'] ?? [], $error, false];
    });
});
```

- Inside `App::go()` a request suspends only its fiber; the window draws
  and takes input meanwhile. Outside a fiber it turns the run loop until
  the answer comes; before `App::run()` it blocks.
- Every status comes back as an `HttpResponse` (`status`, `body`,
  `headers` with lower-case names, `ok()`, `json()`); failures to reach the
  server throw `RuntimeException`. `Http::request($method, $url, $headers,
  $body, $timeout)` sends anything else.
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

## SQLite and files

`Sqlite::open($path)` (`docs/plugins/sqlite.md`) returns a `DB` whose
`execute()` and `query()` run synchronously on the main thread: fine for
the small queries of a local database, but not for scans of large
tables in the middle of a frame. Keep data in
`App::path(PathName::UserData)`, and call `App::setName()` before the
first `App::path()`: the folder is named after the app.
