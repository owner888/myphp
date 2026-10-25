<?php
// A list following its end as it glides, in the cases mygo's review found
// (review_follow_test.go): the list getting shorter, the last row shrinking
// back or going while it glides, lists that cannot glide, a stream of rows
// growing and coming, the window shorter, the user scrolling toward the
// end; and a selection's parts as its text grows (TestReviewSelectionGrowAndCopyData).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\Data;
use MyPHP\UI\{Animation, Context, ListState, Node};

require_once __DIR__ . '/ListTest.php';
require_once __DIR__ . '/SelectionTest.php';

/** A list of $n rows of 40, the last $last high, over a box $below high, following its end with a glide; $now its clock. */
function rfTester(ListState $s, int &$n, float &$last, float &$below, ?int &$now): Headless
{
    return poseTester(function (Context $c) use ($s, &$n, &$last, &$below): void {
        $c->column(0, 0, 1)->align(Node::STRETCH);
          $c->list($s, $n, function (int $i) use ($c, &$n, &$last): void { $c->box(0, $i === $n - 1 ? $last : 40.0, 0x333333)->label("r$i"); })->grow = 1;
          $c->box(0, $below, 0x000000);
        $c->end();
    }, $now, 300, 400);
}

function rfFollowing(): ListState { $s = new ListState(); $s->followEnd = true; $s->followAnimation = Animation::smooth(); return $s; }

$end = fn(?array $r): float => $r === null ? NAN : $r[1] + $r[3];

// The list gets shorter (a composer below grows): no row came, yet the end stays at its bottom.
$s = rfFollowing(); $n = 30; $last = 40.0; $below = 0.0; $now = 0;
$h = rfTester($s, $n, $last, $below, $now); $h->frame();
same(400.0, $end(lbRow($h, $n - 1)), 'at first, the last row ends at the bottom');
$below = 100.0; $h->frame();
same(300.0, $end(lbRow($h, $n - 1)), 'the list 100 shorter: the last row ends at its bottom, not under the composer');

// The last row shrinks back while the list glides: the rows above it stay where they show, then go to their place.
$s = rfFollowing(); $n = 30; $last = 40.0; $below = 0.0; $now = 0;
$h = rfTester($s, $n, $last, $below, $now); $h->frame();
$last = 300.0; $h->frame(1);
$now += 50_000_000; $h->frame(1);
$rowBefore = lbRow($h, $n - 2);
$last = 40.0; $now += 1_000_000; $h->frame(1);
$rowAfter = lbRow($h, $n - 2);
check($rowAfter[1] <= $rowBefore[1] + 1, 'the last row shrank back: the row above it did not jump down: ' . json_encode([$rowBefore[1], $rowAfter[1], $s->lag]));
$now += 2_000_000_000; $h->frame();
same(400.0, $end(lbRow($h, $n - 1)), 'after the glide, the last row ends at the bottom');

// The last row goes while the list glides (a typing indicator): the rows show in place.
$s = rfFollowing(); $n = 30; $last = 40.0; $below = 0.0; $now = 0;
$h = rfTester($s, $n, $last, $below, $now); $h->frame();
$n++; $last = 200.0; $h->frame(1);
$now += 50_000_000; $h->frame(1);
$n--; $last = 40.0; $now += 1_000_000; $h->frame(1);
same(400.0, $end(lbRow($h, $n - 1)), 'the last row went: the one above it ends at the bottom, lag ' . $s->lag);

// A list that cannot glide, in a row, bounded, in a grid, in a wrapping row: no lag.
foreach (['row', 'max height', 'grid', 'wrap'] as $kind) {
    $s = rfFollowing(); $n = 30; $now = 0; $lags = [];
    $h = poseTester(function (Context $c) use ($s, &$n, $kind): void {
        $list = fn(): Node => $c->list($s, $n, function (int $i) use ($c): void { $c->box(0, 40, 0x333333)->label("r$i"); });
        switch ($kind) {
            case 'row': $c->row(0, 0, 1)->align(Node::STRETCH); $list()->grow = 1; $c->end(); break;
            case 'max height': $c->column(); $list()->maxHeight(300); $c->end(); break;
            case 'grid': $c->grid(1)->grow = 1; $list()->height(300); $c->end(); break;
            case 'wrap': $c->row()->wrap(300); $list()->size(200, 300); $c->box(150, 50, 0); $c->end(); break;
        }
    }, $now, 300, 400);
    for ($f = 0; $f < 5; $f++) { $now += 16_000_000; $h->frame(1); $lags[] = $s->lag; }
    same([0.0, 0.0, 0.0, 0.0, 0.0], $lags, "$kind: no glide");
}

// A stream: the last row grows and rows come; the list stays at its end, following, its rows one after another; at rest at the bottom, still.
foreach (['many' => [30, Node::START, false], 'few at the end' => [2, Node::END, false], 'few' => [2, Node::START, false], 'with headers' => [30, Node::START, true]] as $case => [$n, $justify, $headers]) {
    $s = rfFollowing(); $last = 20.0; $now = 0; $bad = ''; $few = $n <= 8; // a few rows at the start may not reach the bottom
    $isHeader = fn(int $i): bool => $i % 7 === 0;
    if ($headers) { $s->header = $isHeader; }
    $h = poseTester(function (Context $c) use ($s, &$n, &$last, $headers, $isHeader, $justify): void {
        $c->column(0, 0, 1)->align(Node::STRETCH);
          $l = $c->list($s, $n, function (int $i) use ($c, &$n, &$last, $headers, $isHeader): void {
              $c->box(0, $i === $n - 1 ? $last : ($headers && $isHeader($i) ? 24.0 : 40.0), 0x333333)->label("r$i");
          })->justify($justify);
          $l->grow = 1;
        $c->end();
    }, $now, 300, 400);
    for ($f = 0; $f < 200 && $bad === ''; $f++) {
        $now += 16_000_000;
        if ($f % 25 === 24) { $n++; $last = 20.0; } elseif ($f < 150) { $last += 7; }
        $h->frame(1);
        if (!$s->atEnd) { $bad = "frame $f: not at the end"; }
        elseif (!$headers) {
            [$first, $lastV] = lbVisible($h, $s);
            for ($i = $first + 1; $i <= $lastV; $i++) {
                $p = lbRow($h, $i - 1); $r = lbRow($h, $i);
                if ($r === null || abs($r[1] - ($p[1] + $p[3])) > 0.01) { $bad = "frame $f: row $i at " . json_encode($r) . ' after ' . json_encode($p); break; }
            }
        }
    }
    same('', $bad, "$case: at the end, its rows one after another");
    $now += 3_000_000_000; $h->frame();
    check(($end(lbRow($h, $n - 1)) == 400.0 || ($justify === Node::START && $few)) && !$h->rt->needsFrame, "$case: at rest at the bottom, still");
}

// The window shorter: the last row ends at its new bottom.
$s = rfFollowing(); $n = 30; $last = 40.0; $below = 0.0; $now = 0;
$h = rfTester($s, $n, $last, $below, $now); $h->frame();
$h->h = 340; $h->frame();
same(340.0, $end(lbRow($h, $n - 1)), 'the window 60 shorter: the last row ends at 340, lag ' . $s->lag);

// The user scrolling toward the end during a glide keeps it following.
$s = rfFollowing(); $n = 30; $last = 40.0; $below = 0.0; $now = 0;
$h = rfTester($s, $n, $last, $below, $now); $h->frame();
$n++; $last = 200.0; $h->frame(1);
$now += 16_000_000;
$h->input->push(['scroll', 100, 100, 0, -20]); $h->frame(1); // toward the end, less than the lag
$now += 2_000_000_000; $h->frame();
$n++; $h->frame();
$now += 2_000_000_000; $h->frame();
same(400.0, $end(lbRow($h, $n - 1)), 'scrolled toward the end on the way, then a row came: the last ends at the bottom');

// A selection's parts as its text grows: the copy data gets them; Copy from the keys, the menus and the context menu.
$a = 'Alpha beta'; $b = 'Gamma';
$h = new Headless(function (Context $c) use (&$a, &$b): void {
    $c->column(12, 8)->selectable()->copyData(function (array $parts): Data {
        return Data::text(implode('', array_map(fn($p) => mb_substr($p->text, $p->start, $p->end - $p->start) . '|', $parts)));
    });
      $c->text($a);
      $c->text($b);
    $c->end();
}, 300, 150);
$h->frame();
selectBetween($h, $a, 6, $b, 3);
$b = 'Gamma delta'; $h->frame();
cmdKey($h, 'c');
same('beta|Gam|', clip($h), 'the second grew');
$a = 'Alpha beta and more'; $h->frame();
$h->input->push(['edit', 'copy']); $h->frame();
same('beta and more|Gam|', clip($h), 'the first grew');
[$x, $y] = selectableAt($h, $b, 1);
$h->rightClick($x, $y);
setClip($h, '');
check($h->chooseMenuItem('Copy') && clip($h) === 'beta and more|Gam|', 'the context menu copies it');

// Inline text nested in a selectable paragraph, the innermost changing: the paragraph's text follows it (TestReviewNestedInlineSpans).
$inner = 'c'; $got = [];
$h = new Headless(function (Context $c) use (&$inner): void {
    $c->richText([])->selectable()->children(function () use ($c, &$inner): void {
        $c->text('a ');
        $c->richText([new \MyPHP\UI\Span('b ')])->children(function () use ($c, &$inner): void { $c->text($inner)->bold(); });
        $c->text(' d');
    });
}, 300, 100);
for ($i = 0; $i < 3; $i++) {
    $inner .= 'x'; $h->frame();
    $got[] = selectableNode($h->root, "a b $inner d") !== null;
}
same([true, true, true], $got, 'the paragraph follows its innermost text');
