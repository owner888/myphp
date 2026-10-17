<?php
// Helpers of the tests of the diff viewer.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Context;
use MyPHP\UI\Editor\DiffState;

/** Whether hunks turn old's lines into new's: '' when they do, else what is wrong. @param list<\MyPHP\UI\Editor\Hunk> $hunks */
function checkHunks(string $old, string $nw, array $hunks): string
{
    $a = explode("\n", $old); $b = explode("\n", $nw);
    $out = []; $i = 0; $j = 0;
    foreach ($hunks as $h) {
        if ($h->a0 < $i || $h->b0 < $j || $h->a0 - $i !== $h->b0 - $j) { return "hunk out of order after $i, $j"; }
        for (; $i < $h->a0; $i++, $j++) {
            if ($a[$i] !== $b[$j]) { return "unchanged old line $i is another in new"; }
            $out[] = $a[$i];
        }
        array_push($out, ...array_slice($b, $h->b0, $h->b1 - $h->b0));
        $i = $h->a1; $j = $h->b1;
    }
    if (count($a) - $i !== count($b) - $j) { return 'tails differ'; }
    array_push($out, ...array_slice($a, $i));
    return implode("\n", $out) === $nw ? '' : 'the hunks make another text';
}

/** A diff viewer in a headless window. */
function diffViewer(DiffState $d, float $w, float $h): Headless
{
    $t = new Headless(function (Context $c) use ($d) { $d->view($c)->grow = 1; }, $w, $h);
    $t->paints = true;
    $t->frame();
    return $t;
}
