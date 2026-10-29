<?php
// The rope (mygo's internal/rope tests): random edits keep the tree
// balanced and its text, lines, UTF-16 and chunks right.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Rope, RopeText};

// Text of several chunks, with multi-byte runes, runes outside the BMP and empty lines.
$sample = '';
for ($i = 0; $i < 3000; $i++) {
    $sample .= match ($i % 5) { 0 => "func main() {\n", 1 => "\tfmt.Println(\"héllo, 世界\")\n", 2 => "\n", 3 => "// 😀 emoji\n", default => "}\n" };
}
$sample .= 'last';

$checkLines = function (RopeText $text, string $s): bool {
    $lines = explode("\n", $s);
    if ($text->lineCount() !== count($lines)) { return false; }
    $off = 0;
    foreach ($lines as $i => $l) {
        if ($text->lineStart($i) !== $off || $text->lineEnd($i) !== $off + strlen($l) || $text->line($i) !== $l) { return false; }
        if ($text->lineOf($off) !== $i || $text->lineOf($off + strlen($l)) !== $i) { return false; }
        $off += strlen($l) + 1;
    }
    return true;
};

mt_srand(12);
$s = $sample; $text = RopeText::new($s);
$pieces = ['', 'x', "\n", "é\n世", '😀', str_repeat("abc\n", 2000)];
$ok = true; $linesOk = true; $fresh = true;
for ($step = 0; $step < 400; $step++) {
    $a = mt_rand(0, strlen($s)); $b = $a + mt_rand(0, min(strlen($s) - $a, 5000));
    while ($a > 0 && (ord($s[$a]) & 0xC0) === 0x80) { $a--; } // the edges on runes, as the editor keeps them
    while ($b < strlen($s) && (ord($s[$b]) & 0xC0) === 0x80) { $b++; }
    $in = $pieces[mt_rand(0, count($pieces) - 1)];
    $old = $text;
    $text = $text->replace($a, $b, $in);
    $s = substr($s, 0, $a) . $in . substr($s, $b);
    if ($old->same($text)) { $fresh = false; }
    Rope::check($text->n);
    if ($text->len() !== strlen($s) || (string)$text !== $s) { $ok = false; break; }
    if ($step % 100 === 0 && !$checkLines($text, $s)) { $linesOk = false; }
}
check($ok, 'the text matches the string through 400 random edits');
check($fresh, 'each replace returns a new version');
check($linesOk && $checkLines($text, $s), 'the lines match the string');

// Lines, batch by batch.
$s = $sample; $text = RopeText::new($s);
$lines = explode("\n", $s); $starts = [0];
for ($i = 1; $i < count($lines); $i++) { $starts[$i] = $starts[$i - 1] + strlen($lines[$i - 1]) + 1; }
$ok = true;
foreach ([[0, count($lines)], [0, 1], [255, 257], [700, 1300], [count($lines) - 3, count($lines) + 10], [-5, 3]] as [$from, $to]) {
    $next = max(0, $from);
    $text->eachLine($from, $to, function ($i, $start, $l) use (&$next, $starts, $lines, &$ok) {
        if ($i !== $next || $start !== $starts[$i] || $l !== $lines[$i]) { $ok = false; }
        $next++;
        return true;
    });
    if ($next !== min($to, count($lines))) { $ok = false; }
}
check($ok, 'eachLine walks the lines of each range');
$ok = true;
foreach ([[count($lines) - 1, 0], [0, 0], [600, 300], [count($lines) + 10, count($lines) - 4], [3, -5]] as [$from, $to]) {
    $next = min($from, count($lines) - 1);
    $text->eachLineBack($from, $to, function ($i, $start, $l) use (&$next, $starts, $lines, &$ok) {
        if ($i !== $next || $start !== $starts[$i] || $l !== $lines[$i]) { $ok = false; }
        $next--;
        return true;
    });
    if ($next !== max($to, 0) - 1) { $ok = false; }
}
check($ok, 'eachLineBack walks the lines back');
$n = 0; $text->eachLine(0, count($lines), function ($i) use (&$n) { $n++; return $i < 300; });
same(301, $n, 'returning false stops eachLine at once');
$n = 0; $text->eachLineBack(count($lines) - 1, 0, function () use (&$n) { $n++; return false; });
same(1, $n, 'and eachLineBack');

// UTF-16 units and runes.
$ok = true; $units = 0; $off = 0;
foreach (mb_str_split($s, 1, 'UTF-8') as $r) {
    if ($text->utf16($off) !== $units || $text->fromUTF16($units) !== $off || $text->runeAt($off) !== [$r, strlen($r)]) { $ok = false; break; }
    $units += strlen($r) === 4 ? 2 : 1;
    $off += strlen($r);
}
check($ok, 'utf16, fromUTF16 and runeAt agree with the string at every rune');
check($text->utf16(strlen($s)) === $units && $text->fromUTF16($units) === strlen($s), 'and at the end');
same(['t', 1], $text->runeBefore(strlen($s)), 'runeBefore the end');
same(['界', 3], $text->runeBefore($text->lineEnd(1) - 2), 'runeBefore a multi-byte rune');

// Chunks.
$ok = true;
foreach ([[0, strlen($s)], [10, 10], [Rope::CHUNK - 3, Rope::CHUNK + 3], [5000, 20000]] as [$a, $b]) {
    $got = ''; $text->chunks($a, $b, function ($c) use (&$got) { $got .= $c; return true; });
    if ($got !== substr($s, $a, $b - $a) || $text->slice($a, $b) !== substr($s, $a, $b - $a)) { $ok = false; }
}
check($ok, 'chunks and slice match the string');

// Long lines are read in batches of 64 KB, a line at least (mygo's 9965d50): each line whole, in order both ways.
$long = []; foreach (range(0, 9) as $i) { $long[] = str_repeat(chr(97 + $i), 30000 + $i * 7000); }
$text = RopeText::new(implode("\n", $long));
$got = []; $text->eachLine(0, 10, function ($i, $start, $l) use (&$got) { $got[] = [$i, $start, strlen($l), $l[0]]; return true; });
$want = []; $at = 0; foreach ($long as $i => $l) { $want[] = [$i, $at, strlen($l), $l[0]]; $at += strlen($l) + 1; }
same($want, $got, 'long lines, whole and in order');
$got = []; $text->eachLineBack(9, 0, function ($i, $start, $l) use (&$got) { $got[] = [$i, $start, strlen($l), $l[0]]; return true; });
same(array_reverse($want), $got, 'and back');
