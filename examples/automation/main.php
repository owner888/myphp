<?php
// An app driven from outside (the automation plugin, docs/automation.md),
// which scripts/smoke-automation.php drives on a Mac: a window of native
// UI (a counter, a field, a row whose Delete button shows on hover and
// ignores input at rest, round rings, borders and shadows drawn in
// bands), a window made hidden that shows once its first frame is drawn,
// and a page; the menu Actions resets the count and quits.
// Run: ./run.sh automation (MYPHP_AUTOMATION=/tmp/automation.sock to drive it)
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\Plugins\Automation\Automation;
use MyPHP\UI\{Content, Context, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

/** What the windows show. */
final class Driven
{
    public int $count = 0, $rowClicks = 0, $deletes = 0;
    public TextState $name;

    public function __construct() { $this->name = new TextState(''); }
}

$s = new Driven();
App::setName('Automation');
if (getenv('MYPHP_AUTOMATION') !== false) {
    App::use(Automation::plugin());
}
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([
        new MenuItem(role: 'appMenu'),
        new MenuItem(role: 'editMenu'),
        new MenuItem(label: 'Actions', submenu: [
            new MenuItem(label: 'Reset', click: function () use ($s) { $s->count = 0; foreach (Window::all() as $w) { $w->invalidate(); } }),
            new MenuItem(label: 'Quit', click: fn() => App::quit()),
        ]),
    ]));
    new Window(new WindowOptions(title: 'Automation', width: 420, height: 400, content: Content::file(__DIR__ . '/view.php', $s)));
    // Made hidden, it shows once its first frame is drawn (mygo 0cb6d05).
    $hidden = new Window(new WindowOptions(title: 'Shown when ready', width: 260, height: 120, hidden: true,
        content: Content::view(fn(Context $c) => $c->text('Drawn while hidden, then shown'))));
    $hidden->on('readyToShow', fn() => $hidden->show());
    new Window(new WindowOptions(title: 'Automation page', width: 320, height: 200, html: '<!doctype html><title>Automation page</title><p>A page'));
});
App::run();
