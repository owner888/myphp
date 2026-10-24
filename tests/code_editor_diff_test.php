<?php
// The diff viewer (mygo's diffview_test.go): the model follows edits of the
// new text, reverts and keeps changes; the viewer collapses unchanged
// lines, aligns its sides, selects lines by their numbers, and acts in the
// side that has the keyboard.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key};
use MyPHP\UI\Editor\{Buffer, Command, DiffAnnotation, DiffHunk, DiffLayout, DiffLineRange, DiffModel, DiffSide, DiffState, Edit, Editor, Pos};

require_once __DIR__ . '/DiffTest.php';

// Edits of the new text compare again only around them, and the hunks stay those of the texts.
mt_srand(9);
$pieces = ['x', "\n", '', "line\n", "a\nb\n", '{', "}\n", "\n\n"];
$bad = '';
for ($round = 0; $round < 300 && $bad === ''; $round++) {
    $sb = '';
    $n = 30 + mt_rand(0, 29);
    for ($i = 0; $i < $n; $i++) { $sb .= ['func a() {', "\treturn", '}', '', 'x := 1', 'y'][$i % 6] . "\n"; }
    $old = new Buffer($sb); $nw = new Buffer($sb);
    $m = new DiffModel;
    $m->set($old, $nw);
    $m->update();
    for ($k = 0; $k < 20 && $bad === ''; $k++) {
        $text = (string)$nw;
        $at = mt_rand(0, strlen($text));
        $end = min(strlen($text), $at + mt_rand(0, 7));
        $nw->applyEdits(new Edit($at, $end, $pieces[mt_rand(0, count($pieces) - 1)]));
        if (mt_rand(0, 2) === 0) {
            $m->update();
            $bad = checkHunks((string)$old, (string)$nw, $m->hunks);
        }
    }
    if ($bad !== '') { break; }
    $m->update();
    $bad = checkHunks((string)$old, (string)$nw, $m->hunks);
    if ($bad === '' && $round % 10 === 0) {
        // A change of the old text compares the whole.
        $old->applyEdits(new Edit(0, 0, "// new first line\n"));
        $m->update();
        $bad = checkHunks((string)$old, (string)$nw, $m->hunks);
    }
}
same('', $bad, 'the hunks of an incremental diff are those of the texts');

// Reverting every hunk gives the old text back; keeping them makes the old text the new.
foreach ([["a\nb\nc", "a\nx\nc"], ["a\nb\nc", "a\nc"], ["a\nc", "a\nb\nc"], ["a\nb", 'a'], ['a', "a\nb"], ["b\na", 'a'], ['', "a\nb"], ["a\nb", ''], ["a\nb\n", "a\nb\nc\n"]] as [$o, $n]) {
    $old = new Buffer($o); $nw = new Buffer($n);
    $m = new DiffModel; $m->set($old, $nw); $m->update();
    for ($i = 0; $m->hunks !== [] && $i < 10; $i++) { $m->revert(0); $m->update(); }
    same($o, (string)$nw, sprintf('reverting %s to %s', json_encode($n), json_encode($o)));
    $old = new Buffer($o); $nw = new Buffer($n);
    $m = new DiffModel; $m->set($old, $nw); $m->update();
    for ($i = 0; $m->hunks !== [] && $i < 10; $i++) { $m->accept(0); $m->update(); }
    same($n, (string)$old, sprintf('accepting %s over %s', json_encode($n), json_encode($o)));
}

// The viewer: one change in 200 lines, the unchanged lines collapsed but 3 around it.
$oldText = ''; $newText = '';
for ($i = 0; $i < 200; $i++) {
    $oldText .= "line $i\n";
    $newText .= $i === 100 ? "changed\n" : "line $i\n";
}
foreach ([DiffLayout::DiffSplit, DiffLayout::DiffUnified] as $layout) {
    $d = new DiffState(new Buffer($oldText), new Buffer($newText));
    $d->layout = $layout; $d->editable = true;
    $t = diffViewer($d, 900, 500);
    $name = $layout === DiffLayout::DiffSplit ? 'split' : 'unified';
    $hs = $d->hunks();
    check(count($hs) === 1 && $hs[0] == new DiffHunk(100, 101, 100, 101), "$name: the change's hunk");
    same([1, 1], $d->stats(), "$name: a line added and one deleted");
    // 97 before, 97 after, with the empty line after the last newline.
    $gaps = $d->gapList;
    same([97, 97], array_map(fn($g) => $g->hidden, $gaps), "$name: the gaps collapse but 3 lines around the change");
    $ed = $d->eds[1];
    $lh = $ed->view->lineHeight;
    $want = (3 + 1 + 3) * $lh + 2 * DiffState::SEPARATOR_HEIGHT + ($layout === DiffLayout::DiffUnified ? $lh : 0.0);
    same($want, $ed->view->heights->total(), "$name: the lines shown and the separators make the height");
    // Expanding shows the lines.
    $d->expand($gaps[0]->a, 1, true);
    $t->frame();
    same(0, $d->gapList[0]->hidden, "$name: expanding shows the lines");
    // Editing the new side follows: reverting the change by hand.
    $ed->sync();
    $r = $ed->view->text;
    $d->new->applyEdits(new Edit($r->lineStart(100), $r->lineEnd(100), 'line 100'));
    $t->frame();
    same([], $d->hunks(), "$name: no hunks after reverting by hand");
    $d->new->undo();
    $t->frame();
    $d->revert(0);
    $t->frame();
    same((string)$d->old, (string)$d->new, "$name: Revert makes the texts the same");
}

// A split view's sides are as high as each other, line for line.
foreach ([false, true] as $wrap) {
    $d = new DiffState(new Buffer("a\nb\nc\nd\ne\nf\n"), new Buffer("a\nB\nB2\nB3\nc\nf\ng\n"));
    $d->expandUnchanged = true; $d->wrap = $wrap;
    $d->annotations = [new DiffAnnotation(DiffSide::DiffNew, 2, 40), new DiffAnnotation(DiffSide::DiffOld, 0, 25)];
    $d->renderAnnotation = function (Context $c, DiffAnnotation $a) { $c->text('note'); };
    $t = diffViewer($d, 600, 400);
    $ha = $d->eds[0]->view->heights; $hb = $d->eds[1]->view->heights;
    $w = $wrap ? 'wrapped' : 'unwrapped';
    same($ha->total(), $hb->total(), "$w: the sides are as high");
    foreach ([[0, 0], [2, 4], [5, 5]] as [$la, $lb]) {
        same($ha->geometry($la)->rows, $hb->geometry($lb)->rows, "$w: old line $la lines up with new line $lb");
    }
    check(in_array('note', $t->texts(), true), "$w: the annotation is built");
}

// Selecting lines by their numbers, and extending the selection with Shift.
$got = null;
$d = new DiffState(new Buffer("a\nb\nc\n"), new Buffer("a\nx\nc\n"));
$d->layout = DiffLayout::DiffUnified; $d->expandUnchanged = true; $d->lineSelection = true;
$d->onLineSelection = function (DiffLineRange $r) use (&$got) { $got = $r; };
$t = diffViewer($d, 600, 400);
$ed = $d->eds[1];
$lh = $ed->view->lineHeight;
// The number of new line 2, below the deleted line.
$t->click($ed->gutterW - 8, $ed->view->heights->geometry(1)->rows + $lh / 2);
$r = $d->selectedLines();
check($r == new DiffLineRange(DiffSide::DiffNew, 1, 1) && $got == $r, 'clicking a line\'s number selects it, and reports it');
$t->click($ed->gutterW - 8, $ed->view->heights->geometry(2)->rows + $lh / 2, Key::SHIFT);
check($d->selectedLines() == new DiffLineRange(DiffSide::DiffNew, 1, 2), 'Shift-clicking another extends the selection');

// The app runs commands and reads the caret in the side that has the keyboard, or had it last: the new side's until the old one has it.
$oldText = ''; $newText = '';
for ($i = 0; $i < 40; $i++) {
    $oldText .= "line $i\n";
    $newText .= $i === 5 || $i === 30 ? "changed $i\n" : "line $i\n";
}
$d = new DiffState(new Buffer($oldText), new Buffer($newText));
$d->expandUnchanged = true;
$at = function () use ($d): array {
    [$h, $in] = $d->currentHunk();
    return [$d->activeSide(), $d->cursorPos()->line, $h, $in];
};
// Before the viewer is built, the new side's caret is at the start.
check($at() === [DiffSide::DiffNew, 0, -1, false] && $d->cursor() === 0, 'before building, the new side\'s caret is at the start');
$t = diffViewer($d, 900, 500);
$d->goToHunk(1);
$t->frame();
check($at() === [DiffSide::DiffNew, 30, 1, true] && $d->cursor() === $d->new->offset(new Pos(30)), 'GoToHunk(1) puts the caret on the second change');
// Between changes, the current one is the one before the caret: the view shows the second change in its middle, lines of the first above it.
$lh = $d->eds[1]->view->lineHeight;
$t->click(200, 2.5 * $lh);
$t->frame();
[$side, $line, $hunk, $inside] = $at();
check($side === DiffSide::DiffOld && $line > 5 && $line < 30 && $hunk === 0 && !$inside, 'clicking in the old side makes it active, after the first change');
// The old side stays active as the keyboard goes.
$focus = $t->rt->focus;
$t->rt->focus = null;
$t->frame();
same(DiffSide::DiffOld, $d->activeSide(), 'the old side stays active without the keyboard');
// GoToHunk moves the caret CursorPos reads, so an app's Next Change steps from it.
[$h] = $d->currentHunk();
$d->goToHunk($h + 1);
$t->frame();
same([DiffSide::DiffOld, 30, 1, true], $at(), 'GoToHunk acts in the old side');
$t->rt->focusOn($focus);
$t->frame();
check($d->run(Command::FIND), 'Run(Find) opens a find bar');
$t->frame();
check($d->eds[0]->find->open && !$d->eds[1]->find->open, 'in the old side\'s editor');
// Back in the new side, between the changes, Find selects the match after the caret.
$t->click(650, 2.5 * $lh);
$t->frame();
$d->find('changed');
$t->frame();
[$side, $line] = $at();
check($side === DiffSide::DiffNew && $line === 30 && $d->eds[1]->find->open, 'Find in the new side selects the match after the caret');
// A unified view has only the new side's editor.
$d->layout = DiffLayout::DiffUnified;
$t->frame();
same(DiffSide::DiffNew, $d->activeSide(), 'a unified view acts in the new side');

// The caret is in a hunk on the lines it changes, and in a deletion on the line of the side without its lines where they were; after the last line of a hunk, it is after it.
$d = new DiffState(new Buffer("a\nb\nc\nd\ne\n"), new Buffer("a\nB\nc\ne\n"));
$d->expandUnchanged = true;
same(2, count($d->hunks()), 'two hunks');
$ed = $d->editorOf(DiffSide::DiffNew);
foreach ([[0, -1, false], [1, 0, true], [2, 0, false], [3, 1, true], [4, 1, false]] as [$line, $hunk, $inside]) {
    $off = $d->new->offset(new Pos($line));
    $ed->setSels([$ed->newSel($off, $off)], Editor::REVEAL_NONE);
    same([$hunk, $inside], $d->currentHunk(), "the current hunk on new line $line");
}
