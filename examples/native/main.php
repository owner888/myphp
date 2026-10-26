<?php
// Native tours the desktop integration from a web page (mygo's
// examples/native): application and context menus, a tray icon, dialogs,
// notifications, a global shortcut, the clipboard and dark mode. The page
// calls PHP's Native service; PHP tells it what happened by the "log"
// event. The app keeps running in the tray once its window is closed.
// Run: ./run.sh native
declare(strict_types=1);

use MyPHP\{App, CallContext, Clipboard, Dialog, Event, GlobalShortcut, Ipc, Menu, MenuItem, MessageOptions, Notification, NotificationOptions, OpenDialogOptions, Screen, Theme, ThemeSource, Tray, TrayOptions, Window, WindowOptions};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** Called from the page. */
final class Native
{
    /** Lets the user pick files, as a sheet of the window calling, and returns their paths. @return list<string> */
    public function openFiles(CallContext $ctx): array
    {
        return Dialog::open(new OpenDialogOptions(parent: $ctx->window(), title: 'Pick some files', multiple: true));
    }

    /** Asks a yes/no question. */
    public function confirm(CallContext $ctx, string $question): bool
    {
        return Dialog::message(new MessageOptions(parent: $ctx->window(), type: 'question', message: $question,
            detail: 'This dialog is native.', buttons: ['Yes', 'No']))->button === 0;
    }

    /** Puts text on the clipboard. */
    public function copy(string $text): void
    {
        Clipboard::writeText($text);
    }

    /** Reads text from the clipboard. */
    public function paste(): string
    {
        return Clipboard::readText();
    }

    /** Shows a desktop notification. */
    public function notify(string $title, string $body): void
    {
        if (!Notification::supported()) {
            throw new RuntimeException('notifications need a packaged app (myphp dev or myphp build)');
        }
        (new Notification(new NotificationOptions(title: $title, body: $body)))->show();
    }

    /** Pops up a native context menu at the mouse. */
    public function showContextMenu(CallContext $ctx): void
    {
        $win = $ctx->window();
        if ($win === null) { return; }
        (new Menu([
            new MenuItem('Reload', fn() => $win->page()?->reload()),
            new MenuItem('Toggle Developer Tools', fn() => $win->page()?->toggleDevTools()),
            MenuItem::separator(),
            new MenuItem(role: 'copy'),
            new MenuItem(role: 'paste'),
        ]))->popup($win);
    }

    /** Switches between the light and the dark appearance. */
    public function setDark(bool $dark): void
    {
        Theme::setSource($dark ? ThemeSource::Dark : ThemeSource::Light);
    }
}

const PAGE = <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Native</title>
  <style>
    :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
    body { margin: 24px; }
    button { font: inherit; margin: 0 6px 8px 0; padding: 6px 12px; }
    pre { background: rgba(128,128,128,.12); padding: 12px; border-radius: 8px; min-height: 120px; white-space: pre-wrap; }
  </style>
</head>
<body>
  <h2>Native integration</h2>
  <p>Right-click anywhere for a native context menu. Try the tray icon and ⌘⇧Space.</p>
  <button id="open">Open files…</button>
  <button id="confirm">Ask a question</button>
  <button id="copy">Copy text</button>
  <button id="paste">Paste text</button>
  <button id="notify">Notify</button>
  <button id="dark">Toggle dark mode</button>
  <pre id="log"></pre>
  <script>
    const log = (s) => (document.getElementById("log").textContent += s + "\n");
    const call = (m, ...a) => myphp.call("Native." + m, ...a).catch((e) => log("error: " + e.message));
    myphp.on("log", log);
    let dark = matchMedia("(prefers-color-scheme: dark)").matches;
    document.getElementById("open").onclick = async () => log("picked: " + JSON.stringify(await call("openFiles")));
    document.getElementById("confirm").onclick = async () => log("answer: " + await call("confirm", "Do you like MyPHP?"));
    document.getElementById("copy").onclick = () => call("copy", "Copied from MyPHP at " + new Date().toLocaleTimeString()).then(() => log("copied"));
    document.getElementById("paste").onclick = async () => log("clipboard: " + await call("paste"));
    document.getElementById("notify").onclick = () => call("notify", "Hello", "From MyPHP");
    document.getElementById("dark").onclick = () => call("setDark", (dark = !dark));
    addEventListener("contextmenu", (e) => { e.preventDefault(); call("showContextMenu"); });
  </script>
</body>
</html>
HTML;

/** The tray's icon: a 32×32 template image, a filled circle. */
function trayIcon(): string
{
    $rows = '';
    for ($y = 0; $y < 32; $y++) {
        $rows .= "\0"; // no filter
        for ($x = 0; $x < 32; $x++) {
            $dx = $x - 16; $dy = $y - 16;
            $rows .= "\0\0\0" . ($dx * $dx + $dy * $dy <= 12 * 12 ? "\xff" : "\0");
        }
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '');
}

Ipc::bind(new Native());
$log = new Event('log', 'string'); // sent to the page for anything worth showing

App::setName('Native');
App::whenReady(function () use ($log) {
    $win = null;
    $show = function () use (&$win): void {
        if ($win === null || $win->isDestroyed()) {
            $win = new Window(new WindowOptions(title: 'Native', width: 720, height: 520, html: PAGE));
        }
        $win->show();
    };
    $logf = function (string $format, mixed ...$args) use (&$win, $log): void {
        if ($win !== null && !$win->isDestroyed()) { $log->emit($win, sprintf($format, ...$args)); }
    };

    App::setMenu(new Menu([
        new MenuItem(role: 'appMenu'),
        new MenuItem(role: 'editMenu'),
        new MenuItem('Demo', submenu: [
            new MenuItem('Say Hi', fn() => $logf('Hi from the menu!'), 'CmdOrCtrl+Shift+H'),
            new MenuItem('Always on Top', fn(MenuItem $item, ?Window $w) => $w?->setAlwaysOnTop($item->checked), type: MenuItem::CHECKBOX, id: 'always-on-top'),
            MenuItem::separator(),
            new MenuItem('Small', fn(MenuItem $item, ?Window $w) => $w?->setSize(720, 520), type: MenuItem::RADIO, checked: true),
            new MenuItem('Large', fn(MenuItem $item, ?Window $w) => $w?->setSize(1000, 720), type: MenuItem::RADIO),
        ]),
        new MenuItem(role: 'viewMenu'),
        new MenuItem(role: 'windowMenu'),
    ]));

    try {
        new Tray(new TrayOptions(icon: trayIcon(), iconIsTemplate: true, toolTip: 'MyPHP Native', menu: new Menu([
            new MenuItem('Show Window', fn() => $show()),
            new MenuItem('What time is it?', fn() => $logf("It's %s", date('g:iA'))),
            MenuItem::separator(),
            new MenuItem(role: 'quit'),
        ])));
    } catch (Throwable $e) {
        fwrite(STDERR, 'tray: ' . $e->getMessage() . "\n");
    }

    try {
        GlobalShortcut::register('CmdOrCtrl+Shift+Space', function () use ($show, $logf): void {
            $show();
            $logf('global shortcut pressed');
        });
    } catch (Throwable $e) {
        fwrite(STDERR, 'shortcut: ' . $e->getMessage() . "\n");
    }

    Theme::onUpdated(fn() => $logf('appearance is now %s', Theme::isDark() ? 'dark' : 'light'));
    $show();
    $d = Screen::primaryDisplay();
    $logf('primary display: %s %dx%d @%gx', trim($d->label), $d->bounds->width, $d->bounds->height, $d->scaleFactor); // held until the page is ready
});
// Keep running in the tray after the window is closed.
App::onWindowAllClosed(function () {});
App::run();
