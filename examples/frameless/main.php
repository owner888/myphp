<?php
// A window without native chrome (mygo's examples/frameless): the page draws
// its own title bar, marks it draggable with `--app-region: drag` and drives
// the window through window.myphp.window.
// Run: ./run.sh frameless
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const PAGE = <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Frameless</title>
  <style>
    :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
    html, body { height: 100%; margin: 0; }
    body { display: flex; flex-direction: column; background: rgba(128, 128, 128, 0.08); border-radius: 12px; }
    .titlebar {
      --app-region: drag;           /* drag the window by the bar */
      height: 44px; display: flex; align-items: center; gap: 8px; padding: 0 12px;
      background: linear-gradient(90deg, #6366f1, #a855f7); color: white; user-select: none;
    }
    .titlebar .title { flex: 1; font-weight: 600; }
    .titlebar button {             /* buttons stay clickable */
      width: 28px; height: 28px; border: 0; border-radius: 6px;
      background: rgba(255, 255, 255, 0.2); color: white; font-size: 14px; cursor: pointer;
    }
    .titlebar button:hover { background: rgba(255, 255, 255, 0.35); }
    main { flex: 1; display: grid; place-content: center; text-align: center; padding: 24px; }
  </style>
</head>
<body>
  <div class="titlebar">
    <span class="title">Custom title bar</span>
    <button id="min" title="Minimize">–</button>
    <button id="max" title="Maximize">□</button>
    <button id="close" title="Close">✕</button>
  </div>
  <main>
    <h2>No native frame</h2>
    <p>Drag the gradient bar to move the window.<br>Double-click it to zoom.</p>
  </main>
  <script>
    const w = myphp.window;
    document.getElementById("min").onclick = () => w.minimize();
    document.getElementById("max").onclick = () => w.toggleMaximize();
    document.getElementById("close").onclick = () => w.close();
  </script>
</body>
</html>
HTML;

App::setName('Frameless');
App::whenReady(function () {
    // A translucent material behind the transparent page.
    new Window(new WindowOptions(width: 640, height: 420, minWidth: 360, minHeight: 240, frameless: true, transparent: true, vibrancy: 'under-window', html: PAGE));
});
App::run();
