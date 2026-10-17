<?php
// The line diff (mygo's internal/diff tests): hunks apply, blocks slide as
// git's indent heuristic places them, the histogram anchors on rare lines
// and Myers's cost limit keeps unrelated texts quick.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Diff, Hunk};

/** Turns $a into $b with the hunks, checking that what they leave alone is the same on both sides; '' on success, else what is wrong. */
function diffApply(array $a, array $b, array $hunks): string
{
    $out = []; $i = 0; $j = 0;
    foreach ($hunks as $h) {
        if ($h->a0 < $i || $h->b0 < $j || $h->a0 - $i !== $h->b0 - $j || $h->a1 < $h->a0 || $h->b1 < $h->b0) { return "hunk out of order after $i, $j"; }
        for (; $i < $h->a0; $i++, $j++) {
            if ($a[$i] !== $b[$j]) { return "unchanged line $i differs"; }
            $out[] = $a[$i];
        }
        for ($k = $h->b0; $k < $h->b1; $k++) { $out[] = $b[$k]; }
        $i = $h->a1; $j = $h->b1;
    }
    if (count($a) - $i !== count($b) - $j) { return 'tails differ'; }
    for (; $i < count($a); $i++) { $out[] = $a[$i]; }
    return $out === $b ? '' : 'applying gives another text';
}
$lines = fn(string $s) => $s === '' ? [] : explode("\n", $s);
$hunks = fn(array $hs) => array_map(fn($h) => [$h->a0, $h->a1, $h->b0, $h->b1], $hs);

foreach ([
    ['', '', []],
    ['a', 'a', []],
    ['', "a\nb", [[0, 0, 0, 2]]],
    ["a\nb", '', [[0, 2, 0, 0]]],
    ["a\nb\nc", "a\nx\nc", [[1, 2, 1, 2]]],
    ["a\nb\nc", "a\nc", [[1, 2, 1, 1]]],
    ["a\nc", "a\nb\nc", [[1, 1, 1, 2]]],
    ["a\nb\nc\nd", "b\nc\nd\ne", [[0, 1, 0, 0], [4, 4, 3, 4]]],
] as [$a, $b, $want]) {
    $got = Diff::lines($lines($a), $lines($b));
    same($want, $hunks($got), "lines(" . json_encode($a) . ', ' . json_encode($b) . ')');
    same('', diffApply($lines($a), $lines($b), $got), 'and they apply');
}

// A function added between two others shows as the function and the blank line after it, not as the end of one function and the start of the next, as git's indent heuristic places it.
$a = $lines("func a() {\n\tx()\n}\n\nfunc c() {\n\tx()\n}");
$b = $lines("func a() {\n\tx()\n}\n\nfunc b() {\n\tx()\n}\n\nfunc c() {\n\tx()\n}");
$got = Diff::lines($a, $b);
same([[4, 4, 4, 8]], $hunks($got), 'an added function slides to read as a block');
same('', diffApply($a, $b, $got), 'and applies');

// Histogram anchors on rare lines: moving a block keeps the unique lines matched rather than the braces that repeat.
$a = $lines("one\n{\n}\ntwo\n{\n}\nthree"); $b = $lines("two\n{\n}\none\n{\n}\nthree");
$got = Diff::lines($a, $b);
same('', diffApply($a, $b, $got), 'a moved block applies');
$changed = 0; foreach ($got as $h) { $changed += $h->a1 - $h->a0 + $h->b1 - $h->b0; }
check($changed <= 6, "a moved block changes at most 6 lines, not $changed");

// Random edits: the hunks of the histogram and of Myers both apply.
mt_srand(3);
$words = ['a', 'b', 'c', '{', '}', '', 'x := 1', 'return'];
$gen = function (int $n) use ($words): array { $out = []; for ($i = 0; $i < $n; $i++) { $out[] = $words[mt_rand(0, count($words) - 1)]; } return $out; };
$ok = true;
for ($t = 0; $t < 500; $t++) {
    $a = $gen(mt_rand(0, 39)); $b = $a;
    for ($e = mt_rand(0, 5); $e > 0; $e--) {
        if ($b === [] || mt_rand(0, 1) === 0) {
            $at = mt_rand(0, count($b));
            array_splice($b, $at, 0, $gen(mt_rand(1, 4)));
        } else {
            $at = mt_rand(0, count($b) - 1);
            array_splice($b, $at, mt_rand(1, 3));
        }
    }
    if (mt_rand(0, 4) === 0) { $b = $gen(mt_rand(0, 39)); }
    if (diffApply($a, $b, Diff::lines($a, $b)) !== '') { $ok = false; break; }
}
check($ok, 'the hunks of 500 random edits apply');

// Myers's cost limit keeps unrelated texts quick and the result valid.
mt_srand(5);
$gen = function (int $n): array { $out = []; for ($i = 0; $i < $n; $i++) { $out[] = chr(ord('a') + mt_rand(0, 2)); } return $out; };
$a = $gen(5000); $b = $gen(5000);
$t0 = hrtime(true);
same('', diffApply($a, $b, Diff::lines($a, $b)), 'the hunks of two unrelated texts of 5000 lines apply');
check((hrtime(true) - $t0) / 1e9 < 5, 'within the cost limit');

// The rarity of a line is its record's count in the region, as git's, so that the last of repeated lines is not taken for a unique anchor.
$a = ['', '', '', "\tv14 := f13()", "\tv22 := f25()", '}', ''];
$b = ["\t\treturn err", "\treturn nil", '', '', "\tv22 := f25()", '}', ''];
same([[0, 0, 0, 2], [2, 4, 4, 4]], $hunks(Diff::lines($a, $b)), "git diff --histogram's hunks");
