<?php
// A WebSocket from a web page, through the PHP plugin: the page talks to
// "plugin:websocket" as the plugin's TypeScript client does
// (src/Plugins/WebSocket/client/index.ts), here in plain JavaScript over
// myphp.call and a Channel: connect streams what happens, send writes a
// message in its turn. The handshake carries an Authorization header the
// browser's WebSocket could not send. The default server echoes.
// Run: ./run.sh websocket [wss://url]
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\WebSocket\WebSocket;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

App::use(WebSocket::plugin());
$url = $argv[1] ?? 'wss://ws.postman-echo.com/raw';

$html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<style>
  body { font: 15px -apple-system, system-ui; margin: 0; padding: 24px; color: #1d1d1f; }
  form { display: flex; gap: 8px; margin-bottom: 12px; }
  input { flex: 1; font: inherit; padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; }
  button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px; background: #3377ff; color: #fff; }
  #log { font: 13px ui-monospace, monospace; white-space: pre-wrap; }
</style>
<form id="conn"><input id="url"><button>Connect</button><button id="close" type="button">Close</button></form>
<form id="msg"><input id="text" placeholder="A message" value="hello"><button>Send</button></form>
<div id="log"></div>
<script>
  url.value = __URL__;
  const say = (s) => log.textContent = s + "\n" + log.textContent;
  let id = null, seq = 0;
  conn.onsubmit = async (e) => {
    e.preventDefault();
    id = crypto.randomUUID(); seq = 0;
    const events = new myphp.Channel();
    events.onmessage = (ev) => {
      if (ev.type === "open") say("open" + (ev.protocol ? " (" + ev.protocol + ")" : ""));
      else if (ev.type === "text") say("← " + ev.text);
      else if (ev.type === "binary") say("← " + atob(ev.data ?? "").length + " bytes");
      else say(`closed ${ev.code}${ev.reason ? " " + ev.reason : ""}${ev.clean ? ", clean" : ""}${ev.error ? ": " + ev.error : ""}`);
    };
    say("connecting to " + url.value);
    await myphp.call("plugin:websocket.connect", { id, url: url.value, protocols: [], headers: [["authorization", "Bearer from-php"]] }, events);
  };
  msg.onsubmit = (e) => {
    e.preventDefault();
    if (!id) return;
    say("→ " + text.value);
    myphp.call("plugin:websocket.send", id, ++seq, { type: "text", text: text.value }).catch((x) => say(x.message));
  };
  close.onclick = () => id && myphp.call("plugin:websocket.send", id, ++seq, { type: "close", code: 1000, reason: "bye" }).catch((x) => say(x.message));
</script>
HTML;

App::setName('WebSocket');
App::whenReady(function () use ($html, $url) {
    new Window(new WindowOptions(title: 'MyPHP — a WebSocket from a page', width: 620, height: 520, html: str_replace('__URL__', json_encode($url), $html)));
});
App::run();
