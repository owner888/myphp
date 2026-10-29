<?php
// A drag region's press moves the window (Native\WindowDrag, mygo's
// window_drag_test.go): the window server when the pointer has not moved
// yet, myphp itself after a quick press and drag or when the window server
// did not begin, and never both.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\WindowDrag as D;

// Not moved when asked: the window server's, its drags eaten, myphp leaving the window alone.
$d = new D;
$d->down(1, [100.0, 50.0]);
same(D::START_NATIVE, $d->start(1, [10.0, 500.0]), 'the window server asked');
same([true, false], $d->dragged([140.0, 70.0], [50.0, 480.0]), 'the window server moving it');
same([true, false], $d->dragged([108.0, 50.0], [10.0, 500.0]), 'back where it was, still the window server\'s');
$d->up();
same(D::IDLE, $d->phase, 'the release ends it');

// A quick press and drag moved before the page asked: myphp moves the window, at once to the pointer, then with it.
$d = new D;
$d->down(1, [100.0, 50.0]);
same([[false, false], [false, false]], [$d->dragged([104.0, 52.0], [10.0, 500.0]), $d->dragged([120.0, 60.0], [10.0, 500.0])], 'drags before it is asked are the content\'s');
same(D::START_MANUAL, $d->start(1, [10.0, 500.0]), 'myphp moves it');
same([30.0, 490.0], $d->target(), 'right 20, down 10 (down in AppKit\'s space)');
$d->placed($d->target());
same([true, true], $d->dragged([150.0, 40.0], [30.0, 490.0]), 'then with the pointer');
same([60.0, 510.0], $d->target(), 'where the pointer is');

// The window server says it begins before its frame moves: its drags stay its.
$d = new D;
$d->down(1, [100.0, 50.0]); $d->start(1, [10.0, 500.0]);
$d->willMove(2);
same(D::NATIVE, $d->phase, 'another window\'s move');
$d->willMove(1);
same([[true, false], [true, false]], [$d->dragged([108.0, 50.0], [10.0, 500.0]), $d->dragged([160.0, 70.0], [10.0, 500.0])], 'the window server\'s, though the frame has not moved');

// The window server did not begin: past the slop, myphp moves the window.
$d = new D;
$d->down(1, [100.0, 50.0]); $d->start(1, [10.0, 500.0]);
same([true, false], $d->dragged([101.0, 51.0], [10.0, 500.0]), 'within the slop');
same([true, true], $d->dragged([108.0, 50.0], [10.0, 500.0]), 'past it');
same([18.0, 500.0], $d->target(), 'with the pointer');

// The window server began after myphp took over: once the window is not where myphp put it, it is the window server's.
$d = new D;
$d->down(1, [100.0, 50.0]); $d->start(1, [10.0, 500.0]);
$d->dragged([108.0, 50.0], [10.0, 500.0]); $d->placed($d->target());
same([true, true], $d->dragged([120.4, 50.0], [18.0, 500.0]), 'where myphp put it');
$d->placed($d->target());
same([true, true], $d->dragged([130.0, 51.0], [30.0, 500.0]), 'its origin rounded by AppKit');
$d->placed($d->target());
same([[true, false], [true, false]], [$d->dragged([160.0, 54.0], [70.0, 496.0]), $d->dragged([170.0, 55.0], [70.0, 496.0])], 'the window server moving it');
same(D::SERVER, $d->phase, 'left to the window server');

// Asked once a press, for the window pressed, while the button is down.
$d = new D;
same(D::START_NONE, $d->start(1, [0.0, 0.0]), 'no press');
$d->down(1, [100.0, 50.0]);
same([D::START_NONE, D::START_NATIVE, D::START_NONE], [$d->start(2, [0.0, 0.0]), $d->start(1, [0.0, 0.0]), $d->start(1, [0.0, 0.0])], 'another window, then once');
$d->down(1, [100.0, 50.0]); $d->up();
same(D::START_NONE, $d->start(1, [0.0, 0.0]), 'after the release');
same([5.0, 700.0], D::belowMenuBar([5.0, 760.0], 200.0, 900.0), 'kept below the menu bar');
