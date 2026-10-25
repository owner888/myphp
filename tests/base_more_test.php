<?php
// More of mygo's base_test.go (TestToggleBases, TestSliderBaseMapsItsContentBox,
// TestTabsBase, TestSelectBase, TestDialogAndPopoverBases, TestDismissed,
// TestPopupsOpenWhereTheyFit, TestEnterWithWindowShortcut): the changes
// the bases report, what assistive technology sees, the content box of a
// slider, a select's highlight, where dialogs and popovers show, what
// dismissed() reports, and Enter between the focus and the window.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, Node, Role};

/** The node of the access tree with $role named $label. */
function baseNode(Headless $h, int $role, string $label): AccessNode
{
    foreach ($h->rt->accessTree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label");
}

// Toggle bases: clicks, their changes, Space, what assistive technology reads.
$check = false; $on = false; $radio = 'a'; $changed = [];
$h = new Headless(function (Context $c) use (&$check, &$on, &$radio, &$changed): void {
    $c->column()->align(Node::START);
      $b = $c->checkboxBase($check)->size(20, 20)->label('check'); $c->end();
      if ($c->changed($b)) { $changed[] = 'check'; }
      $c->switchBase($on)->size(20, 20)->label('switch'); $c->end();
      $c->radioBase($radio, 'a')->size(20, 20)->label('a'); $c->end();
      $b = $c->radioBase($radio, 'b')->size(20, 20)->label('b'); $c->end();
      if ($c->changed($b)) { $changed[] = 'b'; }
    $c->end();
}, 200, 200);
$h->frame();
$h->clickLabel('check'); $h->clickLabel('switch'); $h->clickLabel('b');
check($check && $on && $radio === 'b', 'the clicks');
same(['check', 'b'], $changed, 'changed() reports them');
$h->rt->focus = null; $h->frame();
$h->tab();
same('check', $h->focused()?->label, 'Tab focuses the check box');
$h->space();
same(false, $check, 'Space toggles it');
$h->input->push(['accessOn']); $h->frame();
foreach (['check' => Role::CHECK_BOX, 'switch' => Role::SWITCH, 'b' => Role::RADIO] as $label => $role) {
    same($label !== 'check', (baseNode($h, $role, $label)->states & AccessNode::CHECKED) !== 0, "$label checked to assistive technology");
}

// A slider base maps the pointer across its content box, inside its padding; Home, Right, End.
$value = 0.0;
$h = new Headless(function (Context $c) use (&$value): void {
    $c->column(10, 0)->align(Node::START);
      $c->sliderBase($value, 0, 100)->size(120, 20)->paddingX(10)->label('volume');
    $c->end();
}, 200, 100);
$h->frame();
$r = $h->rect($h->byLabel('volume'));
foreach ([[10, 0], [60, 50], [110, 100], [2, 0], [118, 100]] as [$dx, $want]) {
    $h->press($r[0] + $dx, $r[1] + 10); $h->release($r[0] + $dx, $r[1] + 10);
    same((float)$want, $value, "pressing at $dx");
}
$h->home(); same(0.0, $value, 'Home');
$h->right(); same(1.0, $value, 'Right');
$h->end(); same(100.0, $value, 'End');

// A tabs base: a click and its change, Right from the last round to the first, End.
$tab = 0; $changes = 0;
$h = new Headless(function (Context $c) use (&$tab, &$changes): void {
    $p = $c->tabsBase($tab, 3);
      foreach (['One', 'Two', 'Three'] as $i => $name) { $p->tab($i)->padding(4); $c->text($name); $c->end(); }
    $c->end();
    if ($c->changed($p->list)) { $changes++; }
}, 300, 100);
$h->frame();
$h->clickText('Three');
check($tab === 2 && $changes === 1, "a click chooses tab $tab, $changes changes");
$h->right();
check($tab === 0 && in_array('One', $h->texts($h->focused()), true), 'Right from the last tab: the first');
$h->end();
check($tab === 2 && in_array('Three', $h->texts($h->focused()), true), 'End: the last');

// A select base: the choice highlighted as it opens, the arrows and Enter, Down and Escape, the pointer highlighting, Down under it, a click.
$size = 'M'; $highlighted = [];
$h = new Headless(function (Context $c) use (&$size, &$highlighted): void {
    $sel = $c->selectBase($size);
    $sel->trigger->label('size')->padding(4); $c->text("Size: $size"); $c->end();
    $highlighted = [];
    $sel->popup(function (Node $panel) use ($c, $sel, &$highlighted): void {
        $panel->label('popup');
        foreach (['S', 'M', 'L', 'XL'] as $s) {
            $item = $sel->item($s)->padding(4)->label("item $s");
            if ($item->highlighted) { $highlighted[$s] = true; }
            $c->text($s); $c->end();
        }
    });
}, 300, 300);
$h->frame();
check($h->byText('XL') === null, 'no popup before a click');
$h->clickLabel('size');
check($h->byText('XL') !== null && array_keys($highlighted) === ['M'], 'open, the choice highlighted');
$h->down(); $h->down();
same(['XL'], array_keys($highlighted), 'Down twice');
$h->up(); $h->enter();
check($size === 'L' && $h->byText('XL') === null, 'Enter chooses, and closes');
$h->down();
check($h->byText('XL') !== null, 'Down opens it from the focused trigger');
$h->escape();
check($h->byText('XL') === null, 'Escape closes it');
$h->clickLabel('size');
$h->hover(...$h->middle($h->byLabel('item S')));
same(['S'], array_keys($highlighted), 'the pointer highlights what it moves onto');
$h->down();
check(isset($highlighted['M']), 'Down under the resting pointer still moves');
$h->clickLabel('item XL');
check($size === 'XL' && $h->byLabel('item S') === null, 'a click chooses');

// Dialog and popover bases: the dialog centered, a click on the backdrop closing it; the popover under its anchor, Escape closing it.
$dialog = true; $popover = false; $back = null;
$h = new Headless(function (Context $c) use (&$dialog, &$popover, &$back): void {
    $b = $c->buttonBase()->size(40, 20)->label('anchor'); $c->end();
    if ($c->clicked($b)) { $popover = true; }
    $c->popoverBase($b, $popover, function (Node $panel): void { $panel->label('popover')->size(50, 50); });
    $c->dialogBase($dialog, function (Node $backdrop, Node $panel) use (&$back): void { $back = $backdrop; $backdrop->background(0x000000, 0.5); $panel->label('dialog')->size(100, 60); });
}, 400, 300);
$h->frame();
check($back !== null, 'the dialog hands its backdrop');
$r = $h->rect($h->byLabel('dialog'));
check($r[0] == 150 && $r[1] == 120, 'the dialog centered: ' . json_encode($r));
$h->click(10, 290);
check(!$dialog, 'a click on the backdrop closes it');
$h->clickLabel('anchor');
same(20.0, (float)$h->rect($h->byLabel('popover'))[1], 'the popover under its anchor');
$h->escape();
check(!$popover, 'Escape closes it');

// dismissed(): Escape and a click outside, which setting it open again undoes; not its own Close; a popover's Escape.
$dialog = true; $popover = false; $dismissed = 0; $popoverDismissed = false;
$h = new Headless(function (Context $c) use (&$dialog, &$popover, &$dismissed, &$popoverDismissed): void {
    $b = $c->button('anchor');
    if ($c->clicked($b)) { $popover = true; }
    $p = $c->popover($b, $popover, fn() => $c->text('popover'));
    if ($p !== null && $c->dismissed($p)) { $popoverDismissed = true; }
    $m = $c->modal($dialog, function () use ($c, &$dialog): void { if ($c->clicked($c->button('Close'))) { $dialog = false; } });
    if ($m !== null && $c->dismissed($m)) { $dismissed++; $dialog = true; }
}, 400, 300);
$h->frame();
$h->escape(); $h->frame();
check($dialog && $dismissed === 1, "Escape: open, dismissed $dismissed");
$h->click(5, 295); $h->frame();
check($dialog && $dismissed === 2, "a click outside: open, dismissed $dismissed");
$h->clickText('Close'); $h->frame();
check(!$dialog && $dismissed === 2, 'Close: closed, not dismissed');
$h->clickText('anchor'); $h->frame();
check($popover, 'the popover opens');
$h->escape();
check(!$popover && $popoverDismissed, 'Escape dismisses it');

// Popovers open where they fit, in the first frame that paints them: above an anchor at the bottom right, below one at the top left.
foreach (['bottom right' => Node::END, 'top left' => Node::START] as $name => $at) {
    $open = false; $first = null;
    $h = new Headless(function (Context $c) use (&$open, &$first, $at): void {
        $c->column(10, 0, 1)->justify($at)->align($at);
          $b = $c->buttonBase()->size(80, 24)->label('anchor'); $c->end();
          if ($c->clicked($b)) { $open = true; }
          $c->popoverBase($b, $open, function (Node $panel) use (&$first): void {
              $panel->size(160, 120)->margin(4, 0, 0, 0)->draw(function ($list, Node $p) use (&$first): void { $first ??= [$p->x, $p->y, $p->w, $p->h]; });
          });
        $c->end();
    }, 300, 300);
    $h->paints = true;
    $h->frame();
    $h->clickLabel('anchor');
    $a = $h->rect($h->byLabel('anchor'));
    check($first !== null && $first[0] >= 4 && $first[0] + $first[2] <= 296, "$name: in the window: " . json_encode($first));
    if ($at === Node::END) { check($first !== null && abs($first[1] + $first[3] - ($a[1] - 4)) < 0.01, "$name: 4 above its anchor, first painted: " . json_encode([$first, $a])); }
    else { check($first !== null && abs($first[1] - ($a[1] + $a[3] + 4)) < 0.01 && $first[0] == $a[0], "$name: 4 below its anchor, first painted"); }
}

// Enter: the window's without a focus; a focused button's; a focused check box leaves it to the window, and takes Space.
$window = 0; $button = 0; $checked = false;
$h = new Headless(function (Context $c) use (&$window, &$button, &$checked): void {
    if ($c->shortcut(Key::RETURN)) { $window++; }
    $c->column();
      if ($c->clicked($c->button('Other'))) { $button++; }
      $c->checkbox('Agree', $checked);
    $c->end();
}, 300, 200);
$h->frame();
$h->enter();
check($window === 1 && $button === 0, "Enter without a focus: window $window, button $button");
$h->tab(); $h->enter();
check($window === 1 && $button === 1, "a focused button takes Enter: window $window, button $button");
$h->tab(); $h->enter();
check($window === 2 && !$checked, "a focused check box leaves Enter to the window: window $window");
$h->space();
check($window === 2 && $checked, 'and takes Space');
