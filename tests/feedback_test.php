<?php
// Feedback (mygo's feedback_test.go): a toast with an action, a check box
// group, breadcrumbs, alert dialogs and their Escape, one that must be
// answered, a find bar; what assistive technology reads of each.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, Node, Role, TextState};

/** The node of the access tree with $role named $label, or null. */
function fbNode(Headless $h, int $role, string $label): ?AccessNode
{
    foreach ($h->rt->accessTree?->nodes ?? [] as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
}

// A toast with an action: read as a status, its button reached; its time stops under the pointer; Undo.
$notes = 3; $undone = 0;
$h = new Headless(function (Context $c) use (&$notes, &$undone): void {
    $c->column(20, 0, 1);
      $c->text("$notes notes");
      if ($c->clicked($c->button('Delete'))) {
          $notes--;
          $c->toastAction('Note deleted', 'Undo', function () use (&$notes, &$undone): void { $notes++; $undone++; });
      }
    $c->end();
    $c->toasts();
}, 400, 300);
$h->frame();
$h->clickText('Delete');
check($notes === 2 && $h->byText('Note deleted') !== null && $h->byText('Undo') !== null, 'the toast after Delete');
$h->input->push(['accessOn']); $h->frame();
check(fbNode($h, Role::STATUS, 'Note deleted') !== null || array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::STATUS && str_contains($n->label, 'Note deleted')) !== [], 'read as a status');
check(fbNode($h, Role::BUTTON, 'Undo') !== null, 'its button reached');
$h->hover(...$h->middle($h->byText('Note deleted')));
check($h->rt->toastsPaused(), 'the pointer on it stops its time');
$h->clickText('Undo');
check($notes === 3 && $undone === 1 && $h->byText('Note deleted') === null && $h->byText('3 notes') !== null, 'Undo');

// A check box group: a click checks all; one of two is mixed; from mixed all; Space with all checked none; read as a group.
$mail = false; $calendar = false; $changes = 0;
$h = new Headless(function (Context $c) use (&$mail, &$calendar, &$changes): void {
    $g = $c->checkboxGroup('Notifications', function () use ($c, &$mail, &$calendar): void {
        $c->checkbox('Mail', $mail);
        $c->checkbox('Calendar', $calendar);
    });
    if ($c->changed($g)) { $changes++; }
}, 300, 200);
$h->frame();
$h->clickText('Notifications');
check($mail && $calendar && $changes === 1, "a click on the group checks both ($changes changes)");
$h->clickText('Mail');
$h->input->push(['accessOn']); $h->frame();
check((fbNode($h, Role::CHECK_BOX, 'Notifications')?->states & AccessNode::MIXED) !== 0, 'one of two: mixed');
$h->clickText('Notifications');
check($mail && $calendar, 'from mixed, all');
$h->space();
check(!$mail && !$calendar, 'Space with all checked: none');
check(fbNode($h, Role::GROUP, 'Notifications') !== null, 'a group');

// Breadcrumbs: a click on an item chooses it, the last is not a link.
$path = ['Macintosh HD', 'Users', 'ada', 'Documents']; $chosen = -1;
$h = new Headless(function (Context $c) use ($path, &$chosen): void { $c->breadcrumbs($path, $chosen)->label('Path'); }, 500, 100);
$h->frame();
$h->clickText('Users');
same(1, $chosen, 'a click on Users');
$h->clickText('Documents');
same(1, $chosen, 'a click on the last does nothing');
$h->input->push(['accessOn']); $h->frame();
check(fbNode($h, Role::GROUP, 'Path') !== null && fbNode($h, Role::LINK, 'ada') !== null && fbNode($h, Role::LINK, 'Documents') === null, 'a group of links, the last not one');

// An alert: its role and description, the focus on the default button; a click outside nothing; Escape no button; Cancel 0; Enter the default.
$open = true; $deleted = 0; $canceled = 0;
$h = new Headless(function (Context $c) use (&$open, &$deleted, &$canceled): void {
    $c->button('Behind');
    switch ($c->alertDialog($open, 'Delete Notes?', "You can't undo this.", 'Cancel', 'Delete')) {
        case 0: $canceled++; break;
        case 1: $deleted++; break;
    }
}, 500, 400);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
same("You can't undo this.", fbNode($h, Role::ALERT_DIALOG, 'Delete Notes?')?->description, 'the alert, described');
check(in_array('Delete', $h->texts($h->focused()), true), 'the default button has the focus');
same(fbNode($h, Role::BUTTON, 'Delete')?->id, $h->rt->accessTree->focus, 'assistive technology too');
$h->click(5, 5);
check($open, 'a click outside does nothing');
$h->escape();
check(!$open && $canceled === 0 && $deleted === 0, 'Escape closes it, no button chosen');
$open = true; $h->frame();
$h->clickText('Cancel');
check(!$open && $canceled === 1, 'Cancel');
$open = true; $h->frame();
$h->enter();
check(!$open && $deleted === 1, 'Enter chooses the default');

// Escape closes an alert whatever its buttons: a localized one, one without Cancel, one with a single button.
foreach ([['取消', '删除'], ['Not Now', 'Delete'], ['OK']] as $buttons) {
    $open = true; $got = [];
    $h = new Headless(function (Context $c) use (&$open, &$got, $buttons): void {
        if (($i = $c->alertDialog($open, 'Delete Notes?', '', ...$buttons)) !== -1) { $got[] = $i; }
    }, 500, 400);
    $h->frame();
    $h->escape();
    check(!$open && $got === [Context::ALERT_DISMISSED], json_encode($buttons, JSON_UNESCAPED_UNICODE) . ': Escape dismisses it');
}

// An alert that must be answered stays open on Escape, the focus on its button; Enter chooses it.
$open = true; $chosen = -1;
$h = new Headless(function (Context $c) use (&$open, &$chosen): void {
    $i = $c->alertDialog($open, 'Update required', '', 'Restart');
    if ($i === Context::ALERT_DISMISSED) { $open = true; } elseif ($i >= 0) { $chosen = $i; }
}, 500, 400);
$h->frame();
$h->escape(); $h->frame();
check($open && in_array('Restart', $h->texts($h->focused()), true), 'Escape: still open, the focus on Restart');
$h->enter(); $h->frame();
check(!$open && $chosen === 0, 'Enter chooses it');

// A find bar: Cmd+F opens it on its field; typing counts; Enter, Shift+Enter, Cmd+G, Next; Escape; opened again, typing over the last query; no matches.
$open = false; $query = new TextState(''); $current = 0; $moves = 0;
$text = 'the cat sat on the mat with the hat';
$count = function () use ($query, $text): int { return $query->text === '' ? 0 : substr_count($text, $query->text); };
$h = new Headless(function (Context $c) use (&$open, $query, &$current, &$moves, $text, $count): void {
    $c->column(0, 0, 1);
      if ($c->shortcut('f', Key::COMMAND)) { $open = true; }
      $bar = $c->findBar($open, $query, $count(), $current);
      if ($bar !== null && $c->changed($bar)) { $moves++; }
      $c->text($text)->padding(10);
    $c->end();
}, 600, 300);
$h->frame();
$h->shortcut('f', Key::COMMAND);
check($open && $h->focused()?->kind === Node::INPUT, 'Cmd+F opens it, on its field');
$h->type('the');
check($count() === 3 && $h->byText('1 of 3') !== null, 'typed: 1 of 3');
$h->enter(); $h->enter();
check($current === 2 && $h->byText('3 of 3') !== null, 'Enter twice: 3 of 3');
$h->enter();
same(0, $current, 'round the end');
$h->enter(Key::SHIFT);
check($current === 2 && $moves === 4, "Shift+Enter: back ($moves moves)");
$h->shortcut('g', Key::COMMAND);
same(0, $current, 'Cmd+G, the next');
$h->clickLabel('Next');
same(1, $current, 'Next');
$field = function (Node $n) use (&$field): ?Node { if ($n->kind === Node::INPUT) { return $n; } foreach ($n->kids as $k) { if (($f = $field($k)) !== null) { return $f; } } return null; };
$h->clickOn($field($h->root));
$h->escape();
check(!$open, 'Escape closes it');
$h->shortcut('f', Key::COMMAND);
$h->type('x');
same('x', $query->text, 'opened again, typing goes over the last query');
$h->type('yz');
$h->input->push(['accessOn']); $h->frame();
check($h->byText('No matches') !== null && fbNode($h, Role::STATUS, 'No matches') !== null, 'no matches, read as a status');

// An alert or a modal built inside a part of the window, beside a sidebar, centered in the window over all of it, as mygo's are in an overlay (the gallery's showed toward its corner).
foreach (['alert', 'modal'] as $what) {
    $open = true; $panel = null;
    $h = new Headless(function (\MyPHP\UI\Context $c) use (&$open, &$panel, $what) {
        $c->row(0, 0, 1)->fill();
          $c->column(0, 0)->size(200, 0); $c->end();
          $c->column(80, 0, 1);
            if ($what === 'alert') { $c->alertDialog($open, 'Delete?', 'For good.', 'Cancel', 'Delete'); }
            else { $c->modal($open, function () use ($c) { $c->text('Inside'); }); }
          $c->end();
        $c->end();
        $panel = null;
        $find = function (\MyPHP\UI\Node $n) use (&$find, &$panel) { if (in_array($n->role, [\MyPHP\UI\Role::ALERT_DIALOG, \MyPHP\UI\Role::DIALOG], true)) { $panel = $n; } foreach ($n->kids as $k) { $find($k); } };
        $find($c->root);
    }, 1000, 700);
    $h->frame();
    same([500.0, 350.0], [round($panel->x + $panel->w / 2), round($panel->y + $panel->h / 2)], "$what: centered in the window");
}
