<?php
// A TextBuffer (mygo's text_buffer_test.go TestTextBufferRandomEditsAndSnapshots,
// TestTextBufferNativeRangesMalformedAndZero; the tests of chunk ownership
// and concurrent snapshots are Go's): random edits keep its text, its
// counts and native offsets, lines and tree right, snapshots untouched;
// an empty buffer, a UTF-16 range inside a surrogate pair, malformed
// UTF-8 to native input, and a stream.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\Rope;
use MyPHP\UI\{TextBuffer, TextInputRange, TextState};

$u16 = fn(string $s): int => intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);

$s = str_repeat("ab😀é\n日本語", 600);
$b = new TextBuffer($s);
$original = $b->snapshot();
$rnd = new \Random\Randomizer(new \Random\Engine\PcgOneseq128XslRr64(1171));
$alphabet = mb_str_split("ab \n\r😀é日本語אבג");
$want = mb_str_split($s);
$bad = '';
for ($step = 0; $step < 2500 && $bad === ''; $step++) {
    $a = $rnd->getInt(0, count($want));
    $z = min(count($want), $a + $rnd->getInt(0, 11));
    $ins = [];
    for ($k = $rnd->getInt(0, 9); $k > 0; $k--) { $ins[] = $alphabet[$rnd->getInt(0, count($alphabet) - 1)]; }
    $b->replace($a, $z, implode('', $ins));
    array_splice($want, $a, $z - $a, $ins);
    $text = implode('', $want);
    $v = $b->snapshot();
    if ($v->string() !== $text || $v->len() !== count($want) || $v->utf16Len() !== $u16($text) || $v->lineCount() !== substr_count($text, "\n") + 1) { $bad = "edit $step: the text or its counts"; break; }
    Rope::check($v->root);
    for ($k = 0; $k < 3; $k++) {
        $i = $rnd->getInt(0, count($want));
        $head = implode('', array_slice($want, 0, $i));
        $units = $u16($head);
        if ($v->utf16Offset($i) !== $units || $v->runeOffset($units) !== $i) { $bad = "edit $step: the native offset at $i"; break; }
        $line = substr_count($head, "\n");
        if ($v->lineAt($i) !== $line) { $bad = "edit $step: the line at $i"; break; }
        if ($v->line($line) !== explode("\n", $text)[$line]) { $bad = "edit $step: line $line"; break; }
    }
}
same('', $bad, '2500 random edits');
same($s, $original->string(), 'the edits left the snapshot');
$b->restore($original);
same($s, $b->string(), 'restored');

// Empty; a UTF-16 range inside a surrogate pair; malformed UTF-8 to native input; streamed.
$e = new TextBuffer();
check($e->len() === 0 && $e->lineCount() === 1 && $e->slice(-10, 100) === '', 'empty');
$e->replace(0, 0, "A😀\n\xfftail");
$actual = $e->replaceUTF16(new TextInputRange(2, 3), 'é');
check($actual == new TextInputRange(1, 3) && $e->string() === "Aé\n\xfftail", 'a range inside the surrogate pair widened to it: ' . json_encode([$actual->start, $actual->end]));
[$doc, $r] = $e->textForRange(new TextInputRange(0, 100));
check($doc === "Aé\n\u{FFFD}tail" && $r->end === 8, 'malformed UTF-8 to native input as U+FFFD: ' . json_encode([$doc, $r->end]));
$out = fopen('php://memory', 'w+');
$written = $e->writeTo($out); rewind($out);
check($written === $e->byteLen() && stream_get_contents($out) === $e->string(), 'streamed');
