<?php
// Widgets without a look (mygo's base.go, collapsible.go, combobox.go,
// tooltip.go, toggle.go): what each does with the pointer and the keys,
// and what assistive technology sees, the look left to the app.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node, Role, TextBuffer, TextState};

/** The node built with $id in the last frame of $h. */
function bNode(Headless $h, ?Node $n): Node
{
    return $h->find($n->id) ?? throw new \RuntimeException('not built');
}

// Check box, switch, toggle and radio: a click turns them over, Space too.
$on = false; $sw = false; $tg = false; $size = 'm'; $nodes = [];
$h = new Headless(function (Context $c) use (&$on, &$sw, &$tg, &$size, &$nodes): void {
    $c->column(0, 8);
      $nodes['cb'] = $c->checkboxBase($on)->size(40, 20); $c->end();
      $nodes['sw'] = $c->switchBase($sw)->size(40, 20); $c->end();
      $nodes['tg'] = $c->toggleBase($tg)->size(40, 20); $c->end();
      foreach (['s', 'm', 'l'] as $v) { $nodes["r$v"] = $c->radioBase($size, $v)->size(40, 20); $c->end(); }
    $c->end();
}, 300, 300);
$h->frame();
$h->clickOn($nodes['cb']); $h->clickOn($nodes['sw']); $h->clickOn($nodes['tg']); $h->clickOn($nodes['rl']); $h->frame();
same([true, true, true, 'l'], [$on, $sw, $tg, $size], 'each takes its click');
same([Role::CHECK_BOX, Role::SWITCH, Role::TOGGLE_BUTTON, Role::RADIO], [$nodes['cb']->role, $nodes['sw']->role, $nodes['tg']->role, $nodes['rl']->role], 'and its role');
$h->rt->focus = $nodes['cb']->id; $h->type(' '); $h->frame();
same(false, $on, 'Space turns the check box over');

// Tabs: a click chooses one, the arrows the next, the focus following.
$tab = 0; $tabs = null; $list = null;
$h = new Headless(function (Context $c) use (&$tab, &$tabs, &$list): void {
    $p = $c->tabsBase($tab, 3);
      foreach ([0, 1, 2] as $i) { $tabs[$i] = $p->tab($i)->size(40, 20); $c->end(); }
    $c->end();
    $list = $p->list;
}, 300, 100);
$h->frame();
$h->clickOn($tabs[2]); $h->frame();
same(2, $tab, 'a click chooses a tab');
$h->rt->focus = $tabs[2]->id; $h->left(); $h->frame();
same(1, $tab, 'Left the one before');
same(Role::TAB_LIST, $list->role, 'a tab list');

// Segmented: its segments radio buttons, the track telling of a change.
$seg = 0; $parts = []; $changed = 0;
$h = new Headless(function (Context $c) use (&$seg, &$parts, &$changed): void {
    $p = $c->segmentedBase($seg, 2);
      $parts[0] = $p->segment(0)->size(40, 20); $c->end();
      $parts[1] = $p->segment(1)->size(40, 20); $c->end();
    $c->end();
    if ($c->changed($p->track)) { $changed++; }
}, 300, 100);
$h->frame(); $h->clickOn($parts[1]); $h->frame();
same([1, 1], [$seg, $changed], 'a segment chosen, the track changed');

// Collapsible: the trigger opens it, the panel builds while open.
$open = false; $trigger = null; $panelBuilt = false;
$h = new Headless(function (Context $c) use (&$open, &$trigger, &$panelBuilt): void {
    $p = $c->collapsibleBase($open);
      $trigger = $p->trigger->size(80, 20); $c->text('More');
    $c->end();
    $panelBuilt = $p->panel(function () use ($c): void { $c->text('Inside'); }) !== null;
}, 300, 200);
$h->frame();
check(!$panelBuilt, 'closed: no panel');
$h->clickOn($trigger); $h->frame(4);
check($open && $panelBuilt && $trigger->expanded, 'open: the panel, the trigger expanded');

// Popover: Escape closes it, which dismissed() tells.
$open = true; $dismissed = false; $anchor = null;
$h = new Headless(function (Context $c) use (&$open, &$dismissed, &$anchor): void {
    $anchor = $c->buttonBase()->size(60, 20); $c->text('Open'); $c->end();
    $panel = $c->popoverBase($anchor, $open, function (Node $panel) use ($c): void { $panel->padding(8); $c->text('Hello'); });
    if ($panel !== null && $c->dismissed($panel)) { $dismissed = true; }
}, 300, 200);
$h->frame();
check(in_array('Hello', $h->texts(), true), 'the popover shows');
$h->escape(); $h->frame();
check(!$open && $dismissed, 'Escape dismisses it');

// Dialog: a click on the backdrop closes it; on the panel, not.
$open = true; $panel = null;
$h = new Headless(function (Context $c) use (&$open, &$panel): void {
    $panel = $c->dialogBase($open, function (Node $back, Node $p) use ($c): void { $p->size(100, 60); $c->text('Sure?'); });
}, 300, 200);
$h->frame();
$h->clickOn($panel); $h->frame();
check($open, 'a click on the panel keeps it');
$h->click(5, 5); $h->frame();
check(!$open, 'one on the backdrop closes it');

// Tooltip: once the pointer rests on the anchor, the tip the app builds.
$now = 0; $tipShown = false; $anchor = null;
$h = poseTester(function (Context $c) use (&$tipShown, &$anchor): void {
    $anchor = $c->buttonBase()->size(60, 20); $c->text('Share'); $c->end();
    $tipShown = $c->tooltipBase($anchor, function (Node $tip) use ($c): void { $c->text('Share with others'); }) !== null;
}, $now, 300, 200);
$h->frame();
$h->hover(...$h->middle($anchor));
$now += 1_000_000_000; $h->frame(); $h->frame();
check($tipShown, 'the tip shows after a rest');
check($h->rt->tip !== null && $h->rt->tip[0] === '', 'a tip of its own, which the painter leaves');

// Select: a click opens the popup, an item chooses; the keys too.
$sel = 'b'; $trigger = null; $items = [];
$h = new Headless(function (Context $c) use (&$sel, &$trigger, &$items): void {
    $s = $c->selectBase($sel);
      $trigger = $s->trigger->size(80, 20); $c->text($sel);
    $c->end();
    $items = [];
    $s->popup(function (Node $panel) use ($s, $c, &$items): void {
        foreach (['a', 'b', 'c'] as $v) { $items[$v] = $s->item($v)->size(80, 20); $c->text($v); $c->end(); }
    });
}, 300, 300);
$h->frame();
$h->clickOn($trigger); $h->frame();
check(isset($items['c']) && $items['b']->highlighted() && $items['b']->checked === 2, 'open, on the one chosen');
$h->clickOn($items['c']); $h->frame();
same(['c', []], [$sel, $items], 'a click chooses and closes');
$h->rt->focus = $trigger->id; $h->down(); $h->frame(); $h->up(); $h->frame(); $h->enter(); $h->frame();
same('b', $sel, 'Down opens, Up moves, Enter chooses');

// Combobox: typing opens the popup, on its first option; Enter chooses it.
$q = new TextState(''); $chosen = null; $input = null;
$fonts = ['Helvetica', 'Menlo', 'Monaco'];
$h = new Headless(function (Context $c) use ($q, &$chosen, &$input, $fonts): void {
    $cb = $c->comboboxBase($q);
    $input = $cb->input->size(120, 20);
    $cb->popup(function (Node $panel) use ($cb, $c, $q, $fonts): void {
        foreach (Context::matching($fonts, $cb->filtering() ? $q->text : '') as $f) { $cb->item($f)->size(120, 20); $c->text($f); $c->end(); }
    });
    if (($f = $cb->chosen()) !== null) { $chosen = $f; }
}, 300, 300);
$h->frame();
$h->clickOn($input); $h->type('mo'); $h->frame();
$h->enter(); $h->frame();
same(['Monaco', 'Monaco'], [$chosen, $q->text], 'the first match chosen');

// A text input without a look: no face, no padding; one editing a TextBuffer.
$buf = new TextBuffer('héllo'); $in = null;
$h = new Headless(function (Context $c) use ($buf, &$in): void { $in = $c->textInputBufferBase($buf)->size(200, 20); }, 300, 100);
$h->frame();
check($in->bare && $in->color === null && !$in->bordered, 'no look');
$h->clickOn($in); $h->key(Key::END, 'moveToEndOfLine:'); $h->type('!'); $h->frame();
same(['héllo!', 1], [$buf->string(), $buf->version()], 'typing edits the buffer');
$buf->replace(0, 1, 'J'); $h->frame();
same('Jéllo!', $h->find($in->id)->edit->text, 'a change of the program\'s shows');

// FocusBind: the field asks for a control's focus, waiting while it is not built; the user's focus sets the field.
$app = new class { public ?string $focus = null; public bool $more = false; };
$fields = [];
$h = new Headless(function (Context $c) use ($app, &$fields): void {
    $fields['name'] = $c->input(new TextState(), '', 100)->focusBind($app->focus, 'name');
    if ($app->more) { $fields['mail'] = $c->input(new TextState(), '', 100)->focusBind($app->focus, 'mail'); }
}, 300, 100);
$h->frame();
$app->focus = 'mail'; $h->frame();
same(null, $h->rt->focus, 'the control asked for is not built: the request waits');
$app->more = true; $h->frame();
same($fields['mail']->id, $h->rt->focus, 'built, it takes the focus');
$h->clickOn($fields['name']); $h->frame();
same('name', $app->focus, 'the user\'s focus sets the field');
same('name', (new Context($h->metrics, $h->rt))->focusedValue($app->focus), 'focusedValue reads the focus');
$app->focus = null; $h->frame();
same(null, $h->rt->focus, 'null takes the focus away');
