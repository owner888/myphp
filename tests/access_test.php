<?php
// What assistive technology sees of a window and does in it (mygo's
// access_test.go): the tree of the nodes of a frame, their roles, names,
// states and actions, and the actions acting as the pointer and the keys.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, AccessTree, Context, Key, ListState, Node, Role, TableColumn, TextState};

require_once __DIR__ . "/EditorTest.php";
require_once __DIR__ . "/DiffTest.php";

/** The node of the tree with $role and a label containing $label, or null. */
function accessNode(AccessTree $tree, int $role, string $label): ?AccessNode
{
    foreach ($tree->nodes as $n) {
        if ($n->role === $role && str_contains($n->label, $label)) { return $n; }
    }
    return null;
}

/** The node of the tree with $id, or null. */
function accessById(AccessTree $tree, int $id): ?AccessNode
{
    foreach ($tree->nodes as $n) { if ($n->id === $id) { return $n; } }
    return null;
}

/** Sends an event of assistive technology and runs the frames after it. */
function accessSend(Headless $t, array $event): void
{
    $t->input->push($event);
    $t->frame();
}

// The tree of a window of widgets.
$d = ['count' => 0, 'name' => new TextState('Ada'), 'agree' => false, 'dark' => false, 'volume' => 30.0, 'choice' => '', 'size' => 0, 'selected' => 0];
$t = new Headless(function (Context $c) use (&$d) {
    $th = $c->theme;
    $c->column(20, 14, 1);
      $c->row(0, 10);
        $c->text('MyPHP UI', 24);
        $c->spacer();
        $c->muted('GPU rendered, pure PHP');
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->button('Increment'))) { $d['count']++; }
        if ($c->clicked($c->primaryButton('Reset'))) { $d['count'] = 0; }
        $c->text("Count: {$d['count']}");
      $c->end();
      $c->row(0, 16);
        $c->checkbox('I agree', $d['agree']);
        $c->switch('Dark', $d['dark']);
        $c->radio('Alpha', $d['choice'], 'a');
        $c->radio('Beta', $d['choice'], 'b');
      $c->end();
      $c->row(0, 10);
        $c->input($d['name'], 'Your name', 0);
        $c->select(['Small', 'Medium', 'Large'], $d['size']);
      $c->end();
      $c->slider($d['volume'], 0, 100);
      $c->progress($d['volume'] / 100);
      $c->list(new ListState, 1000, function (int $i) use ($c, &$d) {
          $row = $c->row(8, 8);
            $c->text("Row $i");
            $c->muted((string)($i * $i));
          $c->end();
          if ($c->clicked($row)) { $d['selected'] = $i; }
      })->grow = 1;
    $c->end();
}, 640, 600);
$t->frame();
same(null, $t->rt->accessTree, 'no tree before assistive technology asked');
accessSend($t, ['accessOn']);
$tree = $t->rt->accessTree;
check($tree !== null && $tree->nodes !== [], 'a tree after it asked');
$inc = accessNode($tree, Role::BUTTON, 'Increment');
check($inc !== null && ($inc->actions & AccessNode::ACTION_PRESS) !== 0 && $inc->bounds[2] > 0, 'the button Increment presses');
check(accessNode($tree, Role::TEXT, 'MyPHP UI') !== null, 'a text');
$agree = accessNode($tree, Role::CHECK_BOX, 'I agree');
check($agree !== null && ($agree->states & AccessNode::CHECKED) === 0, 'the check box, unchecked');
check(accessNode($tree, Role::SWITCH, '') !== null, 'the switch');
check(accessNode($tree, Role::RADIO, 'Alpha') !== null, 'a radio button');
$slider = accessNode($tree, Role::SLIDER, '');
same([0.0, 100.0, 30.0], [$slider->min, $slider->max, $slider->now], 'the slider\'s range');
$field = accessNode($tree, Role::TEXT_FIELD, '');
check($field->value === 'Ada' && ($field->actions & AccessNode::ACTION_SET_VALUE) !== 0 && $field->placeholder === 'Your name', 'the text field');
check(accessNode($tree, Role::POP_UP_BUTTON, '') !== null, 'the select');
$list = accessNode($tree, Role::LIST, '');
$row = accessNode($tree, Role::TEXT, 'Row 3');
$inside = false;
for ($p = $row->parent; $p >= 0; $p = $tree->nodes[$p]->parent) { if ($tree->nodes[$p]->id === $list->id) { $inside = true; break; } }
check($inside, 'the row\'s text is inside the list');
$texts = array_filter($tree->nodes, fn(AccessNode $n) => $n->role === Role::TEXT && $n->label === 'Increment');
same([], $texts, 'a button names itself after its text, which is no node of its own');

// Actions act as the pointer and the keyboard would.
accessSend($t, ['access', $inc->id, AccessNode::PRESS]);
same(1, $d['count'], 'pressing Increment clicks it');
accessSend($t, ['access', $agree->id, AccessNode::PRESS]);
check($d['agree'], 'pressing the check box checks it');
check((accessNode($t->rt->accessTree, Role::CHECK_BOX, 'I agree')->states & AccessNode::CHECKED) !== 0, 'and the tree after shows it checked');
accessSend($t, ['access', $slider->id, AccessNode::INCREMENT]);
check($d['volume'] > 30, 'incrementing the slider moves it');
same($slider->id, $t->rt->accessTree->focus, 'and focuses it');
accessSend($t, ['access', $field->id, AccessNode::SET_VALUE, 'Grace']);
same('Grace', $d['name']->text, 'setting the text field\'s value');
$f = accessNode($t->rt->accessTree, Role::TEXT_FIELD, '');
check($f->value === 'Grace' && $f->selStart === 5 && $f->selEnd === 5 && $t->rt->accessTree->focus === $f->id, 'the field after setting it: its value, caret and focus');

// A list's rows say which of all they are, and that they can be chosen.
$sel = new ListState; $sel->selected = -1;
$t = new Headless(function (Context $c) use ($sel) {
    $c->column(0, 0, 1);
      $c->button('Before');
      $c->list($sel, 1000, function (int $i) use ($c) { $c->text("Row $i")->size(0, 30); })->grow = 1;
    $c->end();
}, 300, 400);
$t->frame();
accessSend($t, ['accessOn']);
$tree = $t->rt->accessTree;
$item = accessNode($tree, Role::LIST_ITEM, 'Row 3');
same([4, 1000], [$item->posInSet, $item->setSize], 'row 3 is the fourth of 1000');
$both = AccessNode::SELECTABLE | AccessNode::FOCUSABLE;
check(($item->states & $both) === $both && ($item->actions & (AccessNode::ACTION_PRESS | AccessNode::ACTION_FOCUS | AccessNode::ACTION_SCROLL_INTO_VIEW)) !== 0, 'a row is chosen, focused and scrolled into view');
check(accessNode($tree, Role::TEXT, 'Row 3') !== null, 'its content shows too');
check((accessNode($tree, Role::BUTTON, 'Before')->actions & AccessNode::ACTION_SCROLL_INTO_VIEW) === 0, 'a button out of any scroll container does not scroll into view');
// Focusing a row chooses it, the list taking the focus: assistive technology follows the choice as the arrows move it.
accessSend($t, ['access', $item->id, AccessNode::FOCUS]);
$tree = $t->rt->accessTree;
check($sel->selected === 3 && $tree->focus === $item->id, 'focusing row 3 chooses it and puts the focus on it');
check((accessById($tree, $item->id)->states & AccessNode::CHECKED) !== 0, 'the chosen row is chosen');
$t->down();
$tree = $t->rt->accessTree;
$n = accessById($tree, $tree->focus);
check($n !== null && $n->posInSet === 5 && ($n->states & AccessNode::CHECKED) !== 0, 'Down moves the focus with the choice');
// The last row built, out of view, scrolls into view when assistive technology asks, and the rows after it come.
$last = null;
foreach ($tree->nodes as $n) { if ($n->role === Role::LIST_ITEM && ($last === null || $n->posInSet > $last->posInSet)) { $last = $n; } }
check($last->bounds[1] + $last->bounds[3] > 400 && ($last->states & AccessNode::OFFSCREEN) !== 0, 'the last row built is out of view');
accessSend($t, ['access', $last->id, AccessNode::SCROLL_INTO_VIEW]);
$tree = $t->rt->accessTree;
$n = accessById($tree, $last->id);
check($n !== null && $n->bounds[1] + $n->bounds[3] <= 400.01, 'scrolled into view');
$beyond = false;
foreach ($tree->nodes as $n) { $beyond = $beyond || $n->role === Role::LIST_ITEM && $n->posInSet > $last->posInSet; }
check($beyond, 'and rows after it came');

// A table's rows hold their cells, under a header; the table has the focus for its rows.
$s = new ListState; $s->selected = 2;
$t = new Headless(function (Context $c) use ($s) {
    $c->table($s, [new TableColumn('Name'), new TableColumn('Size', 80)], 500, function (int $row, int $col) use ($c) { $c->text("Cell $row $col"); })->grow = 1;
}, 400, 300);
$t->frame();
accessSend($t, ['accessOn']);
$tree = $t->rt->accessTree;
$rows = array_values(array_filter($tree->nodes, fn(AccessNode $n) => $n->role === Role::ROW));
check(count($rows) >= 5 && $rows[0]->posInSet === 0, 'rows, the header first, of no place');
$bad = [];
foreach (array_slice($rows, 1) as $r) {
    if ($r->setSize !== 500 || ($r->states & AccessNode::SELECTABLE) === 0) { $bad[] = "row {$r->posInSet}"; }
    foreach ($tree->nodes as $m) {
        if ($m->parent >= 0 && $tree->nodes[$m->parent]->id === $r->id && $m->role !== Role::CELL) { $bad[] = "row {$r->posInSet} holds role {$m->role}"; }
    }
}
same([], $bad, 'the rows: of 500, chosen, holding cells');
check(accessNode($tree, Role::COLUMN_HEADER, 'Name') !== null, 'a column\'s header');
$t->clickText('Cell 4 0');
$tree = $t->rt->accessTree;
$n = accessById($tree, $tree->focus);
check($n !== null && $n->role === Role::ROW && $n->posInSet === 5, 'clicking row 4 puts the focus on it');

// What is behind a dialog is inert; its nodes are inside it.
$open = true;
$t = new Headless(function (Context $c) use (&$open) {
    $c->button('Behind');
    $c->modal($open, function () use ($c) { $c->text('Sure?'); $c->button('OK'); });
}, 400, 300);
$t->frame();
accessSend($t, ['accessOn']);
$tree = $t->rt->accessTree;
$dialog = accessNode($tree, Role::DIALOG, '');
$ok = accessNode($tree, Role::BUTTON, 'OK');
check($dialog !== null && $ok !== null && $tree->nodes[$ok->parent]->id === $dialog->id, 'the dialog\'s button is inside it');
same(null, accessNode($tree, Role::BUTTON, 'Behind'), 'what is behind it is not in the tree');

// A role of the app's own, and none.
$on = false;
$t = new Headless(function (Context $c) use (&$on) {
    $b = $c->box(20, 20, 0)->focusable()->role(Role::SWITCH)->label('Wi-Fi');
    if ($c->clicked($b)) { $on = !$on; }
    $c->box(20, 20, 0)->label('hidden')->role(Role::NONE);
}, 100, 100);
$t->frame();
accessSend($t, ['accessOn']);
$sw = accessNode($t->rt->accessTree, Role::SWITCH, 'Wi-Fi');
check($sw !== null, 'a node of the app\'s role');
same([], array_values(array_filter($t->rt->accessTree->nodes, fn(AccessNode $n) => $n->label === 'hidden')), 'a node of no role is not in the tree');
accessSend($t, ['access', $sw->id, AccessNode::PRESS]);
check($on, 'pressing the app\'s switch clicks it');

// Toasts are read out.
$t = new Headless(function (Context $c) { if ($c->clicked($c->button('Save'))) { $c->toast('Saved'); } }, 300, 200);
$t->frame();
accessSend($t, ['accessOn']);
$t->clickText('Save');
same(['Saved'], $t->told, 'a toast is announced');

// A segmented control is a radio group of segments; a tree's items open and close as assistive technology asks.
$view = 0; $open = false; $chosen = 'b';
$t = new Headless(function (Context $c) use (&$view, &$open, &$chosen) {
    $c->segmented(['List', 'Grid'], $view);
    $c->tree(function () use ($c, &$open) {
        $c->treeItem('Folder', $open, function () use ($c) { $none = null; $c->treeItem('File', $none, null); });
    });
    $c->sidebar($chosen, function () use ($c) { $c->sidebarItem('a', 'Inbox'); $c->sidebarItem('b', 'Sent'); });
}, 400, 400);
$t->frame();
accessSend($t, ['accessOn']);
$tree = $t->rt->accessTree;
$grid = accessNode($tree, Role::RADIO, 'Grid');
check($grid !== null && ($grid->states & AccessNode::SEGMENT) !== 0 && ($grid->states & AccessNode::CHECKED) === 0, 'a segment, a radio button, unchosen');
accessSend($t, ['access', $grid->id, AccessNode::PRESS]);
same(1, $view, 'pressing a segment chooses it');
$folder = accessNode($t->rt->accessTree, Role::TREE_ITEM, 'Folder');
check($folder !== null && ($folder->states & AccessNode::EXPANDABLE) !== 0 && ($folder->states & AccessNode::EXPANDED) === 0 && $folder->level === 1 && $folder->label === 'Folder', 'a closed item of a tree, its arrow no part of its name');
accessSend($t, ['access', $folder->id, AccessNode::EXPAND]);
check($open && accessNode($t->rt->accessTree, Role::TREE_ITEM, 'File')?->level === 2, 'expanding it opens it, its item a level down');
$inbox = accessNode($t->rt->accessTree, Role::TREE_ITEM, 'Inbox');
check(($inbox->states & AccessNode::SELECTABLE) !== 0 && ($inbox->actions & AccessNode::ACTION_FOCUS) !== 0, 'a sidebar\'s item is chosen by focusing it');
accessSend($t, ['access', $inbox->id, AccessNode::FOCUS]);
same('a', $chosen, 'focusing it chooses it');
check((accessById($t->rt->accessTree, $inbox->id)->states & AccessNode::CHECKED) !== 0, 'and it is chosen');

// A code editor is a text field of many lines, named, with its text and selection; a diff viewer's sides are named after theirs.
$ct = new EditorTest('go', "func |main() {}");
accessSend($ct->h, ['accessOn']);
$ed = accessNode($ct->h->rt->accessTree, Role::TEXT_FIELD, 'Code editor');
check($ed !== null && $ed->value === 'func main() {}' && $ed->selStart === 5 && ($ed->states & AccessNode::MULTILINE) !== 0, 'the code editor: its name, text and caret');
$d = new \MyPHP\UI\Editor\DiffState(new \MyPHP\UI\Editor\Buffer("a\nb\n"), new \MyPHP\UI\Editor\Buffer("a\nB\n"));
$t = diffViewer($d, 600, 300);
accessSend($t, ['accessOn']);
check(accessNode($t->rt->accessTree, Role::TEXT_FIELD, 'Old text') !== null && accessNode($t->rt->accessTree, Role::TEXT_FIELD, 'New text') !== null, 'the sides of a diff viewer are named');
