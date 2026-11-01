<?php
// Context::keysReleased and buttonPressed: the keys let go since the last
// frame, whoever has the focus; where a button went down on a node (the
// left on it itself, the right and the others anywhere on it).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key};

$released = []; $pressed = []; $box = null;
$h = new Headless(function (Context $c) use (&$released, &$pressed, &$box): void {
    $c->column(0, 0, 1)->size(400, 300);
      $box = $c->column()->absolute()->left(10)->top(10)->size(100, 100);
      $c->end();
      if ($c->clicked($box)) { /* the box takes clicks */ }
    $c->end();
    foreach ($c->keysReleased() as $k) { $released[] = $k[1]; }
    foreach ([0, 1, 2] as $b) { if (($p = $c->buttonPressed($box, $b)) !== null) { $pressed[] = [$b, $p]; } }
}, 400, 300);
$h->frame();
$h->keyUp(Key::A);
same([Key::A], $released, 'a key let go: told once');
$h->click(50, 60);
$h->rightClick(20, 30);
$h->input->mouse(30, 30); $h->input->push(['down', 30, 30, 2, 1, 0]); $h->input->push(['up', 30, 30, 2, 1, 0]); $h->frame();
$h->rightClick(300, 200);
same("[[0,[50,60]],[1,[20,30]],[2,[30,30]]]", json_encode($pressed), 'the left, the right and another on the box, where; none outside it');
