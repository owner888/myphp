# WebSocket

The WebSocket plugin gives pages a `WebSocket` whose connections PHP
makes, with the API of the browser's. Unlike the webview's own, it:

- sends any header with the opening handshake, such as `Authorization`,
  `Cookie` or `Origin`, for servers that want a token in a header;
- is not subject to the page's Content Security Policy;
- keeps the messages of a fast server in PHP while the page is behind,
  instead of in the page's memory.

## Set up

Use the plugin in PHP:

<!-- no-test -->
```php
use MyPHP\App;
use MyPHP\Plugins\WebSocket\WebSocket;

App::use(WebSocket::plugin());
// ...
```

and add its package to the frontend:

```sh
bun add @myphp-plugins/websocket
```

A connection opened in an app that does not use the PHP half fails, and
the console says to call `App::use()`.

## Connecting

Import `WebSocket` from the package and use it as the browser's:

```ts
import { WebSocket } from "@myphp-plugins/websocket";

const ws = new WebSocket("wss://example.com/live", ["v1"], {
  headers: { authorization: `Bearer ${token}` },
});
ws.binaryType = "arraybuffer";
ws.onopen = () => ws.send(JSON.stringify({ subscribe: "prices" }));
ws.onmessage = (e) => console.log(e.data);
ws.onclose = (e) => console.log(e.code, e.reason, e.wasClean);
```

The first two arguments are those of the browser's `WebSocket`: the URL,
`ws:` or `wss:` (`http:` and `https:` become them, and relative URLs
resolve against the page's), and the subprotocols, of which the server
picks one, `ws.protocol` once open. The third is the plugin's: `headers`,
the headers of the opening handshake, as `Headers`, an object or pairs.

The rest is the browser's API: `readyState` and its constants, `send` of
strings, `ArrayBuffer`s, typed arrays and `Blob`s, `close(code, reason)`,
`binaryType` (`"blob"` by default, or `"arraybuffer"`), and the `open`,
`message`, `error` and `close` events, as `on…` properties or with
`addEventListener`.

Libraries that take a `WebSocket` class, as GraphQL and realtime clients
often do, create connections with two arguments; give them a class that
adds the headers:

```ts
class AuthWebSocket extends WebSocket {
  constructor(url: string | URL, protocols?: string | string[]) {
    super(url, protocols, { headers: { authorization: `Bearer ${token}` } });
  }
}
```

## Sending and receiving

- `send` throws while the connection is still opening, and messages sent
  after it closed are discarded, as with the browser's. Messages go out in
  the order they were sent.
- `bufferedAmount` counts the bytes passed to `send` that PHP has not
  written yet: a page that sends a lot can wait for it to drop.
- Messages from the server wait in PHP until the page takes them, so a
  server faster than the page cannot fill its memory. A message bigger
  than `maxMessageSize`, 64 MiB by default, fails the connection.
- PHP answers the server's pings.

## Closing

`close(code, reason)` takes a code of 1000 or between 3000 and 4999, and a
reason of at most 123 bytes, as the browser's does. PHP then waits for the
server to finish the closing handshake: when it does, the `close` event
has the server's code and reason, and `wasClean` is true; when it has not
after `closeTimeout` (5 seconds by default), PHP drops the connection, and
the event has code 1006.

A connection closes on its own, telling the server it is going away (code
1001), when its page navigates away or its window closes.

When a connection fails, because the server cannot be reached, answers the
handshake without upgrading, redirects it or breaks the protocol, the page
gets an `error` event, then a `close` event with code 1006, and the console
says why, as with the browser's.

## Differences from the browser's WebSocket

- The constructor takes a third argument, the headers of the handshake.
- The page's Content Security Policy (`connect-src`) does not apply, and
  neither do the webview's cookies: send `Cookie` yourself.
- No extensions, such as permessage-deflate, are negotiated: `extensions`
  is always empty.

## Options

`WebSocket::plugin()` opens connections to any URL through the system's
proxy settings (see [proxies](../native.md#proxies)). `WebSocket::new()`
takes options instead:

<!-- no-test -->
```php
use MyPHP\Plugins\WebSocket\{Options, Request, WebSocket};

App::use(WebSocket::new(new Options(
    allow: fn(Request $r): bool => parse_url($r->url, PHP_URL_HOST) === 'live.example.com',
    maxMessageSize: 16 << 20, // 16 MiB; default 64 MiB
    closeTimeout: 2,          // seconds; default 5
)));
```

- `allow` sees the handshake request of each connection, with its `url`
  and `headers`, and decides whether pages may open it; a connection it
  refuses fails.
- `maxMessageSize` is the most bytes a message from the server may have; a
  bigger one fails the connection.
- `closeTimeout` is how long a connection that the page closes waits for
  the server to finish the closing handshake.

Only the app's own pages may call the plugin, as with every bound method
(see [who may call](../bindings.md#who-may-call)).
