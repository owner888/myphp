<?php
// Outlines and outline tables (mygo's outline.go), step sliders and toggle
// groups (widgets.go, toggle.go), toasts with actions (toast.go), actions
// run after the build (actions.go), handles (ref.go) and OpenURLThen.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Handle, Key, Node, OutlineState, TableColumn, Theme, Toast};

function olFind(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label || $n->text === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = olFind($k, $label)) !== null) { return $f; } }
    return null;
}

$tree = ['/' => ['/a', '/b'], '/a' => ['/a/x', '/a/y'], '/a/x' => ['/a/x/1'], '/b' => [], '/a/y' => null, '/a/x/1' => null];
$kids = fn(int|string $p): ?array => $tree[$p] ?? null;

// Rows: the roots, and the children of the items open, deeper as they go.
$s = new OutlineState;
$s->list->selected = 0;
$h = new Headless(function (Context $c) use ($s, $kids): void {
    $c->outline($s, ['/'], $kids, function (int|string $p) use ($c): void { $c->text((string)$p); })->size(300, 300);
}, 300, 300);
$h->frame();
same(1, $s->rows(), 'one root, closed');
$s->open->add('/'); $h->frame();
same(['/', '/a', '/b'], [$s->item(0), $s->item(1), $s->item(2)], 'its children below it');
same([0, 1], [$s->depth(0), $s->depth(1)], 'one level deeper');
// Right on /a, chosen, opens it; Right again goes to its first child; Left back to it, Left closes it.
$h->rt->focus = $h->root->kids[0]->id; $s->list->selected = 1;
$h->right(); $h->frame();
check($s->open->has('/a'), 'Right opens the item chosen');
$h->right(); $h->frame();
same(2, $s->list->selected, 'Right again goes to its first child');
$h->left(); $h->frame(); $h->left(); $h->frame();
check(!$s->open->has('/a') && $s->list->selected === 1, 'Left goes to the parent, then closes it');
// Option with Right opens all inside.
$h->key(Key::RIGHT, 'moveRight:', Key::OPTION); $h->frame();
check($s->open->has('/a') && $s->open->has('/a/x'), 'Option+Right opens all inside');
same(6, $s->rows(), 'every row shown');
// A click on the arrow closes the item; the choice inside moves to it.
$s->list->selected = 3; $h->frame();
$arrow = $h->root->kids[0]->kids[1]->kids[1];
$h->click($arrow->x + 2, $arrow->y + 2); $h->frame();
check(!$s->open->has('/a') && $s->list->selected === 1, 'the arrow closes it, the choice going to it');
// Assistive technology sees the level of each row.
same(2, $h->root->kids[0]->kids[1]->level, 'the row of /a at level 2');

// An outline table: the arrow in the first column.
$s2 = new OutlineState; $s2->open->add('/');
$h = new Headless(function (Context $c) use ($s2, $kids): void {
    $c->outlineTable($s2, [new TableColumn('Name'), new TableColumn('Kind', width: 80)], ['/'], $kids, function (int|string $p, int $col) use ($c): void { $c->text($col === 0 ? (string)$p : 'dir'); });
}, 400, 300);
$h->frame();
same(3, $s2->rows(), 'the table\'s rows');
check(olFind($h->root, '/a') !== null && olFind($h->root, 'dir') !== null, 'its cells');

// A step slider snaps to its steps, with a tick at each.
$v = 0.0; $sl = null;
$h = new Headless(function (Context $c) use (&$v, &$sl): void { $sl = $c->stepSlider($v, 0, 10, 2.5, 200); }, 300, 100);
$h->frame();
same(5, $sl->ticks, 'five ticks');
$h->rt->focus = $sl->id; $h->right(); $h->frame();
same(2.5, $v, 'the arrow moves a step');
$h->click($sl->x + $sl->w * 0.6, $sl->y + 5); $h->frame();
same(5.0, $v, 'a press snaps to the nearest step');

// A toggle group: its toggles segments of one control, one stop of Tab.
$bold = false; $italic = true; $g = null;
$h = new Headless(function (Context $c) use (&$bold, &$italic, &$g): void { $g = $c->toggleGroup(function () use ($c, &$bold, &$italic): void { $c->toggle($bold, 'B'); $c->toggle($italic, 'I'); })->label('Style'); $c->end(); }, 300, 100);
$h->frame();
check($g->kids[0]->segment && !$g->kids[0]->bordered && $g->kids[1]->shadows !== [], 'segments, the one on raised');

// Toasts: an action closes its toast and runs; one shows four seconds unless it says; closeToast runs onClose.
$now = 0; $undone = 0; $closed = [];
$h = poseTester(function (Context $c) use (&$undone, &$closed): void {
    if ($c->clicked($c->button('Delete'))) { $c->toastAction('Deleted', 'Undo', function () use (&$undone): void { $undone++; }); }
    if ($c->clicked($c->button('Upload'))) { $c->addToast(new Toast('up', 'Uploading', timeout: -1, onClose: function () use (&$closed): void { $closed[] = 'up'; })); }
    if ($c->clicked($c->button('Done'))) { $c->closeToast('up'); }
    $c->toasts();
}, $now, 400, 300);
$h->frame();
$b = olFind($h->root, 'Delete'); $h->click($b->x + 2, $b->y + 2); $h->frame();
$undo = end($h->root->kids)->kids[0]->kids[1] ?? null; // the toast's action, after its text
check($undo !== null && olFind($h->root, 'Deleted') !== null, 'the toast with its action');
$h->click($undo->x + 2, $undo->y + 2); $h->frame();
check($undone === 1 && olFind($h->root, 'Deleted') === null, 'Undo runs and closes the toast');
$b = olFind($h->root, 'Upload'); $h->click($b->x + 2, $b->y + 2); $h->frame();
$now += 60_000_000_000; $h->frame();
check(olFind($h->root, 'Uploading') !== null, 'a toast of no timeout stays');
$b = olFind($h->root, 'Done'); $h->click($b->x + 2, $b->y + 2); $h->frame();
same(['up'], $closed, 'closeToast runs onClose');
$h->rt->addToast(new Toast(title: 'Saved')); $h->frame();
check(olFind($h->root, 'Saved') !== null, 'a toast');
$now += 4_100_000_000; $h->frame();
check(olFind($h->root, 'Saved') === null, 'gone after four seconds');
same([0x18181b, 0xffffff], Theme::light()->inverse(), 'toasts in the theme turned over');

// Actions after the build: a click, a shortcut in the focus, the window's shortcut.
$log = [];
$h = new Headless(function (Context $c) use (&$log): void {
    $c->button('Go')->onClick(function () use (&$log): void { $log[] = 'click'; })->onShortcut('k', Key::COMMAND, function () use (&$log): void { $log[] = 'focused'; });
    $c->onShortcut('j', Key::COMMAND, function () use (&$log): void { $log[] = 'window'; });
}, 300, 100);
$h->frame();
$h->click(5, 5); $h->frame();
$h->shortcut('k', Key::COMMAND); $h->frame();
$h->shortcut('j', Key::COMMAND); $h->frame();
same(['click', 'focused', 'window'], $log, 'each once');

// Handles: a focus asked before the control is built waits for it.
$hd = new Handle; $show = false;
$h = new Headless(function (Context $c) use ($hd, &$show): void {
    $c->button('A');
    if ($show) { $c->button('B')->bind($hd); }
}, 300, 100);
$h->frame();
$hd->focus(); $h->frame();
same(null, $h->rt->focus, 'nothing to focus yet');
$show = true; $h->frame();
$c = new Context($h->metrics, $h->rt);
check($hd->focused($c), 'the control takes the focus once built');
same(60.0 > 0, $hd->bounds($c)[2] > 0, 'its box');

// OpenURLThen: what came of it before the next frame.
$got = 'none';
$h = new Headless(function (Context $c) use (&$got): void {
    if ($c->clicked($c->button('Open'))) { $c->openURLThen('https://example.com', function (?\Throwable $e) use (&$got): void { $got = $e?->getMessage(); }); }
}, 300, 100);
$h->rt->openURL = function (string $u): void { throw new \RuntimeException("no application can open $u"); };
$h->frame(); $h->click(5, 5); $h->frame();
same('no application can open https://example.com', $got, 'the error, a frame later');

// Fonts: registered families first, then the system's, each once, sorted.
$sys = new \MyPHP\Text\System(new \MyPHP\Text\StubEngine());
$sys->registerFont('mono data', 'Zed Mono');
$sys->registerFont('data');
same([['Stub Sans', false, true], ['Zed Mono', true, true], ['Helvetica', false, false], ['Menlo', true, false]],
    array_map(fn($f) => [$f->name, $f->monospaced, $f->registered], $sys->fontFamilies()), 'the app\'s families, then the system\'s');
$threw = false;
try { $sys->registerFont(''); } catch (\RuntimeException) { $threw = true; }
check($threw, 'no data, no font');

// The theme follows more contrast and larger text.
$hc = Theme::light()->follow(new \MyPHP\UI\Preferences(highContrast: true, textScale: 1.5));
check($hc->border !== Theme::light()->border && $hc->focusAlpha === 1.0 && $hc->fontSize === 19.5, 'darker borders, an opaque ring, larger text');

// Oklch: CSS's colors, mapped into the gamut keeping lightness and hue.
same([0xff0000, 0x636363, 0xffffff], [\MyPHP\UI\Oklch::rgb(0.628, 0.2577, 29.23), \MyPHP\UI\Oklch::rgb(0.5, 0, 0), \MyPHP\UI\Oklch::rgb(1, 0.2, 40)], 'red, a gray, white');

// An image updated in place keeps its slot; one of another size takes a new one.
$img = \MyPHP\UI\Image::fromPixels(1, 1, "\x10\x20\x30\xff");
$key = $img->key;
$img->update(1, 1, "\x40\x50\x60\xff");
check($img->key === $key && $img->updated && $img->pixels() === "\x40\x50\x60\xff", 'the same slot, new pixels');
$img->update(2, 1, str_repeat("\0\0\0\xff", 2));
check($img->key !== $key && $img->size() === [2, 1], 'another size, another slot');

// TextLayout: shaped text for a control of one's own, in UTF-16 offsets on whole graphemes.
$tl = null;
$h = new Headless(function (Context $c) use (&$tl): void { $tl = $c->shapeText("a😀b\ncd", 0); }, 300, 100);
$h->frame();
[$tw, $th] = $tl->size();
check($tw > 0 && $th > 0, 'its size');
same([0, 1, 3, 4], [$tl->previousBoundary(1), $tl->nextBoundary(0), $tl->nextBoundary(1), $tl->nextBoundary(3)], 'the emoji one grapheme of two units');
same([0, 3], [$tl->previousBoundary(2), $tl->nextBoundary(2)], 'inside the emoji: the boundaries around it');
$c0 = $tl->caret(0); $c3 = $tl->caret(3);
check($c3[0] > $c0[0] && $c3[2] === 1.0, 'carets along the line');
check($tl->indexAt($c3[0], $c3[1] + 1) === 3, 'the index under a caret');
$rects = $tl->selectionRects(new \MyPHP\UI\TextInputRange(0, 7));
same(2, count($rects), 'a selection over two lines');

// TextBuffer: runes, UTF-16 offsets, lines, snapshots that keep their text.
$tb = new \MyPHP\UI\TextBuffer("héllo\n😀 world\n");
same([14, 14 + 1, 3], [$tb->len(), $tb->utf16Len(), $tb->lineCount()], 'runes, units, lines');
$snap = $tb->snapshot();
$tb->replace(0, 5, 'hi');
same(["hi\n😀 world\n", "héllo\n😀 world\n", 1], [$tb->string(), $snap->string(), $tb->version()], 'the snapshot keeps its text');
same([3, 3, 5], [$tb->utf16Offset(3), $tb->runeOffset(4), $tb->utf16Offset(4)], 'UTF-16 around the emoji: a unit inside the pair its rune\'s');
same('😀 world', $tb->snapshot()->line(1), 'a line');
$r = $tb->replaceUTF16(new \MyPHP\UI\TextInputRange(4, 6), 'X');
same([3, 6, "hi\nXworld\n"], [$r->start, $r->end, $tb->string()], 'replaced from the whole rune');
$out = fopen('php://memory', 'w+'); $tb->writeTo($out); rewind($out);
same("hi\nXworld\n", stream_get_contents($out), 'written out');
