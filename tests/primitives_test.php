<?php
// What widgets of an app's own are made of (mygo's primitives_test.go
// TestAccessStatesOfYourOwn, TestReadOnlyInput, TestComposing,
// TestOpenURLOutcome, TestPopoverLightDismiss, TestAttachTo,
// TestModalOverlayOfYourOwn, TestSliderBaseSettings, TestSelectHighlight,
// TestDisabledStepper, TestSnapToTheStepsDecimals, TestDrawer,
// TestPopoverScrollsWithItsAnchor, TestEscapeClosesTheInnerPopover,
// TestAttachToInlineText, TestRangeSteps, TestOnTextKeyTakesEnterByModifier,
// TestOnTextKeySeesModifiersAndHolds, TestOnTextKeySeesKeysThatTypeNoText):
// the states and roles they give
// assistive technology, overlays and their placement, the settings of
// bases.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, AccessTree, Anchor, Context, Key, Node, Role, ScrollState, TextState};

/** The node of $tree with $role whose label is $label, or null. */
function pmAccess(AccessTree $tree, int $role, string $label): ?AccessNode
{
    foreach ($tree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
}

/** Turns assistive technology on in $h and returns the tree. */
function pmTree(Headless $h): AccessTree
{
    $h->input->push(['accessOn']); $h->frame();
    return $h->rt->accessTree;
}

// States and roles of nodes of the app's own.
$h = new Headless(function (Context $c): void {
    $c->column();
      $c->text('Settings')->role(Role::HEADING)->level(2);
      $c->box(20, 20, 0)->focusable()->role(Role::CHECK_BOX)->label('All')->mixed();
      $c->box(20, 20, 0)->focusable()->role(Role::POP_UP_BUTTON)->label('Date')->expanded(true)->value('May 4');
      $c->box(80, 20, 0)->focusable()->role(Role::SLIDER)->label('Rating')->range(0, 5, 3);
      $menu = $c->column()->focusable()->role(Role::MENU)->label('Edit')->autoFocus();
        $c->text('Cut')->role(Role::MENU_ITEM);
        $bold = $c->text('Bold')->role(Role::MENU_ITEM_CHECK_BOX)->checked(true);
        $c->text('Left')->role(Role::MENU_ITEM_RADIO)->checked(false);
        $menu->activeDescendant($bold);
      $c->end();
    $c->end();
}, 300, 300);
$h->frame();
$tree = pmTree($h);
same(2, pmAccess($tree, Role::HEADING, 'Settings')?->level, 'a heading of level 2');
check((pmAccess($tree, Role::CHECK_BOX, 'All')?->states & AccessNode::MIXED) !== 0, 'a check box mixed');
$date = pmAccess($tree, Role::POP_UP_BUTTON, 'Date');
check($date !== null && ($date->states & AccessNode::EXPANDED) !== 0 && $date->value === 'May 4', 'a pop-up button expanded, with its value');
$r = pmAccess($tree, Role::SLIDER, 'Rating');
same([0.0, 5.0, 3.0], [$r?->min, $r?->max, $r?->now], 'a slider\'s range');
$menu = pmAccess($tree, Role::MENU, 'Edit');
$bold = pmAccess($tree, Role::MENU_ITEM_CHECK_BOX, 'Bold');
$left = pmAccess($tree, Role::MENU_ITEM_RADIO, 'Left');
check(pmAccess($tree, Role::MENU_ITEM, 'Cut') !== null && ($bold->states & AccessNode::CHECKED) !== 0 && ($left->states & AccessNode::CHECKED) === 0, 'menu items, checked or not');
check($bold->parent >= 0 && $tree->nodes[$bold->parent]->id === $menu->id, 'the items are inside the menu');
same($bold->id, $tree->focus, 'the focus on the active descendant');

// A read-only input takes no typing, no deletes, no value from assistive technology; it copies; editable again once no longer said.
$text = new TextState('hello'); $readOnly = true;
$h = new Headless(function (Context $c) use ($text, &$readOnly): void {
    $text->readOnly = $readOnly;
    $c->textInputBase($text)->width(100)->label('name');
}, 200, 50);
$h->frame();
$h->clickOn($h->byLabel('name'));
$h->key(Key::END, 'moveToEndOfLine:');
$h->type('x');
$h->key(Key::DELETE, 'deleteBackward:');
same('hello', $text->text, 'a read-only input stays as it was');
$tree = pmTree($h);
$field = pmAccess($tree, Role::TEXT_FIELD, 'name');
check($field !== null && ($field->states & AccessNode::READ_ONLY) !== 0 && ($field->actions & AccessNode::SET_VALUE) === 0, 'assistive technology sees it read-only');
$h->input->push(['access', $field->id, AccessNode::SET_VALUE, 'bye']); $h->frame();
same('hello', $text->text, 'and cannot set it');
$readOnly = false; $h->frame();
$h->key(Key::END, 'moveToEndOfLine:');
$h->type('!');
same('hello!', $text->text, 'editable again');

// Composing: the text waits for the commit, which submits nothing.
$text = new TextState(''); $composing = false; $submitted = 0;
$h = new Headless(function (Context $c) use ($text, &$composing, &$submitted): void {
    $n = $c->textInputBase($text)->width(100)->label('name');
    $composing = $text->marked !== null;
    if ($c->submitted($n)) { $submitted++; }
}, 200, 50);
$h->frame();
$h->clickOn($h->byLabel('name'));
$text->setMarked('ni', 2); $h->frame();
check($composing && $text->text === '', 'composing, nothing in the text');
$h->type('你');
check(!$composing && $text->text === '你' && $submitted === 0, 'committed, not submitted');

// openURLThen tells what came of it; openURL alone tells nothing.
$outcome = null; $calls = 0; $opened = []; $fail = false;
$h = new Headless(function (Context $c) use (&$outcome, &$calls): void {
    if ($c->clicked($c->buttonBase()->size(40, 20)->label('go'))) { $c->openURLThen('x-unknown://a', function (?\Throwable $e) use (&$outcome, &$calls): void { $outcome = $e; $calls++; }); }
    $c->end();
    if ($c->clicked($c->buttonBase()->size(40, 20)->label('plain'))) { $c->openURL('https://example.com'); }
    $c->end();
    if ($outcome !== null) { $c->text("Couldn't open the link"); }
}, 200, 100);
$h->rt->openURL = function (string $u) use (&$opened, &$fail): void { $opened[] = $u; if ($fail) { throw new \RuntimeException('no app'); } };
$h->frame();
$h->clickOn($h->byLabel('go'));
check($calls === 1 && $outcome === null, 'opened: told, without an error');
$fail = true;
$h->clickOn($h->byLabel('go'));
check($calls === 2 && $outcome !== null && $h->byText("Couldn't open the link") !== null, 'failing: told the error');
$h->clickOn($h->byLabel('plain'));
check(count($opened) === 3 && $opened[2] === 'https://example.com' && $calls === 2, 'openURL alone: nothing told');

// A popover's light dismiss: a press in a popover inside it is not outside; one in it closes the inner; outside closes it and goes on; its anchor toggles it; Escape.
$open = false; $inner = true; $behind = 0;
$h = new Headless(function (Context $c) use (&$open, &$inner, &$behind): void {
    $c->row(10, 100)->alignItems(Node::START);
      $more = $c->buttonBase()->size(60, 20)->label('more'); $c->end();
      if ($c->clicked($more)) { $open = !$open; }
      if ($c->clicked($c->buttonBase()->size(60, 20)->label('behind'))) { $behind++; }
      $c->end();
      $c->popoverBase($more, $open, function (Node $panel) use ($c, &$inner): void {
          $c->buttonBase()->size(80, 20)->label('rename'); $c->end();
          $sub = $c->buttonBase()->size(80, 20)->label('submenu'); $c->end();
          $c->popoverBase($sub, $inner, function (Node $p) use ($c): void { $c->buttonBase()->size(40, 20)->label('deep'); $c->end(); });
      });
    $c->end();
}, 400, 300);
$h->frame();
$h->clickOn($h->byLabel('more'));
$r = $h->byLabel('rename') === null ? null : $h->rect($h->byLabel('rename'));
check($open && $r !== null && $r[0] == 10.0 && $r[1] == 30.0, 'the popover below its anchor: ' . json_encode($r));
$h->clickOn($h->byLabel('deep'));
check($open && $inner, 'a press in the inner popover closes neither');
$h->clickOn($h->byLabel('rename'));
check($open && !$inner, 'a press in the panel closes the inner one');
$h->clickOn($h->byLabel('behind'));
check(!$open && $behind === 1, 'outside: it closes, and the press goes on');
$h->clickOn($h->byLabel('more')); $h->clickOn($h->byLabel('more'));
check(!$open, 'its anchor toggles it');
$h->clickOn($h->byLabel('more')); $h->escape();
check(!$open, 'Escape closes it');

// attachTo: below, beside, flipped and shifted near the edges, above where there is no room below.
$at = Anchor::BottomLeft; $self = Anchor::TopLeft; $right = false;
$h = new Headless(function (Context $c) use (&$at, &$self, &$right): void {
    $b = $c->buttonBase()->size(40, 20)->label('anchor')->absolute()->top(100)->left($right ? 350 : 100); $c->end();
    $c->overlay(function () use ($c, $b, &$at, &$self): void { $c->box(60, 30, 0)->label('panel')->attachTo($b, $at, $self)->margin(4, 0, 0, 0); });
}, 400, 300);
$h->frame(3);
$panel = fn() => $h->rect($h->byLabel('panel'));
same([100.0, 124.0], array_slice($panel(), 0, 2), 'below');
$at = Anchor::Right; $self = Anchor::Left; $h->frame(3);
same([140.0, 99.0], array_slice($panel(), 0, 2), 'to the right');
$right = true; $h->frame(3);
same(290.0, $panel()[0], 'to the right near the edge: to the left');
$at = Anchor::BottomLeft; $self = Anchor::TopLeft; $h->frame(3);
same([330.0, 124.0], array_slice($panel(), 0, 2), 'below near the right edge: its right edge aligned');
$h->h = 140; $h->frame(3);
same(66.0, $panel()[1], 'above, where there is no room below');

// A modal overlay of the app's own: the focus into it, Tab kept in it, what is behind hidden from assistive technology, Escape giving the focus back.
$open = false;
$h = new Headless(function (Context $c) use (&$open): void {
    $c->column()->alignItems(Node::START);
      if ($c->clicked($c->buttonBase()->size(60, 20)->label('open'))) { $open = true; }
      $c->end();
      $c->buttonBase()->size(60, 20)->label('outside'); $c->end();
    $c->end();
    if ($open) {
        $c->overlay(function () use ($c, &$open): void {
            $back = $c->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->center()->block();
            if ($c->overlayShortcut($back, Key::ESCAPE)) { $open = false; }
              $c->row()->role(Role::DIALOG)->label('Rename');
                $c->buttonBase()->size(60, 20)->label('ok'); $c->end();
                $c->buttonBase()->size(60, 20)->label('cancel'); $c->end();
              $c->end();
            $c->end();
        });
    }
}, 300, 200);
$h->frame();
$h->tab(); $h->enter();
$focused = fn(string $l) => $h->focused()?->label === $l;
check($open && $focused('ok'), 'open, the focus on its first control');
$h->tab(); $h->tab();
check($focused('ok'), 'Tab stays in the dialog');
$tree = pmTree($h);
check(array_filter($tree->nodes, fn(AccessNode $n) => $n->label === 'outside') === [], 'what is behind it is hidden from assistive technology');
$h->escape();
check(!$open && $focused('open'), 'Escape closes it, the focus back on its opener');

// A slider without a look: vertical, stepped; disabled, it moves no more.
$v = 0.0; $hh = 0.0; $disabled = false;
$h = new Headless(function (Context $c) use (&$v, &$hh, &$disabled): void {
    $c->row(0, 20)->alignItems(Node::START);
      $c->sliderBase($v, 0, 100, 10, vertical: true)->size(20, 100)->label('v');
      $n = $c->sliderBase($hh, 0, 100, 25)->size(100, 20)->label('h');
      $n->disabled = $disabled;
    $c->end();
}, 300, 200);
$h->frame();
$r = $h->rect($h->byLabel('v'));
$h->click($r[0] + 10, $r[1] + $r[3] * 0.3);
same(70.0, $v, 'pressing 30% down a vertical slider');
$h->up();
same(80.0, $v, 'Up a step');
$h->clickOn($h->byLabel('h'));
same(50.0, $hh, 'pressing the middle');
$h->right(); $h->key(Key::PAGE_UP, 'scrollPageUp:');
same(100.0, $hh, 'Right and Page Up by steps of 25 from 50');
// A change only of its value, as mygo's: a move to where it is, an arrow at an end, is none.
$changes = 0;
$h2 = new Headless(function (Context $c) use (&$hh, &$changes): void {
    if ($c->changed($c->sliderBase($hh, 0, 100, 25)->size(100, 20)->label('h2'))) { $changes++; }
}, 300, 200);
$h2->frame();
$r2 = $h2->rect($h2->byLabel('h2'));
$h2->press($r2[0] + 50, $r2[1] + 10); $h2->drag($r2[0] + 51, $r2[1] + 10); $h2->drag($r2[0] + 51, $r2[1] + 10); $h2->release($r2[0] + 51, $r2[1] + 10);
same([50.0, 1], [$hh, $changes], 'pressed in the middle, then moved within the step: one change');
$hh = 100.0; $changes = 0; $h2->frame();
$h2->rt->focus = $h2->byLabel('h2')->id; $h2->right(); $h2->key(Key::END, 'moveToEndOfLine:');
same([100.0, 0], [$hh, $changes], 'Right and End at the end: none');
$tree = pmTree($h);
check((pmAccess($tree, Role::SLIDER, 'v')?->states & AccessNode::VERTICAL) !== 0, 'vertical to assistive technology');
$disabled = true; $h->frame();
$r = $h->rect($h->byLabel('h'));
$h->click($r[0] + 1, $r[1] + 10); $h->left();
same(100.0, $hh, 'a disabled slider moves no more');

// A select's popup highlights what the app says: Enter chooses it.
$size = 'S'; $jump = false;
$h = new Headless(function (Context $c) use (&$size, &$jump): void {
    $sel = $c->selectBase($size);
      $sel->trigger->size(60, 20)->label('size');
    $c->end();
    if ($jump) { $sel->highlight('L'); $jump = false; }
    $sel->popup(function () use ($c, $sel): void {
        foreach (['S', 'M', 'L'] as $s) { $sel->item($s)->size(60, 20); $c->text($s); $c->end(); }
    });
}, 200, 200);
$h->frame();
$h->clickOn($h->byLabel('size'));
$jump = true; $h->frame();
$h->enter();
same('L', $size, 'Enter chooses the item highlighted');

// A disabled stepper steps neither by a click nor by the keys.
$v = 5.0;
$h = new Headless(function (Context $c) use (&$v): void { $c->stepper($v, 0, 10)->label('count')->disabled = true; }, 100, 100);
$h->frame();
$r = $h->rect($h->byLabel('count'));
$h->click($r[0] + $r[2] / 2, $r[1] + 4);
$h->tab(); $h->up();
same(5.0, $v, 'a disabled stepper stays');

// A value snapped to the steps keeps their decimals.
foreach ([[0.30000000000000004, 0, 1, 0.1, 0.3], [5.2e-12, 0, 1, 1e-12, 5e-12], [1234567.8914, 0, 1e7, 0.001, 1234567.891], [3.4, 0, 10, 2.5, 2.5]] as [$x, $lo, $hi, $step, $want]) {
    same($want, Context::snap($x, $lo, $hi, $step), "snap($x) by $step");
}

// A drawer: a modal panel at the right, which a press in it keeps open and one on the backdrop closes, the focus back on its button.
$drawer = false;
$h = new Headless(function (Context $c) use (&$drawer): void {
    if ($c->clicked($c->buttonBase()->size(60, 20)->label('filters'))) { $drawer = true; }
    $c->end();
    if ($drawer) {
        $c->overlay(function () use ($c, &$drawer): void {
            $back = $c->row()->absolute()->left(0)->top(0)->right(0)->bottom(0)->justify(Node::END)->alignItems(Node::STRETCH)->background(0x000000, 0.3)->block();
              $panel = $c->column(16)->size(120, 0)->alignItems(Node::START)->background($c->theme->background)->role(Role::DIALOG)->label('Filters');
                $c->buttonBase()->size(60, 20)->label('apply'); $c->end();
              $c->end();
              if ($c->pressedOutside($panel) || $c->overlayShortcut($back, Key::ESCAPE)) { $drawer = false; }
            $c->end();
        });
    }
}, 300, 200);
$h->frame();
$h->clickOn($h->byLabel('filters'));
check($drawer && $h->rect($h->byLabel('Filters'))[0] == 180.0, 'the drawer at the right');
$h->clickOn($h->byLabel('apply')); $h->click(250, 150);
check($drawer, 'a press in the drawer keeps it');
$h->click(30, 150);
check(!$drawer && $h->focused()?->label === 'filters', 'a press on the backdrop closes it, the focus back on its button');

// A popover moves with its anchor as it scrolls, in the same frame.
$open = true; $sc = new ScrollState();
$h = new Headless(function (Context $c) use (&$open, $sc): void {
    $c->column(0, 0, 1)->scroll($sc);
      $c->column()->size(0, 1000)->alignItems(Node::START);
        $c->box(0, 100, 0);
        $b = $c->buttonBase()->size(60, 20)->label('anchor'); $c->end();
        $c->popoverBase($b, $open, function (Node $p) use ($c): void { $c->box(40, 20, 0)->label('panel'); });
      $c->end();
    $c->end();
}, 200, 300);
$h->frame(3);
same(120.0, $h->rect($h->byLabel('panel'))[1], 'the panel below its anchor');
$h->input->mouse(100, 150); $h->input->push(['scroll', 100, 150, 0, -50]); $h->frame();
same([50.0, 70.0], [$h->rect($h->byLabel('anchor'))[1], $h->rect($h->byLabel('panel'))[1]], 'scrolled by 50, together');

// Escape closes the inner of two popovers first.
$outer = true; $inner = true;
$h = new Headless(function (Context $c) use (&$outer, &$inner): void {
    $b = $c->buttonBase()->size(60, 20)->label('more'); $c->end();
    $c->popoverBase($b, $outer, function (Node $p) use ($c, &$inner): void {
        $sub = $c->buttonBase()->size(60, 20)->label('sub'); $c->end();
        $c->popoverBase($sub, $inner, function (Node $q) use ($c): void { $c->box(40, 20, 0); });
    });
}, 300, 300);
$h->frame();
$h->escape();
check($outer && !$inner, 'one Escape: the inner closes');
$h->escape();
check(!$outer, 'the second: the outer');

// A card attached below a link inside a paragraph.
$link = null;
$h = new Headless(function (Context $c) use (&$link): void {
    $c->richText([], width: 300)->children(function () use ($c, &$link): void {
        $c->text('Read the ');
        $link = $c->link('guide', 'https://example.com');
        $c->text(' first.');
    });
    $c->overlay(function () use ($c, &$link): void { $c->box(80, 40, 0)->label('card')->attachTo($link, Anchor::BottomLeft, Anchor::TopLeft); });
}, 400, 200);
$h->frame(3);
$l = $h->rect($link); $card = $h->rect($h->byLabel('card'));
check($l[2] > 0 && $card[0] == $l[0] && $card[1] == $l[1] + $l[3], 'the card below the link: ' . json_encode([$l, $card]));

// How far the keys move each kind of range, as assistive technology learns it.
$a = 50.0; $b = 50.0; $s = 5.0; $low = 10.0; $high = 90.0; $stars = 3;
$h = new Headless(function (Context $c) use (&$a, &$b, &$s, &$low, &$high, &$stars): void {
    $c->column()->alignItems(Node::START);
      $c->slider($a, 0, 200)->label('plain');
      $c->sliderBase($b, 0, 100, 5)->size(100, 20)->label('stepped');
      $c->stepper($s, 0, 10, 0.5)->label('stepper');
      $c->rangeSlider($low, $high, 0, 100, 10)->label('price');
      $c->rating($stars, 5)->label('stars');
      $c->box(80, 20, 0)->focusable()->role(Role::SLIDER)->label('custom')->range(0, 1, 0.5, 0.25);
      $c->progress(0.5)->label('progress');
    $c->end();
}, 400, 400);
$h->frame();
$tree = pmTree($h);
foreach ([[Role::SLIDER, 'plain', 2.0], [Role::SLIDER, 'stepped', 5.0], [Role::STEPPER, 'stepper', 0.5], [Role::SLIDER, 'price minimum', 10.0], [Role::SLIDER, 'stars', 1.0], [Role::SLIDER, 'custom', 0.25], [Role::PROGRESS, 'progress', 0.0]] as [$role, $label, $step]) {
    same($step, pmAccess($tree, $role, $label)?->step, "the step of $label");
}

// onTextKey: a composer sends with Enter and breaks the line with Shift+Enter; one taken changes nothing (mygo's TestOnTextKeyTakesEnterByModifier, TestOnTextKeySeesModifiersAndHolds).
foreach ([false, true] as $multiline) {
    $text = new TextState(''); $submits = 0; $takes = false; $sent = ''; $sends = 0; $seen = [];
    $text->onTextKey = function (int $code, string $chars, int $mods, TextState $s) use (&$takes, &$sent, &$sends, &$seen, $multiline): bool {
        if (!$takes || $code !== Key::RETURN) { return false; }
        $sends++; $sent = $s->text; $seen[] = $mods;
        if (!$multiline) { $s->text = ''; $s->setCursor(0); } // a composer clears the draft it sent
        return true;
    };
    $h = new Headless(function (Context $c) use ($text, $multiline, &$submits): void {
        $n = $multiline ? $c->textAreaBase($text) : $c->textInputBase($text);
        $n->width(100)->label('draft');
        if ($c->submitted($n)) { $submits++; }
    }, 200, 80);
    $h->frame();
    $h->clickOn($h->byLabel('draft'));
    $h->type('hello');
    $h->enter();
    $what = $multiline ? 'a text area' : 'a field';
    check($multiline ? $text->text === "hello\n" : $submits === 1, "$what: Enter not taken does what it does");
    $takes = true;
    $h->enter(Key::SHIFT);
    check($sends === 1 && ($sent === 'hello' || $sent === "hello\n") && $seen === [Key::SHIFT], "$what: Shift+Enter taken, with its modifier");
    check($multiline ? $text->text === "hello\n" : $submits === 1 && $text->text === '', "$what: taken, nothing else happened");
    $h->type('x');
    same($multiline ? "hello\nx" : 'x', $text->text, "$what: it edits on");
}

// onTextKey sees the keys that type no text, Tab and the arrows among them; what it leaves goes on: Tab moves the focus, Up the caret (mygo's TestOnTextKeySeesKeysThatTypeNoText).
$draft = new TextState(''); $other = new TextState(''); $seen = []; $take = [];
$draft->onTextKey = function (int $code, string $chars, int $mods, TextState $s) use (&$seen, &$take): bool {
    $seen[] = [$code, $mods];
    if ($code === Key::UP) {
        if (($take[Key::UP] ?? false) && $s->text === '') { $s->text = 'last message'; $s->setCursor(strlen($s->text)); return true; } // Up edits the last message
        return false;
    }
    return $take[$code] ?? false;
};
$h = new Headless(function (Context $c) use ($draft, $other): void {
    $c->column();
      $c->textInputBase($draft)->width(100)->label('draft');
      $c->textInputBase($other)->width(100)->label('other');
    $c->end();
}, 200, 100);
$h->frame();
$h->clickOn($h->byLabel('draft'));
$h->key(Key::A, '', 0, 'a'); $h->type('a');
$h->key(Key::B, '', Key::SHIFT, 'B'); $h->type('B');
same([], $seen, 'keys that type text do not reach it');
$take[Key::TAB] = true;
$h->tab(); $h->type('c');
check($seen === [[Key::TAB, 0]] && $draft->text === 'aBc', 'a Tab taken stays in the field');
$take[Key::TAB] = false;
$h->tab(); $h->type('x');
same('x', $other->text, 'a Tab left moves the focus');
$h->clickOn($h->byLabel('draft')); $seen = [];
$h->key(Key::RETURN, 'insertNewline:', Key::COMMAND, "\r");
same([[Key::RETURN, Key::COMMAND]], $seen, 'a Cmd chord reaches it');
$take[Key::UP] = true;
$h->up();
same('aBc', $draft->text, 'Up with a draft moves the caret');
$draft->text = ''; $draft->setCursor(0);
$h->up();
same('last message', $draft->text, 'Up in an empty field puts the last message back');
