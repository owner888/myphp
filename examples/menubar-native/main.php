<?php
// A menu bar app whose icon opens a window of native UI (mygo's
// examples/menubar-native): Quick Notes, a frameless panel beside the icon,
// kept within its display's work area, hidden on a click elsewhere, Escape,
// Cmd+W or Hide; hiding keeps its text, selection and undo history. There is
// no Dock icon; a right click on the icon shows its menu.
// Run: myphp run -d macos examples/menubar-native
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, CloseEvent, Menu, MenuItem, Point, QuitEvent, Screen, Tray, TrayOptions, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/** A PNG of 32 × 32 pixels, black in the rectangles [x0, y0, x1, y1], transparent elsewhere, for macOS to tint as a template. */
function trayIcon(array $rects): string
{
    $raw = '';
    for ($y = 0; $y < 32; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < 32; $x++) {
            $in = false;
            foreach ($rects as [$x0, $y0, $x1, $y1]) { $in = $in || $x >= $x0 && $x < $x1 && $y >= $y0 && $y < $y1; }
            $raw .= $in ? "\0\0\0\xff" : "\0\0\0\0";
        }
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 6, 0, 0, 0)) . $chunk('IDAT', (string)gzcompress($raw)) . $chunk('IEND', '');
}

/** The state, read and changed on the main thread by the tray's callbacks and the view. */
final class Notes
{
    public ?Window $win = null;
    public ?Tray $tray = null;
    /** The note, and whether its editor has the keyboard (focused()), which showing the panel gives it. */
    public string $text = '';
    public bool $editing = false;
    public string $status = '';
    public bool $quitting = false;

    /** Places the panel under the icon on its display, within its work area. */
    public function show(): void
    {
        $anchor = $this->tray->bounds();
        if ($anchor->width === 0 || $anchor->height === 0) {
            $this->win->center();
        } else {
            $area = Screen::displayNearestPoint(new Point(intdiv($anchor->x * 2 + $anchor->width, 2), intdiv($anchor->y * 2 + $anchor->height, 2)))->workArea;
            $b = $this->win->bounds();
            $x = $anchor->x + intdiv($anchor->width - $b->width, 2);
            $y = $anchor->y + $anchor->height + 6;
            if ($y + $b->height > $area->y + $area->height) { $y = $anchor->y - $b->height - 6; }
            $x = max($area->x, min($x, $area->x + max(0, $area->width - $b->width)));
            $y = max($area->y, min($y, $area->y + max(0, $area->height - $b->height)));
            $this->win->setPosition($x, $y);
        }
        $this->editing = true;
        $this->win->show();
    }

    public function toggle(): void
    {
        $this->win->isVisible() ? $this->win->hide() : $this->show();
    }

    public function ready(): void
    {
        $this->tray = new Tray(new TrayOptions(icon: trayIcon([[5, 3, 27, 6], [5, 26, 27, 29], [5, 6, 8, 26], [24, 6, 27, 26], [11, 10, 21, 12], [11, 16, 21, 18], [11, 22, 18, 24]]),
            iconIsTemplate: true, toolTip: 'Quick Notes — click to open'));
        $this->win = new Window(new WindowOptions(title: 'Quick Notes', width: 380, height: 340, hidden: true, frameless: true, disableResize: true,
            disableMinimize: true, disableMaximize: true, disableFullScreen: true, alwaysOnTop: true, content: Content::file(__DIR__ . '/view.php', $this)));
        $this->win->on('blur', fn() => $this->win->hide());
        $this->win->on('close', function (CloseEvent $e) {
            // Cmd+W dismisses the panel; quitting still closes it.
            if (!$this->quitting) { $e->cancel(); $this->win->hide(); }
        });
        $menu = new Menu([new MenuItem('Open Quick Notes', click: fn() => $this->show()), MenuItem::separator(), new MenuItem(role: 'quit')]);
        // A TrayOptions menu would take the primary click: the icon toggles the window, the menu is the right click's.
        $this->tray->onClick($this->toggle(...));
        $this->tray->onRightClick(function () use ($menu) {
            $this->win->hide();
            $this->tray->setMenu($menu); // for this popup only, so that clicks keep toggling the window
            $this->tray->popUpMenu();
            $this->tray->setMenu(null);
        });
        App::onWillQuit(fn(QuitEvent $e) => $this->tray->destroy());
    }
}

$s = new Notes();
App::setName('Quick Notes');
App::setActivationPolicy(ActivationPolicy::Accessory);
App::onBeforeQuit(function (QuitEvent $e) use ($s) { $s->quitting = true; });
App::whenReady($s->ready(...));
App::run();
