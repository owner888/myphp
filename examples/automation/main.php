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
use MyPHP\UI\{Content, Context, Node, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** What the windows show. */
final class Driven
{
    public int $count = 0, $rowClicks = 0, $deletes = 0;
    public TextState $name;

    public function __construct() { $this->name = new TextState(''); }
}

function view(Context $c, Driven $s): void
{
    $t = $c->theme;
    $c->column(20, 12, 1)->fill();
      $c->text("Count: {$s->count}");
      if ($c->clicked($c->key('add')->button('Add'))) { $s->count++; }
      $c->key('name')->input($s->name, 'Name', 240)->label('Name');
      $c->text("Hello, {$s->name->text}");
      // Its Delete button shows while the row hovers; at rest it ignores input, a press there the row's, but assistive technology presses it.
      $row = $c->key('row')->row(8, 8, 0, $t->surface)->size(320, 44)->round(8)->label('Row')->align(Node::CENTER);
      $hovered = $c->hovered($row);
        $c->text('A row')->grow = 1;
        $del = $c->key('delete')->button('Delete')->ignoreInput(!$hovered);
        if (!$hovered) { $del->opacity(0); }
        if ($c->clicked($del)) { $s->deletes++; }
      $c->end();
      if ($c->clicked($row)) { $s->rowClicks++; }
      $c->text("Row clicks: {$s->rowClicks}, deletes: {$s->deletes}");
      // Shapes drawn in bands of rows around their round corners (v1.115): their edges should look as ever.
      $c->row(0, 16)->align(Node::CENTER);
        $c->column(0, 0)->size(64, 64)->round(32)->border($t->accent, 2);
        $c->end();
        $c->column(0, 0)->size(120, 64)->round(12)->border($t->border, 1);
        $c->end();
        $c->column(0, 0, 0, $t->background)->size(120, 64)->round(16)->shadow(0, 6, 18, 0, 0x000000, 0.35);
        $c->end();
      $c->end();
    $c->end();
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
    new Window(new WindowOptions(title: 'Automation', width: 420, height: 400, content: Content::view(fn(Context $c) => view($c, $s))));
    // Made hidden, it shows once its first frame is drawn (mygo 0cb6d05).
    $hidden = new Window(new WindowOptions(title: 'Shown when ready', width: 260, height: 120, hidden: true,
        content: Content::view(fn(Context $c) => $c->text('Drawn while hidden, then shown'))));
    $hidden->on('readyToShow', fn() => $hidden->show());
    new Window(new WindowOptions(title: 'Automation page', width: 320, height: 200, html: '<!doctype html><title>Automation page</title><p>A page'));
});
App::run();
