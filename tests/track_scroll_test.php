<?php
// A scroll container on the app's ScrollState (mygo's scroll_test.go
// TestTrackScroll, TestTrackScrollSettles, TestTrackScrollFollowsTheEnd,
// TestTrackScrollPerPage; myphp's TrackScroll is Node::scroll($state)):
// the state following the wheel before the build, the app setting it,
// kept within the content, following the end, one per page.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, ScrollState};

// The wheel, then the app: the state follows before the build sees it; past the end the end, before the start the start, the axis it does not scroll put.
$s = new ScrollState(); $top = null; $seen = -1.0;
$h = new Headless(function (Context $c) use ($s, &$top, &$seen): void {
    $seen = $s->y;
    $c->column(0, 0, 1)->scroll($s);
      $top = $c->column()->size(0, 1000)->shrink(0); $c->end();
    $c->end();
}, 200, 200);
$h->frame();
same([0.0, 800.0, 0.0], [$s->y, $s->maxY, $s->maxX], 'at the start');
$h->input->push(['scroll', 100, 100, 0, -120]); $h->frame(1);
same([120.0, 120.0, -120.0], [$s->y, $seen, $top->y], 'scrolled 120 down: the state, what the build saw, the content');
$s->y = 300; $h->frame();
same(-300.0, $top->y, 'set to 300');
$s->y = PHP_FLOAT_MAX; $h->frame();
same([800.0, -800.0], [$s->y, $top->y], 'set past the end');
$s->x = 50; $s->y = -10; $h->frame();
same([0.0, 0.0, 0.0, 0.0], [$s->x, $s->y, $top->x, $top->y], 'set to (50, -10)');

// Built from the state before layout keeps it within the content: the next frame builds from the kept one.
$s = new ScrollState(); $long = true;
$h = new Headless(function (Context $c) use ($s, &$long): void {
    $c->column(0, 0, 1);
      if ($s->y > 0) { $c->text('Back to top'); }
      $c->column(0, 0, 1)->scroll($s);
        if ($long) { $c->column()->size(0, 1000)->shrink(0); $c->end(); }
      $c->end();
    $c->end();
}, 200, 200);
$h->frame();
$s->y = PHP_FLOAT_MAX; $h->frame();
check($h->byText('Back to top') !== null, 'scrolled to the end');
$long = false; $h->frame();
check($h->byText('Back to top') === null, 'the content shrank to nothing: ' . json_encode($s->y));

// Following the end as lines come, in a few builds; scrolled up, it stays where the user left it.
$s = new ScrollState(); $lines = 10; $builds = 0;
$h = new Headless(function (Context $c) use ($s, &$lines, &$builds): void {
    $builds++;
    if ($s->y >= $s->maxY) { $s->y = PHP_FLOAT_MAX; }
    $c->column(0, 0, 1)->scroll($s);
      for ($i = 0; $i < $lines; $i++) { $c->column()->size(0, 50)->shrink(0); $c->end(); }
    $c->end();
}, 200, 200);
$h->frame();
same([300.0, 300.0], [$s->y, $s->maxY], 'ten lines of 50 in 200');
$lines = 20; $builds = 0; $h->frame();
same([800.0, 800.0], [$s->y, $s->maxY], 'ten lines more');
check($builds <= 4, "following the end took $builds builds (two frames)");
$h->input->push(['scroll', 100, 100, 0, 100]); $h->frame();
$lines = 30; $h->frame();
same([700.0, 1300.0], [$s->y, $s->maxY], 'scrolled up, then ten lines more');

// A state for each page: each keeps its own place.
$pages = ['a' => new ScrollState(), 'b' => new ScrollState()]; $page = 'a'; $top = null;
$h = new Headless(function (Context $c) use ($pages, &$page, &$top): void {
    $c->column(0, 0, 1)->scroll($pages[$page]);
      $top = $c->column()->size(0, 1000)->shrink(0); $c->end();
    $c->end();
}, 200, 200);
$h->frame();
$h->input->push(['scroll', 100, 100, 0, -400]); $h->frame();
$page = 'b'; $h->frame();
same([0.0, 400.0], [$top->y, $pages['a']->y], 'page b from its top; page a kept its place');
$page = 'a'; $h->frame();
same(-400.0, $top->y, 'back on page a');
