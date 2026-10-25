<?php
// A spinner paints in the theme's muted color, or in its own text color,
// as one on a chosen row needs (mygo's indicators_test.go TestSpinnerColor).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, DisplayList, Node};

$nodes = [];
$h = new Headless(function (Context $c) use (&$nodes): void {
    $c->row(10, 20);
      $nodes['muted'] = $c->spinner()->label('Muted');
      $nodes['red'] = $c->spinner()->textColor(0xff0000)->label('Red');
    $c->end();
}, 200, 100);
$h->frame();
$list = $h->paint();
/** The colors painted inside $n's box, as 0xRRGGBB. @return list<int> */
$colors = function (Node $n) use ($h, $list): array {
    [$x, $y, $w, $hh] = $h->rect($n);
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $b = $list->buf; $k = $i * DisplayList::FLOATS_PER_RECT;
        if ($b[$k] >= $x && $b[$k] < $x + $w && $b[$k + 1] >= $y && $b[$k + 1] < $y + $hh && $b[$k + 7] > 0) {
            $out[] = ((int)round($b[$k + 4] * 255) << 16) | ((int)round($b[$k + 5] * 255) << 8) | (int)round($b[$k + 6] * 255);
        }
    }
    return array_values(array_unique($out));
};
same([$h->theme->textMuted], $colors($nodes['muted']), 'the muted text color');
same([0xff0000], $colors($nodes['red']), 'its own text color');
