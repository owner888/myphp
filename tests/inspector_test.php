<?php
// The inspector of native UI (mygo's inspector_test.go and
// inspector_direction_test.go): it opens and closes with its keys, docked
// at the right of the content, which gets a narrower window; its tree
// follows the content; the picker chooses a node in the window, whose
// styles, box model and properties it shows; the tree's keys, its find
// bar and breadcrumbs; the tabs of performance and issues; the panel's
// edge resizes it; its palette follows the appearance; frames settle; the
// right-to-left toggle.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Direction, Inspector, InspectorDetails, InspectorPanel, Key, Node, Theme};

/** A headless window of $view whose inspector can open, and the width the view had in its last frame. @return array{0: Headless, 1: \stdClass} */
function inspected(\Closure $view, int $w = 1000, int $h = 600): array
{
    $seen = new \stdClass; $seen->width = 0.0; $seen->dir = null;
    $t = new Headless(function (Context $c) use ($view, $seen) {
        $seen->width = $c->width; $seen->dir = $c->direction();
        $view($c);
    }, $w, $h);
    $t->rt->inspector = new Inspector();
    return [$t, $seen];
}

/** A key with $mods, as the view sends it: its code, and $chars. */
function inspKey(Headless $t, int $code, int $mods = 0, string $chars = ''): void
{
    $t->input->push(['key', $chars, $code, $mods]);
    $t->frame();
}

/** Whether a text the tree shows holds $s. */
function inspHas(Headless $t, string $s): bool
{
    foreach ($t->texts() as $x) { if (str_contains($x, $s)) { return true; } }
    return false;
}

/** The first node labeled $label. */
function inspLabeled(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = inspLabeled($k, $label)) !== null) { return $f; } }
    return null;
}

/** Clicks the node labeled $label. */
function inspClick(Headless $t, string $label): void
{
    $n = inspLabeled($t->root, $label) ?? throw new \RuntimeException("nothing labeled $label");
    $t->clickOn($n);
}

// It opens and closes, docked at the right of the content.
[$t, $seen] = inspected(function (Context $c) {
    $c->column(20); $c->text('Hello'); $c->end();
});
$t->frame();
inspKey($t, Key::F12);
$in = $t->rt->inspector;
check($in->open, 'F12 opens the inspector');
same(1000.0 - Inspector::WIDTH, $seen->width, 'the content is narrower beside it');
foreach (['Elements', 'Performance', 'Issues', '<Root>', '<Text>Hello</Text>', '</Column>'] as $s) {
    check(inspHas($t, $s), "the inspector shows $s");
}
$pick = inspLabeled($t->root, InspectorPanel::PICK);
check($pick !== null && $t->rect($pick)[0] >= 1000 - Inspector::WIDTH, 'the picker is in the panel, beside the content');
inspKey($t, Key::F12);
check(!$in->open && $seen->width == 1000 && !inspHas($t, '<Root>'), 'F12 closes it');
inspKey($t, Key::I, Key::OPTION | Key::COMMAND);
check($in->open, 'Alt+Cmd+I opens it');
inspKey($t, Key::I, Key::OPTION | Key::COMMAND);
inspKey($t, Key::C, Key::SHIFT | Key::COMMAND);
check($in->open && $in->picking, 'Shift+Cmd+C opens it picking');
inspKey($t, Key::ESCAPE);
check(!$in->picking, 'Escape stops picking');
inspClick($t, 'Close');
check(!$in->open, 'Close closes it');
// Without developer tools, the keys go to the view.
$f12 = false;
[$t, $seen] = inspected(function (Context $c) use (&$f12) { if ($c->shortcut(Key::F12)) { $f12 = true; } });
$t->rt->inspector = null;
inspKey($t, Key::F12);
check($f12, 'without developer tools, F12 is the view\'s');

// The tree follows the content.
$count = 0;
[$t] = inspected(function (Context $c) use (&$count) {
    $c->column(20, 8);
      $c->text("Count $count");
      if ($c->clicked($c->button('Add'))) { $count++; }
    $c->end();
});
$t->frame();
inspKey($t, Key::F12);
check(inspHas($t, '<Text>Count 0</Text>'), 'the tree shows the text');
$t->clickText('Add');
check(inspHas($t, '<Text>Count 1</Text>') && !inspHas($t, 'Count 0'), 'and follows it');

// The picker chooses a node in the window, which its tabs describe.
$clicks = 0;
[$t] = inspected(function (Context $c) use (&$clicks) {
    $c->column(20)->fontSize(14);
      if ($c->clicked($c->button('Press'))) { $clicks++; }
    $c->end();
});
$t->frame();
inspKey($t, Key::F12);
$in = $t->rt->inspector;
check(inspHas($t, 'Choose an element in the tree, or pick one in the window.'), 'a hint before a node is chosen');
inspClick($t, InspectorPanel::PICK);
check($in->picking, 'the picker picks');
$press = $t->byText('Press');
[$x, $y] = $t->middle($press);
$t->hover($x, $y);
check($in->hoverElem !== null && $in->hoverElem->id === $press->id, 'picking follows the pointer');
$list = $t->paint();
$tinted = false;
for ($i = 0; $i < $list->n; $i++) { // the button's content box, in blue over it
    $k = $i * 16;
    if (abs($list->buf[$k] - ($press->x + $press->padL())) < 0.01 && abs($list->buf[$k + 2] - ($press->w - $press->padL() - $press->padR())) < 0.01) { $tinted = true; }
}
check($tinted, 'the node picked is highlighted');
$t->click($x, $y);
check($clicks === 0, 'the click that picked does not reach the button');
check(!$in->picking && $in->selected === $press->id, 'the click picks');
foreach (['Button {', 'Inherited from', 'font-size: 14px;', '}'] as $s) { check(inspHas($t, $s), "the Styles tab shows $s"); }
check(inspHas($t, 'inspector_test.php:'), 'and where the node was built');
$t->clickText('Computed');
foreach (['margin', 'border', 'padding', 'display', 'width'] as $s) { check(inspHas($t, $s), "the Computed tab shows $s"); }
$t->clickText('Properties');
foreach (['Accessibility', 'keyboard-focusable: true', 'role: button'] as $s) { check(inspHas($t, $s), "the Properties tab shows $s"); }
$t->clickText('Press');
same(1, $clicks, 'after picking, the button takes clicks');

// The tree: keys as attributes, nodes deep in closed, the keys move and open, the find bar, the breadcrumbs.
[$t] = inspected(function (Context $c) {
    $c->column(20);
      $c->key('toolbar')->row();
        $c->row(); $c->text('Deep'); $c->end();
      $c->end();
      $c->text('Inside');
    $c->end();
});
$t->frame();
inspKey($t, Key::F12);
$in = $t->rt->inspector;
check(inspHas($t, '<Row key="toolbar">') && inspHas($t, '<Row>…</Row>') && !inspHas($t, '<Text>Deep</Text>'), 'nodes below the third level start closed: ' . json_encode($t->texts()));
$t->clickText('<Text>Inside</Text>');
$inside = $in->selected;
check($inside !== null && inspHas($t, 'Text {'), 'a click in the tree chooses the text');
foreach (['Root', 'Column', 'Text'] as $s) { check($t->byText($s) !== null, "the breadcrumbs show $s"); }
[$x, $y] = $t->middle($t->byText('<Text>Inside</Text>'));
$t->hover($x, $y);
check($in->hoverElem?->id === $inside, 'hovering a row highlights its node');
$t->up();
$sel = $in->nodes[$in->nodeOf($in->selected)] ?? null;
same('toolbar', $sel['keyText'] ?? null, 'Up goes to the closing tag of the row above, which chooses it');
$t->down();
$sel = $in->nodes[$in->nodeOf($in->selected)] ?? null;
check(($sel['name'] ?? '') === 'Row' && $sel['keyText'] === '', 'Down goes to the row inside it');
$t->right();
check(inspHas($t, '<Text>Deep</Text>'), 'Right opens it');
$t->left();
check(!inspHas($t, '<Text>Deep</Text>'), 'Left closes it');
$t->left();
same('toolbar', $in->nodes[$in->nodeOf($in->selected)]['keyText'] ?? null, 'Left goes to the node around it');
$t->left();
check(inspHas($t, '<Row key="toolbar">…</Row>'), 'and closes that');
inspKey($t, Key::F, Key::COMMAND, 'f');
check($in->finding, 'Cmd+F opens the find bar');
$t->type('deep');
same('Deep', $in->nodes[$in->nodeOf($in->selected)]['text'] ?? null, 'finding chooses the node found');
check(inspHas($t, '<Text>Deep</Text>'), 'and shows it');

// The tabs: issues, performance; the edge resizes the panel.
$log = ini_get('error_log');
ini_set('error_log', '/dev/null');
[$t, $seen] = inspected(function (Context $c) {
    $c->key('twice')->row(); $c->end();
    $c->key('twice')->row(); $c->end();
});
$t->rt->strict = false;
$t->frame();
inspKey($t, Key::F12);
$in = $t->rt->inspector;
check(inspHas($t, 'Issues 1'), 'the toolbar counts the issue');
$t->clickText('Issues 1');
check(inspHas($t, "the key 'twice'"), 'the Issues tab lists it');
ini_set('error_log', (string)$log);
$t->clickText('Performance');
foreach (['Last frame', 'Average', 'Slowest', 'Build', 'Layout', 'Paint', '60 Hz', '16.7 ms, a frame at 60 Hz'] as $s) { check(inspHas($t, $s), "the Performance tab shows $s"); }
$in->hz = 120;
$t->frame();
check(inspHas($t, '120 Hz') && inspHas($t, '8.3 ms, a frame at 120 Hz'), 'a frame\'s budget is the display\'s');
check($in->frames > 0, 'frames noted');
$x = 1000 - Inspector::WIDTH;
$t->press($x, 300);
$t->drag($x - 100, 300);
$t->release($x - 100, 300);
same((float)($x - 100), $seen->width, 'dragging the edge resizes the panel');

// The panel follows the appearance.
[$t] = inspected(fn(Context $c) => $c->text('Hi'));
$t->theme = Theme::light();
inspKey($t, Key::F12);
same(0xffffff, inspLabeled($t->root, 'Developer tools')?->color, 'light');
$t->theme = Theme::dark();
$t->frame();
same(0x202124, inspLabeled($t->root, 'Developer tools')?->color, 'and dark');

// It asks for frames only while what it shows changes.
[$t] = inspected(function (Context $c) {
    $c->column(20, 8);
      $c->text('Title')->bold();
      for ($i = 0; $i < 3; $i++) { $c->key($i)->row(); $c->button("Item $i"); $c->end(); }
    $c->end();
});
$t->frame();
inspKey($t, Key::F12);
$settled = function (string $what) use ($t): void { $t->frame(); check(!$t->rt->needsFrame, "$what: frames settle"); };
$settled('open');
$t->clickText('<Text>Title</Text>');
$settled('chosen');
foreach (['Computed', 'Properties', 'Styles', 'Performance', 'Issues', 'Elements'] as $tab) {
    $t->clickText($tab);
    $settled($tab);
}
[$x, $y] = $t->middle($t->byText('<Text>Title</Text>'));
$t->hover($x + 5, $y);
$settled('hovering the tree');
inspClick($t, InspectorPanel::PICK);
$t->hover(100, 30);
$settled('picking');

// The toggle lays the window out right to left, and back; the panel stays left to right.
[$t, $seen] = inspected(function (Context $c) { $c->row(0, 0, 1); $c->text('Content'); $c->end(); });
$t->frame();
inspKey($t, Key::F12);
$t->clickText('RTL');
same(Direction::RightToLeft, $seen->dir, 'the toggle lays the window out right to left');
check(inspLabeled($t->root, 'Developer tools')?->rtl === false, 'the panel stays left to right');
$t->clickText('RTL');
same(Direction::LeftToRight, $seen->dir, 'and back');

// Styles as CSS: a shadow, a border, a transition.
[$t] = inspected(function (Context $c) { $c->column(8, 0, 0, 0x336699)->shadow(0, 4, 12, 0, 0x000000, 0.18)->borderWidth(2)->borderColor(0)->label('box'); $c->end(); });
$t->frame();
inspKey($t, Key::F12);
$in = $t->rt->inspector;
$in->choose(inspLabeled($t->root, 'box')->id);
$t->frame();
$decls = array_column($in->sel['rules'][0]['decls'], 1, 0);
same(['0 4px 12px 0 #0000002e', '2px solid #000000', '#336699', '8px'], [$decls['box-shadow'] ?? null, $decls['border'] ?? null, $decls['background-color'] ?? null, $decls['padding'] ?? null], 'a shadow, a border, a background and padding');
same('8px 24px', InspectorDetails::edges([8.0, 24.0, 8.0, 24.0]), 'edges as CSS writes them');
