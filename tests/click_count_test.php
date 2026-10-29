<?php
// Counting clicks (mygo's click_count_test.go): the system's count of quick
// presses, started over on a press of another node than the last's, so that a
// click then a click on its neighbor, or on what the first click put under the
// pointer, is no double click; a release carries its press's count.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, InputEvent};

// A handler gets the count with a press and its release, and each button counts its own (mygo's TestHandlerClickCount).
$clicks = [];
$h = new Headless(function (Context $c) use (&$clicks) {
    $c->box(100, 100, 0)->handleInput(function (InputEvent $ev) use (&$clicks): bool {
        if ($ev->kind === InputEvent::POINTER_DOWN || $ev->kind === InputEvent::POINTER_UP) { $clicks[] = $ev->clicks; }
        return true;
    });
}, 100, 100);
$h->frame();
$h->click(50, 50); $h->click(50, 50, 0, 2); $h->rightClick(50, 50); $h->click(50, 50);
same([1, 1, 2, 2, 1, 1, 1, 1], $clicks, 'presses and releases, the right button and a click after it');

// A release carries its press's count, though another button went down meanwhile (mygo's TestReleaseCarriesPressCount).
$ups = [];
$h = new Headless(function (Context $c) use (&$ups) {
    $c->box(100, 100, 0)->handleInput(function (InputEvent $ev) use (&$ups): bool {
        if ($ev->kind === InputEvent::POINTER_UP && $ev->button === 0) { $ups[] = $ev->clicks; }
        return $ev->button === 0;
    });
}, 100, 100);
$h->frame();
$h->click(50, 50);
$h->input->push(['down', 50, 50, 0, 2, 0]);
$h->input->push(['down', 50, 50, 1, 1, 0]);
$h->input->push(['up', 50, 50, 0, 2, 0]);
$h->frame();
same([1, 2], $ups, 'the releases');

// A click that puts another node under the pointer does not make the next click on it a double click, inside a handler that leaves presses (mygo's TestClickOnNewElementIsNotDouble).
$opened = false; $doubles = 0;
$h = new Headless(function (Context $c) use (&$opened, &$doubles) {
    $c->column()->size(200, 100)->handleInput(fn(InputEvent $ev): bool => false);
      if (!$opened) {
          $opened = $c->clicked($c->key('open')->button('Open')->size(100, 40));
      } elseif ($c->doubleClicked($c->key('row')->button('Row')->size(100, 40))) {
          $doubles++;
      }
    $c->end();
}, 200, 100);
$h->frame();
$h->click(50, 20); $h->frame(2);
check($opened, 'the click opened');
$h->click(50, 20, 0, 2); $h->frame();
same(0, $doubles, 'the first click on the new node is no double click');

// The system's count starts over on another node than the last press's: a click then a click on its neighbor is no double click, the release carrying its press's count (mygo's TestBackendCountPerElement).
$a = []; $b = [];
$record = function (array &$to) {
    return function (InputEvent $ev) use (&$to): bool {
        if ($ev->kind === InputEvent::POINTER_DOWN) { $to[] = $ev->clicks; }
        elseif ($ev->kind === InputEvent::POINTER_UP) { check($ev->clicks === end($to), "a release carried {$ev->clicks}, its press " . end($to)); }
        return true;
    };
};
$h = new Headless(function (Context $c) use ($record, &$a, &$b) {
    $c->row();
      $c->box(50, 50, 0)->handleInput($record($a));
      $c->box(50, 50, 0)->handleInput($record($b));
    $c->end();
}, 100, 50);
$h->frame();
foreach ([[10, 1], [60, 2], [60, 3], [10, 4], [10, 1], [10, 2]] as [$x, $n]) {
    $h->input->push(['down', $x, 20, 0, $n, 0]);
    $h->input->push(['up', $x, 20, 0, $n, 0]);
    $h->frame();
}
same([[1, 1, 1, 2], [1, 2]], [$a, $b], 'counted per node');
