<?php
// Nodes ignoring input (mygo's ignoreinput_test.go): the pointer goes
// through to what is under them, Tab and clicks pass them by and they
// lose the focus, their text is not selected; assistive technology still
// finds them and presses them, but neither focuses them nor steps them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, AccessTree, Context, Cursor, Role, TextState};

$findAccess = function (AccessTree $t, int $role, string $label): ?AccessNode {
    foreach ($t->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
};

// A row showing its Delete button while it is hovered keeps it built at rest, ignoring input. (A row's clicks are asked for after its children's, which take a click first.)
$rowClicks = $deletes = $innerClicks = 0; $rowHovered = $delHovered = false;
$t = new Headless(function (Context $c) use (&$rowClicks, &$deletes, &$innerClicks, &$rowHovered, &$delHovered) {
    $c->column(0, 0);
      $row = $c->row(0, 0)->size(200, 40)->label('row');
      $rowHovered = $c->hovered($row);
        $c->column(0, 0, 1);
        $c->end();
        $del = $c->button('Delete')->ignoreInput(!$rowHovered);
        if (!$rowHovered) { $del->opacity(0); }
        $delHovered = $c->hovered($del);
        if ($c->clicked($del)) { $deletes++; }
      $c->end();
      if ($c->clicked($row)) { $rowClicks++; }
      // Inside a node ignoring input, a clickable one ignores it too, and the pointer has the cursor of what is under them.
      $c->column(0, 0)->size(200, 40)->cursor(Cursor::Pointer);
        $c->column(0, 0)->size(200, 40)->ignoreInput()->cursor(Cursor::Text);
          $inner = $c->column(0, 0)->size(40, 40)->label('inner')->cursor(Cursor::Text);
          if ($c->clicked($inner)) { $innerClicks++; }
          $c->end();
        $c->end();
      $c->end();
    $c->end();
}, 300, 200);
$t->frame();
$del = $t->byText('Delete');
[$x, $y] = $t->middle($del);
$t->input->mouse($x, $y);
check(!$t->rt->hovered($del->id) && $t->rt->hovered($t->byLabel('row')->id), 'at rest, the pointer goes through the button to the row');
$t->hover($x, $y);
check($rowHovered && $delHovered, 'the row hovering, the button shown takes the pointer itself');
$t->click($x, $y);
same([1, 0], [$deletes, $rowClicks], 'clicking the button shown: a delete, not the row');
$t->hover(250, 150);
check(!$rowHovered, 'the row hovers no more with the pointer away');
$t->click($x, $y);
same([1, 1], [$deletes, $rowClicks], "a press on the button at rest is the row's");
[$ix, $iy] = $t->middle($t->byLabel('inner'));
$t->hover($ix, $iy);
same(Cursor::Pointer, $t->rt->cursor, 'the cursor of what is under the nodes ignoring input');
$t->click($ix, $iy);
same(0, $innerClicks, 'a node inside one ignoring input takes no click');
$t->hover(250, 190);

// Assistive technology finds it, a button it presses but does not focus.
$t->input->push(['accessOn']);
$t->frame();
$n = $findAccess($t->rt->accessTree, Role::BUTTON, 'Delete');
check($n !== null && ($n->actions & AccessNode::ACTION_PRESS) !== 0 && ($n->actions & AccessNode::ACTION_FOCUS) === 0 && ($n->states & AccessNode::FOCUSABLE) === 0, 'to assistive technology: pressed, not focused');
$t->input->push(['access', $n->id, AccessNode::PRESS, '', 0, 0]);
$t->frame();
same([2, 1], [$deletes, $rowClicks], 'pressing it from assistive technology deletes');
$t->input->push(['access', $n->id, AccessNode::FOCUS, '', 0, 0]);
$t->frame();
check($t->rt->focus !== $n->id && $t->rt->accessTree->focus !== $n->id, 'assistive technology does not focus it');
check($findAccess($t->rt->accessTree, Role::GROUP, 'inner') !== null, 'what is inside one ignoring input still reads');

// Tab passes it by, a click does not focus it, and it loses the focus as it starts ignoring input.
$ignore = true;
$t = new Headless(function (Context $c) use (&$ignore) {
    $c->column(0, 0);
      $c->button('A');
      $c->button('B')->ignoreInput($ignore);
      $c->column(0, 0)->ignoreInput($ignore);
        $c->button('inside');
      $c->end();
      $c->button('C');
    $c->end();
}, 200, 300);
$t->frame();
$t->tab();
same('A', $t->focused()?->text, 'Tab focuses A');
$t->tab();
same('C', $t->focused()?->text, 'Tab from A goes to C, past B and the button inside');
$t->tab(true);
same('A', $t->focused()?->text, 'Shift+Tab from C passes B by');
$t->clickText('B');
check($t->focused()?->text !== 'B', 'clicking B does not focus it');
$ignore = false;
$t->frame();
$t->tab();
same('B', $t->focused()?->text, 'taking input again, Tab focuses it');
$ignore = true;
$t->frame();
same(null, $t->rt->focus, 'it loses the focus as it ignores input');

// A slider ignoring input: no increments, which go through its keys; a field's value is still set, without the focus.
$v = 5.0; $field = new TextState('Ada');
$t = new Headless(function (Context $c) use (&$v, $field) {
    $c->column(0, 8)->ignoreInput();
      $c->slider($v, 0, 10);
      $c->input($field);
    $c->end();
}, 300, 200);
$t->frame();
$t->input->push(['accessOn']);
$t->frame();
$slider = null;
foreach ($t->rt->accessTree->nodes as $a) { if ($a->role === Role::SLIDER) { $slider = $a; } }
check($slider !== null && ($slider->actions & (AccessNode::ACTION_INCREMENT | AccessNode::ACTION_DECREMENT)) === 0, 'a slider ignoring input does not step');
$t->input->push(['access', $slider->id, AccessNode::INCREMENT, '', 0, 0]);
$t->frame();
same(5.0, $v, 'nor does assistive technology step it');
$fieldNode = null;
foreach ($t->rt->accessTree->nodes as $a) { if ($a->role === Role::TEXT_FIELD) { $fieldNode = $a; } }
$t->input->push(['access', $fieldNode->id, AccessNode::SET_VALUE, 'Grace', 0, 0]);
$t->frame();
same(['Grace', null], [$field->text, $t->rt->focus], "assistive technology sets the field's value, without focusing it");
