<?php
// A window with a hidden title bar: the traffic lights stay, over a title
// bar the view draws itself, which drags the window (double-click zooms),
// keeping clear of the lights; a toggle moves to the inset style. In full
// screen the lights hide and the bar's room goes to zero.
// Run: ./run.sh titlebar [hidden|hiddenInset] [x,y]   — x,y moves the traffic lights (their top-left corner from the window's)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Node, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$style = $argv[1] ?? 'hidden';
$lights = isset($argv[2]) ? array_map('floatval', explode(',', $argv[2])) : null;
$s = ['count' => 0, 'query' => new TextState];

function view(Context $c, array &$s, string $style): void
{
    $t = $c->theme;
    $bar = $c->titleBar();
    $c->column(0, 0, 1);
      // The title bar: as high as the controls' room (or 32), padded past them, a handle moving the window; the field and the button in it keep their presses.
      $c->row(0, $t->space(2), 0, $t->surface)->size(0, max($bar['height'], 32))->alignItems(Node::CENTER)->dragWindow();
        $c->column(0, 0)->size($bar['left'], 0); $c->end();
        $c->text("Title bar: $style", $t->fontSize, $t->text, Font::BOLD);
        $c->muted(sprintf('controls take %d × %d', $bar['left'], $bar['height']));
        $c->row(0, 0, 1); $c->end();
        $c->searchField($s['query'], 180);
        if ($c->clicked($c->button('Count'))) { $s['count']++; }
        $c->column(0, 0)->size($bar['right'] + $t->space(3), 0); $c->end();
      $c->end();
      $c->divider();
      $c->column($t->space(5), $t->space(3), 1);
        $c->text('Drag the bar above to move the window; double-click it to zoom.');
        $c->muted("Counted {$s['count']}; searching “{$s['query']->text}”.");
      $c->end();
    $c->end();
}

App::whenReady(function () use (&$s, $style, $lights) {
    new Window(new WindowOptions(
        title: 'MyPHP — title bar',
        width: 720,
        height: 420,
        titleBarStyle: $style,
        trafficLightPosition: $lights,
        content: Content::view(function (Context $c) use (&$s, $style) { view($c, $s, $style); }),
    ));
});
App::run();
