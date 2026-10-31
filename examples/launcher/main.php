<?php
// An app launcher in the manner of Spotlight and Raycast, in native UI
// (mygo's examples/launcher): Alt+Shift+Space shows a panel over every app,
// full-screen ones included, without taking the active app away; type to
// find an installed app, Up and Down to choose, Enter to open it, Escape to
// clear or close. It lives in the menu bar, without a Dock icon.
// Run: ./run.sh launcher
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, CloseEvent, GlobalShortcut, InstalledApp, Menu, MenuItem, QuitEvent, Screen, Shell, Tray, TrayOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Handle, Image, Key, ListState, Node, SVG, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** Tried in turn: the first one free is the app's. Not Alt+Space, which Raycast and others take: macOS gives a shortcut to every app that registers it. */
const SHORTCUTS = ['Alt+Shift+Space', 'CmdOrCtrl+Alt+Shift+Space'];
const WIDTH = 680;
const HEIGHT = 420;
const ICON_SIZE = 28;
const SEARCH_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>';

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

/** How well $name matches the query $q: names starting with it first, then those with a word starting with it, those containing it, and those with its letters in order ("vsc" finds Visual Studio Code); -1 for none. */
function rank(string $name, string $q): int
{
    if ($q === '' || str_starts_with($name, $q)) { return 0; }
    if (str_contains($name, " $q") || str_contains($name, "-$q")) { return 1; }
    if (str_contains($name, $q)) { return 2; }
    $at = 0;
    foreach (mb_str_split($q) as $ch) {
        $i = strpos($name, $ch, $at);
        if ($i === false) { return -1; }
        $at = $i + strlen($ch);
    }
    return 3;
}

/** Where an app is, shortly: its folder, ~ for the home's, System for the system's. */
function where(InstalledApp $app): string
{
    $dir = dirname($app->path);
    $home = (string)getenv('HOME');
    if ($home !== '' && str_starts_with($dir, $home)) { return '~' . substr($dir, strlen($home)); }
    foreach (['/System/Applications', '/System/Library/CoreServices/Applications', '/System/Library/CoreServices'] as $system) {
        if (str_starts_with($dir, $system)) { return 'System' . substr($dir, strlen($system)); }
    }
    return ltrim($dir, '/');
}

/** A shortcut spelled as macOS does: ⌥⇧Space. */
function keys(string $shortcut): string
{
    return strtr($shortcut, ['CmdOrCtrl+' => '⌘', 'Cmd+' => '⌘', 'Ctrl+' => '⌃', 'Alt+' => '⌥', 'Shift+' => '⇧']);
}

/** What the panel shows, read and changed on the main thread by the view and the callbacks of the app, the tray and the shortcut. */
final class Launcher
{
    public ?Window $panel = null;
    public ?Tray $tray = null;
    public string $shortcut = '';
    /** @var list<InstalledApp> */
    public array $apps = [], $results = [];
    public TextState $query;
    public ListState $list;
    public Handle $input;
    /** @var array<string, ?Image> by app path; null where the app has none */
    public array $icons = [];
    public bool $loading = false;
    public SVG $searchIcon;

    public function __construct()
    {
        $this->query = new TextState();
        $this->list = new ListState();
        $this->list->selected = 0;
        $this->input = new Handle();
        $this->searchIcon = SVG::parse(SEARCH_ICON);
    }

    public function chosen(): int { return $this->list->selected ?? 0; }

    public function filter(): void
    {
        $q = mb_strtolower(trim($this->query->text));
        $found = [];
        foreach ($this->apps as $i => $app) {
            if (($r = rank(mb_strtolower($app->name), $q)) >= 0) { $found[] = [$r, $i, $app]; }
        }
        usort($found, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->results = array_column($found, 2);
        $this->choose(0);
    }

    public function choose(int $i): void
    {
        $this->list->selected = max(0, min($i, count($this->results) - 1));
        if ($this->results !== []) { $this->list->scrollIntoView($this->list->selected); }
    }

    /** An app's icon, drawn for the sharpest display the first time it shows, and kept. */
    public function icon(InstalledApp $app): ?Image
    {
        if (array_key_exists($app->path, $this->icons)) { return $this->icons[$app->path]; }
        $scale = 1.0;
        foreach (Screen::displays() as $d) { $scale = max($scale, $d->scaleFactor); }
        try {
            $img = Image::fromBytes(Shell::fileIcon($app->path, (int)(ICON_SIZE * $scale)));
        } catch (\RuntimeException) {
            $img = null;
        }
        return $this->icons[$app->path] = $img;
    }

    /** Launches the chosen app and hides the panel, which gives the keyboard back to the app the user was in before the launched one takes it. */
    public function open(): void
    {
        $app = $this->results[$this->chosen()] ?? null;
        if ($app === null) { return; }
        $this->panel->hide();
        try { Shell::launchApp($app); } catch (\RuntimeException $e) { error_log("launching $app->name: " . $e->getMessage()); }
    }

    /** Lists the installed apps after the panel shows, as at each show, where apps may have come or gone since. */
    public function load(): void
    {
        $this->loading = true;
        App::later(function () {
            $this->apps = Shell::installedApps();
            $this->loading = false;
            $this->filter();
            $this->panel?->invalidate();
        });
    }

    /** Shows the panel on the display with the pointer, a third of the way down, with an empty query. */
    public function show(): void
    {
        $d = Screen::displayNearestPoint(Screen::cursorScreenPoint());
        [$w, $h] = $this->panel->size();
        $this->panel->setPosition($d->workArea->x + intdiv($d->workArea->width - $w, 2), $d->workArea->y + intdiv($d->workArea->height - $h, 3));
        $this->query->text = '';
        $this->filter();
        $this->load();
        $this->input->focus();
        $this->panel->show();
    }

    public function toggle(): void
    {
        $this->panel->isVisible() ? $this->panel->hide() : $this->show();
    }

    public function ready(): void
    {
        $this->panel = new Window(new WindowOptions(title: 'Launcher', panel: true, hidden: true, frameless: true, transparent: true, vibrancy: 'popover',
            width: WIDTH, height: HEIGHT, disableResize: true, content: Content::view(fn(Context $c) => view($c, $this))));
        // Hidden rather than closed, on Escape or Cmd-W, and when the user goes elsewhere: it shows again at once, as it was.
        $this->panel->on('close', function (CloseEvent $e) { $e->cancel(); $this->panel->hide(); });
        $this->panel->on('blur', fn() => $this->panel->hide());
        foreach (SHORTCUTS as $sc) {
            try {
                GlobalShortcut::register($sc, $this->toggle(...));
                $this->shortcut = $sc;
                break;
            } catch (\RuntimeException $e) {
                error_log("shortcut $sc: " . $e->getMessage());
            }
        }
        $this->setUpTray();
        if (!App::wasOpenedAtLogin()) { $this->show(); } // started by the user: show what it does
    }

    private function setUpTray(): void
    {
        $this->tray = new Tray(new TrayOptions(icon: trayIcon(function (int $x, int $y) {
            $r = ($x - 13) ** 2 + ($y - 13) ** 2;
            if ($r >= 49 && $r <= 100) { return true; }
            for ($i = 0; $i < 8; $i++) { if ($x >= 20 + $i && $x < 23 + $i && $y >= 20 + $i && $y < 23 + $i) { return true; } } // the handle
            return false;
        }), iconIsTemplate: true, toolTip: 'Launcher'));
        $this->tray->setMenu(new Menu([
            new MenuItem('Open Launcher', fn() => $this->show(), accelerator: $this->shortcut),
            MenuItem::separator(),
            new MenuItem('Open at Login', function (MenuItem $it) {
                try { App::setOpenAtLogin($it->checked); } catch (\RuntimeException $e) { error_log($e->getMessage()); }
            }, type: MenuItem::CHECKBOX, checked: App::openAtLogin(), id: 'login'),
            MenuItem::separator(),
            new MenuItem(role: 'quit'),
        ]));
        App::onWillQuit(fn(QuitEvent $e) => $this->tray->destroy());
    }
}

function view(Context $c, Launcher $l): void
{
    $t = $c->theme;
    $c->root->color = $c->vibrancy() ? null : $t->background; // the material shows through
    // An Escape left to the panel closes it, which hides it: with a query, Escape clears it first.
    if ($l->query->text !== '' && $c->shortcut(Key::ESCAPE)) { $l->query->text = ''; $l->filter(); }
    if ($c->shortcut(Key::DOWN)) { $l->choose($l->chosen() + 1); }
    if ($c->shortcut(Key::UP)) { $l->choose($l->chosen() - 1); }
    if ($c->shortcut(Key::RETURN)) { $l->open(); }
    $c->onShortcut(Key::Q, Key::COMMAND, App::quit(...));
    $c->column(0, 0, 1);
      $c->row(0, 12)->padding(16, 18)->alignItems(Node::CENTER);
        $c->icon($l->searchIcon, 20, $t->textMuted);
        if ($c->changed($c->textInputBase($l->query)->bind($l->input)->grow(1)->fontSize(22)->label('Search apps'))) { $l->filter(); }
      $c->end();
      $c->divider();
      if ($l->results === []) {
          $c->column(16, 0, 1);
            $c->muted($l->loading ? 'Finding apps…' : 'No apps found');
          $c->end();
      } else {
          $l->list->key = fn(int $i) => $l->results[$i]->path;
          $list = $c->list($l->list, count($l->results), fn(int $i) => row($c, $l, $i))->grow(1)->padding(6);
          if ($l->list->submitted()) { $l->open(); }
      }
      $c->divider();
      $c->row(0, 16)->padding(8, 16);
        $c->text(keys($l->shortcut) . ' to show or hide', 11, $t->textMuted)->grow(1);
        $c->text('↑↓ Choose   ↵ Open   esc Close', 11, $t->textMuted);
      $c->end();
    $c->end();
}

/** An app, with its icon, its name, and where it is. */
function row(Context $c, Launcher $l, int $i): void
{
    $app = $l->results[$i];
    $t = $c->theme;
    $chosen = $i === $l->chosen();
    $r = $c->row(0, 12)->padding(6, 10)->alignItems(Node::CENTER)->radius(8);
    if ($chosen) { $r->background($t->accent, 0.85); }
      if (($icon = $l->icon($app)) !== null) { $c->image($icon, ICON_SIZE, ICON_SIZE); } else { $c->avatar($app->name)->size(ICON_SIZE, ICON_SIZE); } // its initial
      $c->column(0, 1, 1);
        $c->text($app->name, color: $chosen ? $t->accentText : $t->text)->singleLine();
        $c->text(where($app), 11, $chosen ? $t->accentText : $t->textMuted)->singleLine();
      $c->end();
    $c->end();
}

$s = new Launcher();
App::setName('Launcher');
if (!App::requestSingleInstanceLock()) { exit; } // the running launcher shows itself (onSecondInstance)
App::setActivationPolicy(ActivationPolicy::Accessory); // no Dock icon; a bundle says so from its start with LSUIElement (myphp.json)
App::onWindowAllClosed(function () {}); // keep running without windows
App::onSecondInstance(fn() => $s->show());
App::whenReady($s->ready(...));
App::run();
