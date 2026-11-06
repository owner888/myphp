<?php
// A menu bar app whose icon opens a flyout, the panel of the app, in a
// popover of the system's below the icon (mygo's examples/menubar-flyout).
// It closes as the user clicks elsewhere, presses Escape or clicks the icon
// again; a right click shows its menu.
// Run: ./run.sh menubar-flyout
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, FlyoutOptions, Menu, MenuItem, QuitEvent, Tray, TrayOptions, Window};
use MyPHP\UI\Content;

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

/** A PNG of 32 × 32 pixels, black where $ink says, transparent elsewhere, for macOS to tint as a template. */
function trayIcon(Closure $ink): string
{
    $raw = '';
    for ($y = 0; $y < 32; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < 32; $x++) { $raw .= $ink($x, $y) ? "\0\0\0\xff" : "\0\0\0\0"; }
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 6, 0, 0, 0)) . $chunk('IDAT', (string)gzcompress($raw)) . $chunk('IEND', '');
}

/** The app's state, read and changed on the main thread by the tray's callbacks and the view. */
final class Focus
{
    public ?Tray $tray = null;
    /** The open panel, or null. */
    public ?Window $panel = null;
    public bool $focus = false, $sound = true;
    public float $minutes = 25;

    /** Opens the panel under the icon, or closes it. */
    public function toggle(): void
    {
        if ($this->panel !== null) { $this->panel->close(); return; }
        $this->panel = Window::flyout(new FlyoutOptions(tray: $this->tray, placement: FlyoutOptions::BOTTOM, gap: 4, width: 280, height: 230,
            focusable: true, shadow: true, popover: true, content: Content::file(__DIR__ . '/view.php', $this)));
        $this->panel->on('closed', function () { $this->panel = null; });
    }

    public function ready(): void
    {
        // A target.
        $this->tray = new Tray(new TrayOptions(icon: trayIcon(function (int $x, int $y) {
            $d = ($x - 15.5) ** 2 + ($y - 15.5) ** 2;
            return $d < 15 * 15 && $d > 11 * 11 || $d < 7 * 7 && $d > 4 * 4 || $d < 1.5 * 1.5;
        }), iconIsTemplate: true, toolTip: 'Focus'));
        $menu = new Menu([new MenuItem('Open Focus', click: fn() => $this->toggle()), MenuItem::separator(), new MenuItem(role: 'quit')]);
        $this->tray->onClick($this->toggle(...));
        $this->tray->onRightClick(function () use ($menu) {
            $this->panel?->close();
            // The menu for this popup only, so that clicks keep toggling the panel.
            $this->tray->setMenu($menu);
            $this->tray->popUpMenu();
            $this->tray->setMenu(null);
        });
        App::onWillQuit(fn(QuitEvent $e) => $this->tray->destroy());
    }
}

$s = new Focus();
App::setName('Focus');
App::setActivationPolicy(ActivationPolicy::Accessory);
App::onWindowAllClosed(function () {}); // the panel is the app's only window: keep running as it closes
App::whenReady($s->ready(...));
App::run();
