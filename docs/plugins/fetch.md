# Fetch

The fetch plugin gives pages a `fetch` that makes HTTP requests from PHP.
It takes the options of the standard `fetch` and returns a standard
`Response`, but the request leaves from the PHP side of the app, so:

- it is not subject to CORS: any server answers, and every response header
  is readable but `Set-Cookie`, which a `Response` cannot hold;
- it may set any header, including those the webview forbids, such as
  `Origin`, `Cookie`, `Referer` and `User-Agent`;
- its body streams to the page as PHP reads it, and aborting it cancels
  the request in PHP.

## Set up

Use the plugin in PHP:

<!-- no-test -->
```php
use MyPHP\App;
use MyPHP\Plugins\Fetch\Fetch;

App::use(Fetch::plugin());
// ...
App::run();
```

and add its package to the frontend:

```sh
bun add @myphp-plugins/fetch
```

Calling its `fetch` in an app that does not use the PHP half rejects with
an error that says to call `App::use(Fetch::plugin())`.

## Making requests

Import `fetch` from the package and call it as you would the webview's:

```ts
import { fetch } from "@myphp-plugins/fetch";

const res = await fetch("https://api.example.com/items", {
  method: "POST",
  headers: {
    authorization: `Bearer ${token}`,
    "content-type": "application/json",
  },
  body: JSON.stringify({ name: "Notes" }),
});
if (!res.ok) throw new Error(`HTTP ${res.status}`);
const item = await res.json();
```

A `Request` works in place of the URL. Bodies are those of the standard
`fetch`, such as strings, `FormData`, `URLSearchParams`, `Blob`s and
`ArrayBuffer`s, with the `Content-Type` it would give them; they are read
whole before the request starts, streams too.

Libraries that take a `fetch` of their own, as many API clients do, can be
given this one. Do not assign it to `globalThis.fetch`: the plugin hands
the URLs it does not make, those whose scheme is not http or https, to the
webview's `fetch`.

## Streaming responses

The body of the response is a `ReadableStream` that PHP fills as the
server sends it, which suits server-sent events and the streamed answers
of model APIs:

```ts
const res = await fetch("https://api.example.com/events");
const reader = res.body!.pipeThrough(new TextDecoderStream()).getReader();
for (;;) {
  const { value, done } = await reader.read();
  if (done) break;
  output.append(value);
}
```

The requests are libcurl's, driven on the app's run loop, so a request
never blocks the app.

## Canceling

Aborting the request's `signal` cancels it in PHP, before or after the
response arrived:

```ts
const ctrl = new AbortController();
const timer = setTimeout(() => ctrl.abort(), 10_000);
try {
  const res = await fetch(url, { signal: ctrl.signal });
  return await res.text();
} finally {
  clearTimeout(timer);
}
```

The promise, or the reading of the body, rejects with the signal's reason.
Canceling the body (`res.body.cancel()`), navigating away and closing the
window cancel the request too.

## Errors

As with the webview's `fetch`, a response is not an error, whatever its
status: check `res.ok`. The promise rejects with a `TypeError` when the
request fails, such as when the server cannot be reached, TLS fails or
there are too many redirects, and its message says why:

```ts
try {
  await fetch("https://unreachable.example");
} catch (err) {
  console.log(err.message); // fetch failed: Couldn't resolve host name
}
```

## Differences from the webview's fetch

- `mode`, `credentials`, `cache`, `referrerPolicy`, `integrity`,
  `keepalive` and `priority` do not apply and are ignored.
- Cookies are never the webview's: the plugin keeps none between requests;
  set `Cookie` yourself.
- `redirect: "manual"` returns the redirect response itself, with its
  status and `Location`, instead of an opaque response. `"error"` rejects
  on a redirect, and `"follow"`, the default, follows up to 20 redirects
  and sets `res.redirected` and `res.url`.
- URLs whose scheme is not http or https, such as the app's own pages in
  builds (`myphp://localhost`), `data:` and `blob:` URLs, go to the
  webview's `fetch`. Relative URLs resolve against the page's: during
  `myphp dev` they are those of the dev server, which PHP fetches.

## From PHP or native UI

The plugin is the page's `fetch`. PHP, and so native UI, makes HTTP
requests with `MyPHP\Http` (a mygo app uses Go's `net/http` in a
goroutine; there is no plugin to use): libcurl's, driven by the run loop as
the plugin's are, through the system's proxy, redirects followed and what
the server compresses undone.

- `Http::get($url, $headers = [])` and
  `Http::request($method, $url, $headers = [], $body = null, $timeout = 0)`
  return an `HttpResponse`: `status`, `body`, `headers` (by their names in
  lower case), `ok()` for a 2xx status, `json()` for the body decoded.
  `$headers` is `['Name' => 'value']`; `$timeout` is in seconds, 0 for no
  limit.
- A response of any status is returned. What keeps the request from being
  made or answered (a name that does not resolve, a refused connection,
  TLS, the timeout) throws a `RuntimeException` with curl's reason.
- Called in a fiber of `App::go()`, the request suspends the fiber until
  the response came, and the app goes on drawing and taking events. Called
  on the main thread outside a fiber, it turns the run loop until the
  response came (`App::wait()`): the view is built meanwhile, but the code
  after the call waits, so make requests in a fiber. Before `App::run()`
  it simply blocks.

A view that loads what it shows, a runnable app:

<!-- no-test -->
```php
<?php
require 'vendor/autoload.php'; // or the framework's src/autoload.php
use MyPHP\{App, Http, Window, WindowOptions};
use MyPHP\UI\Content;

$repo = ['state' => 'loading', 'stars' => 0, 'error' => ''];
App::whenReady(function () use (&$repo) {
    $win = new Window(new WindowOptions(title: 'Stars', width: 360, height: 120,
        content: Content::view(function () use (&$repo) {
            return match ($repo['state']) {
                'loading' => ProgressView(),
                'done' => Text(verbatim: "owner888/myphp has {$repo['stars']} stars"),
                'failed' => Text(verbatim: "Could not load it: {$repo['error']}"),
            };
        })));
    App::go(function () use (&$repo, $win) {
        try {
            $r = Http::get('https://api.github.com/repos/owner888/myphp', ['Accept' => 'application/vnd.github+json']);
            $next = $r->ok() ? ['state' => 'done', 'stars' => $r->json()['stargazers_count'], 'error' => '']
                : ['state' => 'failed', 'stars' => 0, 'error' => "HTTP {$r->status}"];
        } catch (RuntimeException $e) {
            $next = ['state' => 'failed', 'stars' => 0, 'error' => $e->getMessage()];
        }
        $win->update(function () use (&$repo, $next) { $repo = $next; }); // changes the state, then draws
    });
});
App::run();
```

Several requests at once each run in a fiber of their own; their
transfers go on together. A response is read whole into `body`: for a
download to show progress, or one too large to hold, use the page's
`fetch` of this plugin, or a `MyPHP\Process` running curl.

## Options

`Fetch::plugin()` makes requests through the system's proxy settings, as
the webview does (see [proxies](../native.md#proxies)), and lets pages
request any URL. `Fetch::new()` takes options instead:

<!-- no-test -->
```php
use MyPHP\Plugins\Fetch\{Fetch, Options, Request};

App::use(Fetch::new(new Options(
    allow: fn(Request $r): bool => parse_url($r->url, PHP_URL_HOST) === 'api.example.com',
)));
```

`allow` sees each request before PHP makes it, with its `url`, `method`
and `headers`, and decides whether pages may make it: a request it refuses
rejects with an error. It sees the first request only, not the redirects
that follow.

Only the app's own pages may call the plugin, as with every bound method
(see [who may call](../bindings.md#who-may-call)); `allow` keeps a page
that runs code it should not, such as through a cross-site scripting bug,
from reaching other servers.
