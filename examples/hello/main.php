<?php
// The smallest MyPHP app (mygo's examples/hello): one window and one PHP
// method called from the page. A project would have `myphp dev` write a
// typed client for it; this one calls the untyped window.myphp.call to stay
// in a single file.
// Run: myphp run -d macos examples/hello
declare(strict_types=1);

use MyPHP\{App, Ipc, Window, WindowOptions};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/** Callable from the page. */
final class Greeter
{
    /** A greeting. */
    public function greet(string $name): string
    {
        return "Hello $name, from PHP " . PHP_VERSION . '!';
    }
}

const PAGE = <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Hello MyPHP</title>
  <style>
    :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
    body { height: 100vh; margin: 0; display: grid; place-content: center; text-align: center; }
  </style>
</head>
<body>
  <h1>Hello MyPHP</h1>
  <p id="greeting">…</p>
  <script>
    myphp.call("Greeter.greet", "world").then((text) => {
      document.getElementById("greeting").textContent = text;
    });
  </script>
</body>
</html>
HTML;

Ipc::bind(new Greeter());
App::setName('Hello');
App::whenReady(function () {
    new Window(new WindowOptions(width: 480, height: 320, html: PAGE));
});
App::run();
