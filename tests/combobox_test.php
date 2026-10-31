<?php
// Comboboxes and the fields around them (mygo's combobox_test.go): what a
// click, typing and the keys show and choose, what assistive technology
// reads, a search field's Escape, a token field's chips, and popups
// scrolling to what the keys highlight.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, AccessTree, Context, Key, Node, Role, TextState};

/** The node of $tree with $role named $label exactly. */
function cbNode(AccessTree $tree, int $role, string $label): AccessNode
{
    foreach ($tree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label");
}

/** Whether the frame shows a text containing $s (mygo's HasText). */
function cbHas(Headless $h, string $s): bool
{
    foreach ($h->texts() as $t) { if (str_contains($t, $s)) { return true; } }
    return false;
}

$fonts = ['Arial', 'Helvetica', 'Times', 'Courier', 'Optima'];

// A choice of a combobox without a look builds what depends on it at once, and once, by the pointer and by the keys.
foreach (['pointer', 'keyboard'] as $way) {
    $text = new TextState(''); $picked = 'none'; $choices = 0;
    $h = new Headless(function (Context $c) use ($text, &$picked, &$choices): void {
        $c->column();
          $c->text("Picked: $picked");
          $p = $c->key('combo')->comboboxBase($text);
          $p->input->label('Combo')->width(180);
          $p->popup(function (Node $panel) use ($c, $p): void {
              foreach (['Alpha', 'Beta'] as $value) { $p->item($value); $c->text($value); $c->end(); }
          });
          if (($value = $p->chosen()) !== null) { $picked = $value; $choices++; }
        $c->end();
    }, 300, 300);
    $h->frame();
    $h->clickLabel('Combo');
    if ($way === 'keyboard') { $h->down(); $h->down(); $h->enter(); } else { $h->clickText('Beta'); }
    check($picked === 'Beta' && $choices === 1 && $h->byText('Picked: Beta') !== null, "$way: picked $picked, $choices choices");
    $h->frame();
    same(1, $choices, "$way: another frame does not repeat the choice");
}

// A combobox: a click shows all the options; typing filters, those starting with it first; Enter, Down, a click; Escape; the focus leaving.
$font = 'Arial'; $changes = 0;
$h = new Headless(function (Context $c) use (&$font, &$changes, $fonts): void {
    $c->row(0, 8);
      if ($c->changed($c->combobox($font, $fonts, 200)->label('Font'))) { $changes++; }
      $c->button('Next');
    $c->end();
}, 400, 400);
$h->frame();
$field = $h->byLabel('Font');
$h->clickOn($field);
check(cbHas($h, 'Helvetica') && cbHas($h, 'Optima'), 'a click shows all the options');
$h->input->focus->selectAll();
$h->type('ti');
check(cbHas($h, 'Helvetica') && cbHas($h, 'Times') && cbHas($h, 'Optima') && !cbHas($h, 'Courier'), 'ti filters: ' . json_encode($h->texts()));
$texts = $h->texts();
check(array_search('Times', $texts, true) < array_search('Optima', $texts, true), 'Times, starting with ti, first');
$h->type('me');
check(!cbHas($h, 'Optima') && cbHas($h, 'Times'), 'time');
$h->enter();
check($font === 'Times' && $changes === 1 && !cbHas($h, 'Optima'), "Enter chose $font ($changes changes)");
$h->down();
check(cbHas($h, 'Courier'), 'Down opens the options');
$h->clickText('Courier');
check($font === 'Courier' && $h->rt->focus === $h->byLabel('Font')?->kids[0]->id || $font === 'Courier' && str_contains(Headless::label($h->focused()), 'Font'), "a click chose $font, the field keeps the focus");
$h->input->focus->selectAll();
$h->type('zzz');
$h->escape();
check(!cbHas($h, 'Arial'), 'Escape closes the options');
$h->tab();
$h->input->push(['accessOn']); $h->frame();
$n = cbNode($h->rt->accessTree, Role::COMBO_BOX, 'Font');
check($n->value === 'Courier' && $font === 'Courier', "after the focus left, it shows {$n->value}, chose $font");

// A click opening the options selects the choice, as the chevron does, so typing replaces it; a click while they show places the caret.
$font = 'Arial'; $changes = 0;
$h = new Headless(function (Context $c) use (&$font, &$changes, $fonts): void {
    $c->column()->alignItems(Node::START);
      if ($c->changed($c->combobox($font, $fonts, 200)->label('Font'))) { $changes++; }
    $c->end();
}, 400, 400);
$h->frame();
$r = $h->rect($h->byLabel('Font'));
$h->click($r[0] + 30, $r[1] + $r[3] / 2);
check(cbHas($h, 'Helvetica'), 'a click shows the options');
$h->type('ti');
check(cbHas($h, 'Times') && cbHas($h, 'Helvetica') && cbHas($h, 'Optima') && !cbHas($h, 'Courier'), 'typing after a click filters');
$h->enter();
check($font === 'Times' && $changes === 1, "Enter chose $font");
$h->click($r[0] + $r[2] - 12, $r[1] + $r[3] / 2);
check(cbHas($h, 'Optima'), 'the chevron shows them');
$h->type('co');
check(!cbHas($h, 'Optima') && cbHas($h, 'Courier'), 'typing after the chevron filters');
$h->enter();
check($font === 'Courier' && $changes === 2, "Enter chose $font ($changes changes)");
$h->click($r[0] + 30, $r[1] + $r[3] / 2);
$h->click($r[0] + $r[2] / 2 + 20, $r[1] + $r[3] / 2);
$h->type('x');
$h->input->push(['accessOn']); $h->frame();
check(cbNode($h->rt->accessTree, Role::COMBO_BOX, 'Font')->value !== 'x', 'a second click left the choice unselected: typing appends');

// What assistive technology reads: the combobox open, its list, the option the arrows are on with the focus and its place.
$font = 'Arial';
$h = new Headless(function (Context $c) use (&$font, $fonts): void {
    $c->column()->alignItems(Node::START);
      $c->combobox($font, $fonts, 200)->label('Font');
    $c->end();
}, 400, 400);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$h->clickOn($h->byLabel('Font'));
$h->down(); $h->down();
$tree = $h->rt->accessTree;
$cb = cbNode($tree, Role::COMBO_BOX, 'Font');
check(($cb->states & AccessNode::EXPANDED) !== 0 && $cb->value === 'Arial', 'the combobox, open, with its choice');
$list = null;
foreach ($tree->nodes as $n) { if ($n->role === Role::LIST) { $list = $n; } }
check($list !== null && ($list->states & AccessNode::SELECTABLE) !== 0, 'the popup, a list to choose from');
$f = null;
foreach ($tree->nodes as $n) { if ($n->id === $tree->focus) { $f = $n; } }
check($f !== null && $f->role === Role::LIST_ITEM && $f->label === 'Helvetica' && $f->posInSet === 2 && $f->setSize === 5 && ($f->states & AccessNode::CHECKED) !== 0, 'the focus on the option: ' . json_encode($f === null ? null : [$f->role, $f->label, $f->posInSet, $f->setSize, $f->states]));

// An autocomplete suggests nothing before typing; what contains the text; Enter on none submits what was typed; Down, Enter takes one.
$city = new TextState(''); $submitted = 0;
$cities = ['Paris', 'Parma', 'Lyon', 'Comparis'];
$h = new Headless(function (Context $c) use ($city, &$submitted, $cities): void {
    $c->column()->alignItems(Node::START);
      $c->autocomplete($city, $cities, '', 200)->label('City');
      if ($city->submitted) { $submitted++; $city->submitted = false; }
    $c->end();
}, 400, 400);
$h->frame();
$h->clickLabel('City');
check(!cbHas($h, 'Paris'), 'no suggestions before anything was typed');
$h->type('par');
check(cbHas($h, 'Paris') && cbHas($h, 'Parma') && cbHas($h, 'Comparis') && !cbHas($h, 'Lyon'), 'par suggests: ' . json_encode($h->texts()));
$h->enter();
check($city->text === 'par' && $submitted === 1, "Enter on no suggestion: {$city->text}, $submitted submitted");
$h->type('i');
$h->down(); $h->enter();
check($city->text === 'Paris' && !cbHas($h, 'Comparis'), "Down, Enter took {$city->text}");

// A search field: typing, Clear, Escape clearing it and then reaching the dialog; read as a search.
$query = new TextState(''); $changes = 0; $dialog = true;
$h = new Headless(function (Context $c) use ($query, &$changes, &$dialog): void {
    $c->modal($dialog, function () use ($c, $query, &$changes): void {
        if ($c->changed($c->searchField($query, 240)->label('Search mail')->autoFocus())) { $changes++; }
    });
}, 500, 400);
$h->frame();
$h->clickLabel('Search mail');
$h->type('invoice');
check($query->text === 'invoice' && $changes > 0, "typed {$query->text}, $changes changes");
$h->clickLabel('Clear');
check($query->text === '' && $h->focused()?->label === 'Search mail', 'Clear empties it, the field keeps the focus');
$h->type('x');
$h->escape();
check($query->text === '' && $dialog, 'Escape with text clears it, the dialog stays');
$h->escape();
check(!$dialog, 'Escape in an empty search field reaches the dialog');
$h->input->push(['accessOn']); $dialog = true; $h->frame();
check((cbNode($h->rt->accessTree, Role::TEXT_FIELD, 'Search mail')->states & AccessNode::SEARCH) !== 0, 'read as a search field');

// A token field: a comma and Enter add, Backspace takes the last out, a suggestion, Remove keeping the focus in the input.
$tags = ['go']; $langs = ['typescript', 'python', 'rust'];
$h = new Headless(function (Context $c) use (&$tags, $langs): void {
    $c->column()->alignItems(Node::START);
      $c->tokenField($tags, $langs, 360)->label('Tags');
    $c->end();
}, 500, 400);
$h->frame();
$h->clickLabel('Tags');
$h->type('zig,'); $h->type('lua'); $h->enter();
same(['go', 'zig', 'lua'], $tags, 'typed tokens');
$h->key(Key::DELETE, 'deleteBackward:');
same(['go', 'zig'], $tags, 'Backspace');
$h->type('ty');
check(cbHas($h, 'typescript'), 'ty suggests');
$h->down(); $h->enter();
same(['go', 'zig', 'typescript'], $tags, 'a suggestion');
$h->clickLabel('Remove go');
same(['zig', 'typescript'], $tags, 'Remove go');
check($h->focused()?->kind === Node::INPUT, 'the input keeps the focus');

// A long popup scrolled by the wheel past the option the pointer highlighted; Down brings the next into view.
$many = array_map(fn(int $i) => sprintf('Option %02d', $i), range(0, 59)); $choice = '';
$h = new Headless(function (Context $c) use (&$choice, $many): void {
    $c->column()->alignItems(Node::START);
      $c->combobox($choice, $many, 200)->label('Pick');
    $c->end();
}, 400, 600);
$h->frame();
$h->clickOn($h->byLabel('Pick'));
$first = $h->rect($h->byText('Option 00'));
[$x, $y] = [$first[0] + $first[2] / 2, $first[1] + $first[3] / 2];
$h->hover($x, $y);
for ($k = 0; $k < 30; $k++) { $h->input->push(['scroll', $x, $y, 0, -120]); $h->frame(); }
$last = $h->rect($h->byText('Option 59'));
check($last[3] > 0 && $last[1] <= $first[1] + 400, 'scrolled to the end, the last option shows: ' . json_encode([$last, $first]));
$h->down();
$r = $h->rect($h->byText('Option 01'));
check($r[1] >= $first[1] - $first[3] && $r[1] <= $first[1] + 200, 'Down highlights Option 01, in view: ' . json_encode([$r, $first]));

// A select whose popup scrolls: the option the keys highlight comes into view.
$sizes = array_map(fn(int $i) => sprintf('Size %02d', $i), range(0, 39)); $size = $sizes[0]; $popupScroll = new \MyPHP\UI\ScrollState;
$h = new Headless(function (Context $c) use (&$size, $sizes, $popupScroll): void {
    $c->column()->alignItems(Node::START);
      $sel = $c->selectBase($size);
      $sel->trigger->label('Size'); $c->text($size); $c->end();
      $sel->popup(function (Node $panel) use ($c, $sel, $sizes, $popupScroll): void {
          $c->column()->scroll($popupScroll)->maxHeight(120);
            foreach ($sizes as $s) { $sel->item($s)->height(20); $c->text($s); $c->end(); }
          $c->end();
      });
    $c->end();
}, 400, 600);
$h->frame();
$h->clickLabel('Size');
$top = $h->rect($h->byText('Size 01'));
$h->end();
$r = $h->rect($h->byText('Size 39'));
check($r[1] >= $top[1] - 20 && $r[1] + $r[3] <= $top[1] - 20 + 120, 'End highlights Size 39, in view: ' . json_encode([$r, $top]));

// A field's box gives autoFocus() to its input, and shows its placeholder (mygo's 8006903, f27a125): typing reaches it at once.
$picked = ''; $tags = [];
$h = new Headless(function (Context $c) use (&$picked, &$tags): void {
    $c->column(8, 8);
      $c->combobox($picked, ['Red', 'Green'], 200, 'Pick a color')->label('Color')->autoFocus();
      $c->tokenField($tags, [], 200, 'Add a tag')->label('Tags');
    $c->end();
}, 400, 200);
$h->frame();
$f = $h->focused();
check($f !== null && $f->kind === \MyPHP\UI\Node::INPUT && $f->text === 'Pick a color', 'autoFocus on a combobox: its input focused, its placeholder shown: ' . ($f?->text ?? 'none'));
$h->type('Gr'); $h->frame();
same('Gr', $f->edit->text, 'typing reaches it');
$inputs = []; $walk = function (\MyPHP\UI\Node $n) use (&$walk, &$inputs) { if ($n->kind === \MyPHP\UI\Node::INPUT) { $inputs[] = $n->text; } foreach ($n->kids as $k) { $walk($k); } };
$walk($h->root);
same(['Pick a color', 'Add a tag'], $inputs, 'each input with its placeholder');
