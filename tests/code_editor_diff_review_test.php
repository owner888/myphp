<?php
// Regressions found by reviewing the diff viewer (mygo's diff_review_test.go).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};
use MyPHP\UI\Editor\{Block, Buffer, Command, DiffAnnotation, DiffHunk, DiffLayout, DiffLineRange, DiffModel, DiffSide, DiffState, Edit, FilePatch, State};

require_once __DIR__ . '/DiffTest.php';

// One version changing several places, as typing at several carets or reverting hunks at once, re-diffs right.
$lines = [];
for ($i = 0; $i < 50; $i++) { $lines[] = "l$i"; }
$oldS = implode("\n", $lines) . "\n";
$lines[40] = 'Z';
$old = new Buffer($oldS); $nw = new Buffer(implode("\n", $lines) . "\n");
$m = new DiffModel; $m->set($old, $nw); $m->update();
$r = $nw->rope();
$nw->applyEdits(new Edit($r->lineStart(1), $r->lineStart(21)), new Edit($r->lineStart(25), $r->lineStart(25), "\n"));
$m->update();
same('', checkHunks($oldS, (string)$nw, $m->hunks), 'a version of edits in two places re-diffs right');
$bad = '';
for ($seed = 0; $seed < 500 && $bad === ''; $seed++) {
    mt_srand($seed);
    $a = [];
    for ($i = 0; $i < 40; $i++) { $a[] = 'line ' . ($i % 13); }
    $oldS = implode("\n", $a) . "\n";
    $old = new Buffer($oldS); $nw = new Buffer($oldS);
    $m = new DiffModel; $m->set($old, $nw); $m->update();
    for ($k = 0; $k < 6; $k++) {
        $len = $nw->len();
        $edits = []; $at = 0;
        for ($e = 1 + mt_rand(0, 2); $e > 0; $e--) {
            $start = min($len, $at + mt_rand(0, 39));
            $end = min($len, $start + mt_rand(0, 11));
            $edits[] = new Edit($start, $end, ['', "\n", "x\n", "line 3\n", 'q'][mt_rand(0, 4)]);
            $at = $end + 1;
        }
        $nw->applyEdits(...$edits);
        $m->update();
    }
    if (($bad = checkHunks($oldS, (string)$nw, $m->hunks)) !== '') { $bad = "seed $seed: $bad"; }
}
same('', $bad, 'versions of random edits in several places re-diff right');

// New becoming the same buffer as Old shows no changes, rather than the blocks of those there were.
$a = new Buffer("a\nb\nc\n");
$d = new DiffState($a, new Buffer("a\nX\nc\n"));
$d->layout = DiffLayout::DiffUnified; $d->expandUnchanged = true;
$t = diffViewer($d, 600, 300);
$d->new = $a;
$t->frame();
same([], $d->hunks(), 'a buffer compared with itself has no changes');

// A patch shows its own - and + runs, not a diff of its lines' texts.
$files = FilePatch::parse("--- a/x\n+++ b/x\n@@ -1,5 +1,2 @@\n c1\n-X\n-Y\n-Z\n c2\n@@ -40,2 +37,5 @@\n c3\n+X\n+Y\n+Z\n c4\n");
$d = new DiffState;
$d->setPatch($files[0]);
$t = diffViewer($d, 800, 400);
check($d->hunks() == [new DiffHunk(1, 4, 1, 1), new DiffHunk(6, 6, 3, 6)], 'a patch\'s hunks are its runs of - and + lines');
// A new file deletes nothing.
$files = FilePatch::parse("--- /dev/null\n+++ b/n.md\n@@ -0,0 +1,2 @@\n+# Title\n+text\n");
$d = new DiffState;
$d->setPatch($files[0]);
same([2, 0], $d->stats(), 'a new file adds its lines and deletes nothing');
$d = new DiffState(new Buffer(''), new Buffer('x'));
same([1, 0], $d->stats(), 'a text from nothing adds its line');

// The header of a last hunk that only adds lines goes after the old side's last line, level with the new side's.
$files = FilePatch::parse("--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n a\n-b\n+B\n c\n@@ -10,0 +11,2 @@\n+new1\n+new2\n");
$d = new DiffState;
$d->setPatch($files[0]);
$t = diffViewer($d, 800, 400);
$t->frame();
same($d->eds[0]->view->heights->geometry(2)->rows, $d->eds[1]->view->heights->geometry(2)->rows, 'line c is level on both sides of a patch whose last hunk only adds');

// With no context lines, deletions and separators still show.
$old = "a\nb\nc\nd\ne\nf\ng\nh\ni\nj\n";
$nw = str_replace("f\n", '', $old);
foreach ([DiffLayout::DiffUnified, DiffLayout::DiffSplit] as $layout) {
    $d = new DiffState(new Buffer($old), new Buffer($nw));
    $d->contextLines = -1; $d->layout = $layout;
    $t = diffViewer($d, 600, 400);
    $t->frame();
    check($d->eds[1]->view->heights->total() > 0, "layout {$layout->name}: the new side shows with no context lines");
    if ($layout === DiffLayout::DiffSplit) { same($d->eds[0]->view->heights->total(), $d->eds[1]->view->heights->total(), 'and the sides are as high'); }
}

// Both sides of a split view wrap alike where their line counts have different numbers of digits.
$a = [];
for ($i = 0; $i < 995; $i++) { $a[] = str_repeat('w', $i % 300) . ' ' . str_repeat('ab ', $i % 7); }
$b = $a;
array_splice($b, 1, 0, array_fill(0, 10, 'added'));
$d = new DiffState(new Buffer(implode("\n", $a)), new Buffer(implode("\n", $b)));
$d->wrap = true; $d->expandUnchanged = true;
$t = diffViewer($d, 700, 400);
$t->frame();
same($d->eds[0]->gutterW, $d->eds[1]->gutterW, 'the gutters of the sides are as wide');

// Lines expanded stay expanded as the new text is edited among them.
$lines = [];
for ($i = 0; $i < 100; $i++) { $lines[] = "line $i"; }
$old = implode("\n", $lines) . "\n";
$lines[10] = 'changed';
$nw = implode("\n", $lines) . "\n";
foreach ([50, 11] as $at) {
    $d = new DiffState(new Buffer($old), new Buffer($nw));
    $t = diffViewer($d, 600, 400);
    $t->frame();
    $d->expand($d->gapList[1]->a, 1, true);
    $t->frame();
    $r = $d->new->rope();
    $d->new->applyEdits(new Edit($r->lineEnd($at), $r->lineEnd($at), 'x'));
    $t->frame(); $t->frame();
    same([0], array_values(array_unique(array_map(fn($g) => $g->hidden, array_slice($d->gapList, 1)))), "editing line $at leaves the lines expanded");
}

// Lines deleted from the end of a text without a final newline are a change of its last line, which reverts and shows in the gutter.
$st = new State(new Buffer("a\nb"));
$st->diffBase = new Buffer("a\nb\nc");
$t = new Headless(function (Context $c) use ($st, &$node) { $node = $st->view($c); $node->grow = 1; }, 400, 200);
$t->paints = true;
$t->frame();
$t->rt->focusOn($node->id);
$t->frame();
$g = (fn() => $this->ed->gitDiff)->call($st);
check($g !== null && $g->status(1) === 3, 'the gutter marks the lines deleted after the last line');
$st->select(3, 3);
$st->run(Command::REVERT_HUNK);
same("a\nb\nc", (string)$st->buffer, 'reverting puts them back');

// Annotations on one line go one after the other, with keys or without.
$d = new DiffState(new Buffer("a\nb\n"), new Buffer("a\nB\n"));
$d->annotations = [new DiffAnnotation(DiffSide::DiffNew, 0, 30), new DiffAnnotation(DiffSide::DiffNew, 0, 40)];
$d->renderAnnotation = function (Context $c, DiffAnnotation $a) { $c->text('note ' . $a->height); };
$t = diffViewer($d, 600, 400);
$t->frame();
$n1 = $t->byText('note 30'); $n2 = $t->byText('note 40');
check($n1 !== null && $n2 !== null && $t->rect($n2)[1] - $t->rect($n1)[1] >= 29, 'annotations on a line stack');

// An annotation on the last line shown goes above the separator after it.
$lines = [];
for ($i = 0; $i < 30; $i++) { $lines[] = "line $i"; }
$old = implode("\n", $lines) . "\n";
$lines[5] = 'changed';
foreach ([DiffLayout::DiffSplit, DiffLayout::DiffUnified] as $layout) {
    $d = new DiffState(new Buffer($old), new Buffer(implode("\n", $lines) . "\n"));
    $d->layout = $layout;
    $d->annotations = [new DiffAnnotation(DiffSide::DiffNew, 8, 30)];
    $d->renderAnnotation = function (Context $c, DiffAnnotation $a) { $c->text('the note'); };
    $t = diffViewer($d, 600, 600);
    $t->frame();
    $note = $t->byText('the note'); $sep = $t->byText('22 unmodified lines');
    check($note !== null && $sep !== null && $t->rect($note)[1] <= $t->rect($sep)[1], "layout {$layout->name}: the note goes above the separator after it");
}

// The unified view shows the old text's annotations of unchanged lines.
$d = new DiffState(new Buffer("a\nb\nc"), new Buffer("a\nX\nc"));
$d->layout = DiffLayout::DiffUnified;
$d->annotations = [new DiffAnnotation(DiffSide::DiffOld, 2, 30)];
$d->renderAnnotation = function (Context $c, DiffAnnotation $a) { $c->text('old note'); };
$t = diffViewer($d, 600, 300);
$t->frame();
check(in_array('old note', $t->texts(), true), 'a unified view shows the old side\'s annotations of unchanged lines');

// Clicking the number of a wrapped deleted line selects that line.
$d = new DiffState(new Buffer("a\n" . str_repeat('word ', 60) . "\nsecond\nc\n"), new Buffer("a\nc\n"));
$d->layout = DiffLayout::DiffUnified; $d->wrap = true; $d->lineSelection = true;
$t = diffViewer($d, 500, 400);
$t->frame();
$ed = $d->eds[1];
[$above] = $ed->view->heights->blocksOf(1);
check($above !== [] && $above[0]->kind === Block::DELETED, 'the deleted lines are a block above the line after them');
$top = $ed->view->heights->geometry(1)->top - $ed->scrollY;
$t->click(10, $top + $ed->view->lineHeight * 1.5); // the deleted line's second row
check($d->selectedLines() == new DiffLineRange(DiffSide::DiffOld, 1, 1), 'clicking a wrapped deleted line\'s second row selects it');

// Names git quotes read as the names, in renames and new files.
$files = FilePatch::parse("diff --git \"a/rn/old/\\346\\226\\207.txt\" \"b/rn/new/\\346\\226\\207.txt\"\nsimilarity index 100%\nrename from \"rn/old/\\346\\226\\207.txt\"\nrename to \"rn/new/\\346\\226\\207.txt\"\n"
    . "diff --git \"a/my file.txt\" \"b/my file.txt\"\nnew file mode 100644\nindex 0000000..e69de29\n");
same(['rn/old/文.txt', 'rn/new/文.txt'], [$files[0]->oldName, $files[0]->newName], 'a rename\'s quoted names');
same(['', 'my file.txt'], [$files[1]->oldName, $files[1]->newName], 'a new file\'s quoted name');

// A patch of a file with \r\n endings applies to it.
$files = FilePatch::parse("--- a/x\r\n+++ b/x\r\n@@ -1,3 +1,3 @@\r\n a\r\n-b\r\n+x\r\n c\r\n");
same("a\r\nx\r\nc\r\n", $files[0]->apply("a\r\nb\r\nc\r\n"), 'a patch with \\r\\n endings applies');

// A hunk with more lines than its header counts is an error.
$threw = false;
try { FilePatch::parse("--- a/x\n+++ b/x\n@@ -1,1 +1,3 @@\n a\n b\n+c\n"); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a hunk longer than it counts does not parse');
