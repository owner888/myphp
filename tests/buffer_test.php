<?php
// The editor's buffer (mygo's buffer_test): edits, undo and redo, and the
// log that moves offsets and lines through edits as the text moved.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Buffer, Change, Edit, Pos};

$b = new Buffer("hello\nworld\n");
same([3, 'world', false], [$b->lineCount(), $b->line(1), $b->modified()], 'a new buffer: its lines, unmodified');
$b->applyEdits(new Edit(0, 5, 'goodbye'), new Edit(6, 6, 'big '));
same("goodbye\nbig world\n", (string)$b, 'two edits, in the text before either');
check($b->modified() && $b->canUndo(), 'modified after an edit');
$b->undo();
same(["hello\nworld\n", false], [(string)$b, $b->modified()], 'undo restores the text, unmodified');
$b->redo();
same("goodbye\nbig world\n", (string)$b, 'redo');
check($b->pos(12)->equals(new Pos(1, 4)), 'pos of a byte');
same(17, $b->offset(new Pos(1, 100)), 'offset of a column past the line end, clamped');
$b->transact(function () use ($b) {
    $b->applyEdits(new Edit(0, 0, 'a'));
    $b->applyEdits(new Edit(0, 0, 'b'));
});
$b->undo();
same("goodbye\nbig world\n", (string)$b, 'transact groups edits into one undo');
$b->redo();
same("bagoodbye\nbig world\n", (string)$b, 'and one redo');

// Typing in a row is one step; another kind, or a pause, starts another.
$b = new Buffer('');
$b->edit([new Edit(0, 0, 'a')], \MyPHP\UI\Editor\Transaction::TYPING, 'ed', null);
$b->edit([new Edit(1, 1, 'b')], \MyPHP\UI\Editor\Transaction::TYPING, 'ed', null);
$b->edit([new Edit(2, 2, 'c')], \MyPHP\UI\Editor\Transaction::TYPING, 'other', null);
$b->undo();
same('ab', (string)$b, 'another editor\'s typing is its own step');
$b->undo();
same('', (string)$b, 'typing in a row is one step');
$b->redo(); $b->endGroup();
$b->edit([new Edit(2, 2, 'd')], \MyPHP\UI\Editor\Transaction::TYPING, 'ed', null);
$b->undo();
same('ab', (string)$b, 'endGroup starts a new step');

// \r\n texts keep \n lines and remember their ending.
$b = new Buffer("a\r\nb");
same(["a\nb", "\r\n", "a\r\nb"], [(string)$b, $b->lineEnding(), $b->snapshot()->text()], 'crlf: the text, its ending and what is saved');
$b->reset("x\ny");
same(["x\ny", "\n", false, false], [(string)$b, $b->lineEnding(), $b->canUndo(), $b->modified()], 'reset forgets the history and saves');

// The log moves offsets and lines through edits as the text moved.
mt_srand(56);
$b = new Buffer("one\ntwo\nthree\nfour\nfive\n");
$ok = true;
for ($step = 0; $step < 500 && $ok; $step++) {
    $was = $b->rope(); $v = $b->version();
    $text = (string)$was;
    $at = mt_rand(0, strlen($text)); $end = min(strlen($text), $at + mt_rand(0, 5));
    $ins = ['', 'x', "\n", "ab\ncd", "\n\n"][mt_rand(0, 4)];
    $probe = mt_rand(0, strlen($text));
    $b->applyEdits(new Edit($at, $end, $ins));
    if ((string)$b === $text) { continue; }
    $to = $b->changesTo($v);
    if ($to === null || count($to[0]) !== 1) { $ok = false; break; }
    $changes = $to[0][0];
    $got = Change::mapOffset($probe, $changes, Change::RIGHT);
    if ($probe < $at) { $want = $probe; }
    elseif ($probe >= $end && ($probe > $at || $end > $at)) { $want = $probe + strlen($ins) - ($end - $at); }
    else { $want = $at + strlen($ins); }
    if ($probe > $at && $probe < $end) { $want = $at + strlen($ins); }
    if ($got !== $want) { $ok = false; break; }
    $after = $b->rope();
    $pl = $was->lineOf($probe);
    if ($was->lineStart($pl) > $end && $pl > $was->lineOf($end)) { // lines wholly after the change keep their text
        $nl = Change::mapLine($pl, $changes);
        if ($after->line($nl) !== $was->line($pl)) { $ok = false; break; }
    }
    if ($after->lineCount() !== substr_count((string)$after, "\n") + 1) { $ok = false; break; }
}
check($ok, 'mapOffset and mapLine follow 500 random edits');

// A reader too far behind the log gets the changes between the texts by their lines.
$old = \MyPHP\UI\Editor\RopeText::new("a\nb\nc\n"); $new = \MyPHP\UI\Editor\RopeText::new("a\nx\nc\n");
$cs = Buffer::changesBetween($old, $new);
same([[2, 4, 2, 4, 1, 2, 1, 2]], array_map(fn($c) => [$c->oldStart, $c->oldEnd, $c->newStart, $c->newEnd, $c->oldLine, $c->oldEndLine, $c->newLine, $c->newEndLine], $cs), 'changesBetween by lines');
