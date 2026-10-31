<?php
// Toasts (mygo's toast_test.go TestToastViewportBase, TestToastManager,
// TestToastAfterDialog, TestToastDescription, TestToastExits): a look of the app's own,
// the newest three, a toast changed in place by its id, its time that
// stops under the pointer and with the window in the background, Escape
// in it, and what screen readers hear.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, Runtime, Toast};

// A look of the app's own: the action runs and closes it, its time over it closes, Close and Escape close it.
$now = 0; $shown = []; $lefts = []; $closed = []; $undone = [];
$h = poseTester(function (Context $c) use (&$shown, &$lefts, &$closed, &$undone): void {
    $shown = []; $lefts = [];
    if ($c->clicked($c->button('Delete'))) {
        $c->addToast(new Toast('deleted', 'Note deleted', type: 'info', action: 'Undo',
            onAction: function () use (&$undone): void { $undone[] = 'deleted'; }, onClose: function () use (&$closed): void { $closed[] = 'deleted'; }));
    }
    $c->toastViewportBase(function (Node $viewport, array $toasts) use ($c, &$shown, &$lefts): void {
        $shown = array_merge($shown, $toasts);
        $viewport->padding(16)->alignItems(Node::END);
        $viewport->gap = 8;
        foreach ($toasts as $t) {
            $toast = $c->toastBase($t);
            $lefts[] = $toast->left();
            $toast->root->dir = Node::ROW; $toast->root->gap = 8;
              $c->text($t->title);
              if ($t->action !== '') { $toast->actionButton(); $c->text($t->action); $c->end(); }
              $toast->closeButton()->label('Close'); $c->text('×'); $c->end();
            $c->end();
        }
    });
    $c->toasts(); // as the window builds them after the view: the app's look took them
}, $now, 400, 300);
$tick = function (float $s) use ($h, &$now): void { $now += (int)round($s * 1e9); $h->frame(); };
$h->clickText('Delete');
check(count($shown) === 1 && $shown[0]->type === 'info' && $h->byText('Note deleted') !== null, 'the toast shows');
same(Runtime::TOAST_FOR, $lefts[0], 'a toast without a timeout shows for the time of toasts');
same(1, count(array_filter($h->texts(), fn(string $s) => $s === 'Note deleted')), 'once: the theme\'s look does not show it too');
$r = $h->rect($h->byText('Note deleted'));
check($r[0] + $r[2] >= 300 && $r[1] >= 250, 'at the bottom right of the window: ' . json_encode($r));
$tick(1);
check(abs($lefts[0] - (Runtime::TOAST_FOR - 1)) < 1e-6, 'a second later, a second less left');
$h->clickText('Undo');
check($undone === ['deleted'] && $closed === ['deleted'] && $h->byText('Note deleted') === null, 'its action runs, and closes it');
$h->clickText('Delete');
$tick(Runtime::TOAST_FOR);
check(count($closed) === 2 && $h->byText('Note deleted') === null, 'its time over, it closes');
$h->clickText('Delete');
$h->clickOn($h->byLabel('Close'));
check(count($closed) === 3 && $h->byText('Note deleted') === null, 'Close closes it');
$h->clickText('Delete');
for ($k = 0; $k < 4; $k++) { $f = $h->focused(); if ($f !== null && in_array('Undo', $h->texts($f), true)) { break; } $h->tab(); }
check(($f = $h->focused()) !== null && in_array('Undo', $h->texts($f), true), 'Tab reaches the toast');
$h->escape();
check(count($closed) === 4 && $h->byText('Note deleted') === null, 'Escape with the focus in it closes it');

// The newest three, oldest first; an id changes a toast in place; closing one, then all; one without end; the time stops under the pointer and in the background.
$now = 0; $shown = []; $lefts = []; $ctx = null;
$h = poseTester(function (Context $c) use (&$shown, &$lefts, &$ctx): void {
    $ctx = $c; $shown = []; $lefts = [];
    $c->toastViewportBase(function (Node $viewport, array $toasts) use ($c, &$shown, &$lefts): void {
        foreach ($toasts as $t) {
            $toast = $c->toastBase($t);
            $shown[] = $t->title; $lefts[] = $toast->left();
              $c->text($t->title);
            $c->end();
        }
    });
}, $now, 400, 300);
$tick = function (float $s) use ($h, &$now): void { $now += (int)round($s * 1e9); $h->frame(); };
$ids = [];
foreach (['A', 'B', 'C', 'D'] as $t) { $ids[$t] = $ctx->addToast(new Toast(title: $t)); }
$h->frame();
same(['B', 'C', 'D'], $shown, 'the newest three, oldest first');
check($ids['A'] !== '' && $ids['A'] !== $ids['B'], 'ids made up');
$tick(1);
$ctx->addToast(new Toast($ids['C'], 'C2'));
$h->frame();
check($shown === ['B', 'C2', 'D'] && $lefts[1] == Runtime::TOAST_FOR, 'C changed where it is, its time anew');
$ctx->closeToast($ids['D']); $h->frame();
same(['A', 'B', 'C2'], $shown, 'D closed: A shows again');
$ctx->closeToast(); $h->frame();
same([], $shown, 'all closed');
$ctx->addToast(new Toast(title: 'Uploading', timeout: -1));
$tick(3600);
check($shown === ['Uploading'] && $lefts[0] == 0.0, 'a toast without end stays, nothing left to count');
$ctx->closeToast();
$ctx->addToast(new Toast(title: 'Saved')); $h->frame();
$r = $h->rect($h->byText('Saved'));
$h->hover($r[0] + 1, $r[1] + 1);
$tick(60);
same(['Saved'], $shown, 'the pointer on the toast stops its time');
$h->hover(399, 1);
$tick(1);
$h->rt->setWindowFocus(false);
$tick(60);
check($shown === ['Saved'] && abs($lefts[0] - (Runtime::TOAST_FOR - 1)) < 1e-6, 'the window in the background stops it too: ' . json_encode([$shown, $lefts]));
$h->rt->setWindowFocus(true);
$tick(Runtime::TOAST_FOR);
same([], $shown, 'its time over in the foreground, it goes');

// A toast shown as a dialog the pointer clicked closes, at once and frames later: its time runs out, the pointer resting where the dialog was.
foreach ([false, true] as $later) {
    $now = 0; $open = true; $toast = false;
    $h = poseTester(function (Context $c) use (&$open, &$toast, $later): void {
        if ($c->alertDialog($open, 'Remove “python”?', '', 'Cancel', 'Remove') === 1) { $toast = !$later; }
        if ($toast) { $c->toast('Removed python'); $toast = false; }
        $c->toasts();
    }, $now, 400, 300);
    $tick = function (float $s) use ($h, &$now): void { $now += (int)round($s * 1e9); $h->frame(); };
    $h->clickText('Remove');
    $tick(0.1);
    if ($later) { $tick(1); $toast = true; $tick(0.1); }
    $n = $h->byText('Removed python');
    check($n !== null, ($later ? 'later' : 'at once') . ': the toast');
    $tick(Runtime::TOAST_FOR); $tick(1);
    check($h->byText('Removed python') === null, ($later ? 'later' : 'at once') . ': it goes after its time, the pointer on none of it');
}

// A description shows under the title, and is read out after it.
$ctx = null;
$h = new Headless(function (Context $c) use (&$ctx): void { $ctx = $c; $c->toasts(); }, 400, 300);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$ctx->addToast(new Toast(title: 'Upload failed', description: 'The server is not reachable.'));
$h->frame();
check($h->byText('Upload failed') !== null && $h->byText('The server is not reachable.') !== null, 'the title and the description');
same(['Upload failed', 'The server is not reachable.'], $h->told, 'both read out');

// The last toast goes with its exit transition (mygo's TestToastExits): its copy stays in the viewport until the transition is over.
$now = 0; $ctx = null; $viewport = null;
$h = poseTester(function (Context $c) use (&$ctx, &$viewport): void {
    $ctx = $c;
    $viewport = $c->toastViewportBase(function (Node $viewport, array $toasts) use ($c): void {
        foreach ($toasts as $t) {
            $toast = $c->toastBase($t);
            $toast->root->size(100, 40)->background(0xff0000);
            $c->transition($toast->root, new \MyPHP\UI\ElementTransition(0.2, \MyPHP\UI\Easing::linear(...), exit: new \MyPHP\UI\TransitionMotion(y: 40)));
            $c->end();
        }
    });
}, $now, 200, 100);
$tick = function (float $s) use ($h, &$now): void { $now += (int)round($s * 1e9); $h->frame(); };
$id = $ctx->addToast(new Toast(title: 'Saved'));
$tick(0.016);
check($h->byText('Saved') === null && count($viewport->kids) === 1, 'the toast shows');
$ctx->closeToast($id);
$tick(0.016);
same(1, count($viewport->ghosts), 'closed, it goes with its exit transition');
$tick(0.3);
same(0, count($viewport->ghosts), 'and is gone after it');
