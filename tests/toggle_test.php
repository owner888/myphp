<?php
// Focus groups, toggles and toolbars (mygo's toggle_test.go): a toolbar
// one stop of Tab that the arrows move in and Tab comes back to where it
// left; what inside it takes the arrows itself; radio groups, toggles,
// segmented controls and tabs; a toolbar's overflow menu; what assistive
// technology sees.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Node, Role, TextState};

/** Which of $labels the node with the focus shows, '' for none. */
function tgFocused(Headless $h, array $labels): string
{
    $f = $h->focused();
    if ($f === null) { return ''; }
    foreach ($labels as $l) { if ($f->label === $l || in_array($l, $h->texts($f), true)) { return $l; } }
    return '';
}

/** Tab (or Shift+Tab) $n times, where the focus went each time. @return list<string> */
function tgTabs(Headless $h, bool $back, int $n, array $labels): array
{
    $got = [];
    for ($i = 0; $i < $n; $i++) { $h->tab($back); $got[] = tgFocused($h, $labels); }
    return $got;
}

/** The node of the access tree with $role named $label. */
function tgNode(Headless $h, int $role, string $label): ?AccessNode
{
    foreach ($h->rt->accessTree?->nodes ?? [] as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
}

// A toolbar is one stop of Tab; the arrows, Home and End move in it; Tab leaves it and comes back where it left.
$bold = false; $labels = ['Before', 'Cut', 'Bold', 'Paste', 'After'];
$h = new Headless(function (Context $c) use (&$bold): void {
    $c->column(10, 8);
      $c->button('Before');
      $c->toolbar(function () use ($c, &$bold): void { $c->button('Cut'); $c->toggle($bold, 'Bold'); $c->button('Paste'); })->label('Edit');
      $c->button('After');
    $c->end();
}, 500, 300);
$h->frame();
same(['Before', 'Cut', 'After', 'Before'], tgTabs($h, false, 4, $labels), 'Tab');
$h->tab();
$arrows = [];
foreach (['right', 'right', 'right', 'left', 'end', 'home', 'end'] as $k) { $h->$k(); $arrows[] = tgFocused($h, $labels); }
same(['Bold', 'Paste', 'Cut', 'Paste', 'Paste', 'Cut', 'Paste'], $arrows, 'the arrows, Home and End');
same(['After'], tgTabs($h, false, 1, $labels), 'Tab leaves the toolbar');
same(['Paste'], tgTabs($h, true, 1, $labels), 'Shift+Tab comes back where it left');
same(['Before'], tgTabs($h, true, 1, $labels), 'and out of it');

// What takes the arrows itself keeps them: the caret in an input, a slider's value.
$name = new TextState('abc'); $volume = 0.5;
$h = new Headless(function (Context $c) use ($name, &$volume): void {
    $c->toolbar(function () use ($c, $name, &$volume): void {
        $c->button('Cut');
        $c->input($name, '', 100)->label('Name');
        $c->slider($volume, 0, 1, 100)->label('Volume');
        $c->button('Paste');
    });
}, 600, 200);
$h->frame();
$h->tab(); $h->right();
same('Name', $h->focused()?->label, 'Right from Cut goes to the input');
$h->left();
same('Name', $h->focused()?->label, 'Left in the input moves its caret');
$h->rt->focus = $h->byLabel('Volume')->id; $h->frame();
$h->right();
check($h->focused()?->label === 'Volume' && $volume > 0.5, "Right on the slider moves it: $volume");

// A radio group: Tab to the one chosen; the arrows choose, round; Tab out; its radio buttons under it.
$size = 'Medium'; $labels = ['Before', 'Small', 'Medium', 'Large', 'After'];
$h = new Headless(function (Context $c) use (&$size): void {
    $c->column(10, 8);
      $c->button('Before');
      $c->radioGroup(function () use ($c, &$size): void { foreach (['Small', 'Medium', 'Large'] as $s) { $c->radio($s, $size, $s); } })->label('Size');
      $c->button('After');
    $c->end();
}, 400, 300);
$h->frame();
same(['Before', 'Medium'], tgTabs($h, false, 2, $labels), 'Tab goes to the one chosen');
$h->down();
check($size === 'Large' && tgFocused($h, $labels) === 'Large', 'Down chooses the next');
$h->down(); $h->right();
same('Medium', $size, 'round to the first, then Right');
same(['After'], tgTabs($h, false, 1, $labels), 'Tab out');
$h->input->push(['accessOn']); $h->frame();
$g = tgNode($h, Role::RADIO_GROUP, 'Size');
same(3, count(array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::RADIO && $g !== null && $h->rt->accessTree->nodes[$n->parent]->id === $g->id)), 'its radio buttons under it');

// A toggle: a click presses it, which it says; Space lets it go.
$bold = false; $changes = 0;
$h = new Headless(function (Context $c) use (&$bold, &$changes): void { if ($c->changed($c->toggle($bold, 'Bold'))) { $changes++; } }, 300, 200);
$h->frame();
$h->clickText('Bold');
check($bold && $changes === 1, 'a click presses it');
$h->input->push(['accessOn']); $h->frame();
check((tgNode($h, Role::TOGGLE_BUTTON, 'Bold')?->states & AccessNode::CHECKED) !== 0, 'pressed, it says so');
$h->space();
check(!$bold, 'Space lets it go');

// A segmented control: a click, Right; a radio group of radio buttons.
$view = 0; $changes = 0;
$h = new Headless(function (Context $c) use (&$view, &$changes): void { if ($c->changed($c->segmented(['List', 'Grid', 'Columns'], $view)->label('View'))) { $changes++; } }, 400, 200);
$h->frame();
$h->clickText('Grid');
check($view === 1 && $changes === 1, 'a click on Grid');
$h->right();
check($view === 2 && tgFocused($h, ['Columns']) === 'Columns', 'Right chooses Columns');
$h->input->push(['accessOn']); $h->frame();
check(tgNode($h, Role::RADIO_GROUP, 'View') !== null && (tgNode($h, Role::RADIO, 'Columns')?->states & AccessNode::CHECKED) !== 0, 'a radio group, Columns checked');

// Tabs are one stop, at the tab chosen.
$tab = 1; $labels = ['Before', 'One', 'Two', 'Three', 'After'];
$h = new Headless(function (Context $c) use (&$tab): void {
    $c->column(0, 8); $c->button('Before'); $c->tabs(['One', 'Two', 'Three'], $tab); $c->button('After'); $c->end();
}, 400, 200);
$h->frame();
same(['Before', 'Two', 'After'], tgTabs($h, false, 3, $labels), 'Tab: the tab chosen, one stop');

// A toolbar's overflow: what does not fit goes into a menu, from the last; choosing does what a click does, a toggle checked; with room, all show.
$clicked = ''; $italic = false; $names = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
$h = new Headless(function (Context $c) use (&$clicked, &$italic, $names): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->toolbar(function () use ($c, &$clicked, &$italic, $names): void {
          foreach ($names as $n) { if ($c->clicked($c->button($n))) { $clicked = $n; } }
          $c->toggle($italic, 'Italic');
      });
    $c->end();
}, 260, 100);
$h->frame(); $h->frame();
$shown = array_values(array_filter([...$names, 'Italic'], fn($n) => $h->byText($n) !== null));
check($shown !== [] && count($shown) < 6 && $shown[0] === 'Alpha', 'some show: ' . json_encode($shown));
check($h->byLabel('More') !== null, 'and the overflow button');
$h->clickLabel('More');
$menu = $h->menuLabels() ?? [];
check($menu !== [] && end($menu) === 'Italic' && !in_array($shown[0], $menu, true), 'the menu has the rest: ' . json_encode($menu));
check($h->chooseMenuItem('Echo') && $clicked === 'Echo', 'choosing Echo clicks it');
$h->clickLabel('More');
$h->chooseMenuItem('Italic');
check($italic, 'choosing Italic presses it');
$h->clickLabel('More');
$items = $h->menu['items'] ?? [];
check($items !== [] && end($items)->checked, 'Italic checked in the menu once pressed');
$h->closeMenu();
$h->w = 900; $h->frame(); $h->frame();
check(array_filter($names, fn($n) => $h->byText($n) === null) === [] && $h->byLabel('More') === null, 'with room, all show and the button goes');

// What assistive technology sees: the toolbar holds the group, which holds the toggle, pressed.
$bold = true;
$h = new Headless(function (Context $c) use (&$bold): void {
    $c->toolbar(function () use ($c, &$bold): void { $c->toggleGroup(function () use ($c, &$bold): void { $c->toggle($bold, 'Bold'); })->label('Style'); })->label('Format');
}, 400, 100);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$tb = tgNode($h, Role::TOOLBAR, 'Format'); $b = tgNode($h, Role::TOGGLE_BUTTON, 'Bold');
check($b !== null && ($b->states & AccessNode::CHECKED) !== 0, 'Bold, pressed');
$group = $b === null ? null : $h->rt->accessTree->nodes[$b->parent];
check($group?->label === 'Style' && $tb !== null && $h->rt->accessTree->nodes[$group->parent]->id === $tb->id, 'in its group, in the toolbar');

// Tab enters a toolbar at its first control, not at a toggle that is on.
$bold = true;
$h = new Headless(function (Context $c) use (&$bold): void { $c->toolbar(function () use ($c, &$bold): void { $c->button('Cut'); $c->toggle($bold, 'Bold'); }); }, 400, 100);
$h->frame();
$h->tab();
same('Cut', tgFocused($h, ['Cut', 'Bold']), 'Tab enters at the first');
