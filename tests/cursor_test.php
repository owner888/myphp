<?php
// The cursor a node asks for goes to what is inside it, but stops at what
// is clicked, which has its own (mygo's style_test.go TestCursorStopsAtClickable).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Cursor, Node};

$n = [];
$h = new Headless(function (Context $c) use (&$n): void {
    $c->row(10, 10, 1)->cursor(Cursor::Text);
      $n['inherits'] = $c->text('inherits');
      $n['button'] = $c->buttonBase()->size(30, 30); $n['+'] = $c->text('+'); $c->end();
      $n['pointer'] = $c->buttonBase()->size(30, 30)->cursor(Cursor::Pointer); $c->end();
    $c->end();
}, 200, 60);
$h->frame();
foreach ([['inherits', Cursor::Text], ['button', Cursor::Default], ['+', Cursor::Default], ['pointer', Cursor::Pointer]] as [$name, $want]) {
    $h->hover(...$h->middle($n[$name]));
    same($want, $h->rt->cursor, "the pointer over $name");
}
