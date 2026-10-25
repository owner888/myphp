<?php
// More of mygo's outline_test.go (TestOutlineIndent, TestOutlineBuildsWhatShows,
// TestOutlineExpandsForAssistiveTechnology): the width a level adds, the
// rows built of a hundred thousand, and opening and closing by assistive
// technology, an outline's and a tree's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, OutlineState, Role, Theme};

$olFiles = ['src' => ['src/ui', 'src/main.go'], 'src/ui' => ['src/ui/list.go', 'src/ui/tree.go'], 'docs' => [], 'README.md' => null];
$olKids = fn(int|string $p): ?array => $olFiles[$p] ?? null;
$olBase = fn(string $p): string => substr($p, (int)strrpos('/' . $p, '/'));

// The width each level adds: the theme's Space(4) at 0; a negative one throws.
foreach ([0.0, 6.0] as $indent) {
    $s = new OutlineState(); $s->indent = $indent; $s->open->add('src'); $s->open->add('src/ui');
    $h = new Headless(function (Context $c) use ($s, $olKids, $olBase): void {
        $c->column(0, 0, 1);
          $c->outline($s, ['src'], $olKids, function ($p) use ($c, $olBase): void { $c->text($olBase($p)); })->grow = 1;
        $c->end();
    }, 400, 400);
    $h->frame();
    $want = $indent == 0 ? Theme::light()->space(4) : $indent;
    same($want, $h->rect($h->byText('list.go'))[0] - $h->rect($h->byText('ui'))[0], "indent $indent: the child right of its parent");
}
$s = new OutlineState(); $s->indent = -1;
$threw = false;
try { (new Headless(function (Context $c) use ($s, $olKids): void { $c->outline($s, ['src'], $olKids, function ($p): void {}); }, 400, 400))->frame(); }
catch (\InvalidArgumentException|\LogicException $e) { $threw = true; }
check($threw, 'a negative indent throws');

// A hundred thousand children: the rows that show are built; End chooses the last; the choice follows its item.
$s = new OutlineState(); $s->list->selected = -1; $n = 100000; $big = range(1, $n); $built = 0;
$h = new Headless(function (Context $c) use ($s, $big, &$built): void {
    $c->column(0, 0, 1);
      $c->outline($s, [0], fn($i) => $i === 0 ? $big : null, function ($i) use ($c, &$built): void { $built++; $c->text("Item $i"); })->grow = 1;
    $c->end();
}, 400, 400);
$h->rt->reduceMotion = true;
$h->frame();
$s->open->add(0); $h->frame();
$built = 0; $h->frame(1); // one frame (frame() runs two)
check($s->rows() === $n + 1 && $built > 0 && $built <= 40, "{$s->rows()} rows, built $built");
$h->clickText('Item 3');
$h->end();
check($s->list->selected === $n && $h->byText("Item $n") !== null, "End chose {$s->list->selected}");
$h->home(); $h->down(); $h->down();
same(2, $s->item($s->list->selected), 'Home and Down twice');

// Assistive technology opens and closes an outline's rows without choosing them, and a tree's items; a file opens nothing.
$s = new OutlineState(); $s->list->selected = -1; $open = false;
$h = new Headless(function (Context $c) use ($s, $olKids, $olBase, &$open): void {
    $c->column(0, 0, 1);
      $c->outline($s, ['src', 'README.md'], $olKids, function ($p) use ($c, $olBase): void { $c->text($olBase($p)); })->grow = 1;
      $c->tree(function () use ($c, &$open): void { $c->treeItem('lib', $open, function () use ($c): void { $none = null; $c->treeItem('util.go', $none, null); }); });
    $c->end();
}, 400, 400);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$item = function (string $name) use ($h): ?AccessNode {
    foreach ($h->rt->accessTree->nodes as $a) { if ($a->role === Role::TREE_ITEM && $a->label === $name) { return $a; } }
    return null;
};
$act = function (string $name, int $action) use ($h, $item): void {
    $a = $item($name);
    check($a !== null && ($a->actions & AccessNode::ACTION_EXPAND) !== 0, "$name can open");
    $h->input->push(['access', $a?->id ?? 0, $action]); $h->frame();
};
check(($item('README.md')?->actions & AccessNode::ACTION_EXPAND) === 0, 'a file cannot open');
$act('src', AccessNode::EXPAND);
check($s->open->has('src') && $s->list->selected === -1 && $h->byText('main.go') !== null, 'src opened, not chosen');
$act('src', AccessNode::COLLAPSE);
check(!$s->open->has('src'), 'and closed');
$act('lib', AccessNode::EXPAND);
check($open && $h->byText('util.go') !== null, "a tree's item opened");
