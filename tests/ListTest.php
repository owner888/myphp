<?php
// Helpers of the list tests (list_behaviour_test.php, list_sections_test.php), as mygo's list_test.go has them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, ListState, Node};

/** The height of row $i of lists of rows of varied heights (mygo's varied). */
function lbVaried(int $i): float { return (float)(20 + $i * 7 % 45); }

/** The box of row $i, labeled "r$i", or null when not built. @return ?array{0: float, 1: float, 2: float, 3: float} */
function lbRow(Headless $h, int $i): ?array
{
    $n = $h->byLabel("r$i");
    return $n === null ? null : $h->rect($n);
}

/** The first and last rows in a view from $top to $bottom (mygo's Visible). @return array{0: int, 1: int} */
function lbVisible(Headless $h, ListState $s, float $top = 0, float $bottom = 400): array
{
    $first = -1; $last = -2;
    for ($i = $s->first; $i < $s->count && ($r = lbRow($h, $i)) !== null; $i++) {
        if ($r[1] + $r[3] > $top && $r[1] < $bottom) { if ($first < 0) { $first = $i; } $last = $i; }
    }
    return [$first, $last];
}

/** Whether the rows in view follow each other $gap apart and cover the view from $top to $bottom (mygo's checkRows). */
function lbRowsOk(Headless $h, ListState $s, float $gap = 0, float $top = 0, float $bottom = 400): bool
{
    [$first, $last] = lbVisible($h, $s, $top, $bottom);
    if ($last < $first) { return false; }
    $prev = lbRow($h, $first);
    if ($prev[1] > $top + $gap + 0.01) { return false; } // the view's top may fall in a gap
    for ($i = $first + 1; $i <= $last; $i++) {
        $r = lbRow($h, $i);
        if ($r === null || abs($r[1] - ($prev[1] + $prev[3] + $gap)) > 0.01) { return false; }
        $prev = $r;
    }
    return $last === $s->count - 1 || $prev[1] + $prev[3] + $gap >= $bottom - 0.01;
}

/** The wheel at (100, 100) moving the content $dy down (mygo's Scroll's dy). */
function lbWheel(Headless $h, float $dy): void
{
    $h->input->mouse(100, 100);
    $h->input->push(['scroll', 100, 100, 0, -$dy]);
    $h->frame();
}

/** A headless window of a list of $n rows "r$i" as $height($i) high, the list built by $build (padding, gap…). */
function lbList(ListState $s, int &$n, \Closure $height, int $w = 300, int $hh = 400, ?\Closure $build = null): Headless
{
    return new Headless(function (Context $c) use ($s, &$n, $height, $build): void {
        $c->column(0, 0, 1)->alignItems(Node::STRETCH);
          $l = $c->list($s, $n, function (int $i) use ($c, $height): void { $c->box(0, $height($i), 0x333333)->label("r$i"); });
          $l->grow = 1;
          if ($build !== null) { $build($l); }
        $c->end();
    }, $w, $hh);
}
