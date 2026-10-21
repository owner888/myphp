<?php
// Scrolling nodes into view (mygo's scroll_test.go): ScrollIntoView in the
// frame that adds or chooses a node, nested containers, and Tab bringing the
// focus into view.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, ScrollState};

// A row added below shows in the frame that adds it, at the bottom; one above comes down to the top; one in view stays; one taller than the view shows from its start.
$rowsShown = 2; $show = -1; $rows = [];
$scroll = new ScrollState();
$h = new Headless(function (Context $c) use (&$rowsShown, &$show, &$rows, $scroll) {
    $c->column(0, 0, 1)->scroll($scroll);
      for ($i = 0; $i < $rowsShown; $i++) {
          $rows[$i] = $c->box(10, $i === 7 ? 400 : 100, 0x808080);
          if ($i === $show) {
              $rows[$i]->scrollIntoView();
              $show = -1;
          }
      }
    $c->end();
}, 200, 250);
$h->frame();
$rowsShown = 10; $show = 9;
$h->frame(1);
same(150.0, $rows[9]->y, 'a row added below shows at the bottom in the frame that adds it');
$show = 4; $h->frame(1);
same(0.0, $rows[4]->y, 'a row above comes down to the top');
$show = 5; $h->frame(1);
same(0.0, $rows[4]->y, 'showing a row in view moves nothing');
$show = 7; $h->frame(1);
same(0.0, $rows[7]->y, 'what is taller than the view shows from its start');

// Nested: the inner container shows the row at its bottom, the window the inner container's bottom at its own.
$show = false; $row = null;
$outer = new ScrollState(); $inner = new ScrollState();
$h = new Headless(function (Context $c) use (&$show, &$row, $outer, $inner) {
    $c->column(0, 0, 1)->scroll($outer);
      $c->box(10, 300, 0x808080);
      $c->column()->scroll($inner)->size(0, 150);
        for ($i = 0; $i < 10; $i++) {
            $r = $c->box(10, 50, 0x808080);
            if ($i === 8) { $row = $r; if ($show) { $r->scrollIntoView(); } }
        }
      $c->end();
    $c->end();
}, 200, 200);
$h->frame();
$show = true; $h->frame(1);
same(150.0, $row->y, 'row 8 shows at the bottom of both');

// Tab to a button in a scrolling container below the window brings it into view.
$button = null;
$outer = new ScrollState(); $inner = new ScrollState();
$h = new Headless(function (Context $c) use (&$button, $outer, $inner) {
    $c->column(0, 0, 1)->scroll($outer);
      $c->box(10, 300, 0x808080);
      $c->column()->scroll($inner)->size(0, 150);
        $c->box(10, 400, 0x808080);
        $button = $c->button('Deep');
      $c->end();
      $c->box(10, 1000, 0x808080);
    $c->end();
}, 200, 200);
$h->frame();
$h->tab();
same($button->id, $h->rt->focus, 'Tab focuses the button');
check($button->y >= 0 && $button->y + $button->h <= 200, "and it shows in the window ({$button->y})");
