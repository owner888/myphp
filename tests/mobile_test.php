<?php
// The widgets of a phone (ContextMobile, not mygo's): the safe area and
// the keyboard a safeArea keeps out of; iOS 26's tab bar, a capsule over
// the safe area's bottom; a navigation bar 44 high under its top with the
// router's way back; a sheet rising from the bottom that Escape and a tap
// outside close; an action sheet; inset grouped sections and cells; swipe
// actions; the interactive pop gesture; pull to refresh; large titles.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, Router, SVG};

$icon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20"/></svg>');

// safeArea: padded by the insets, the keyboard taking the bottom's place when higher.
$inner = null;
$h = new Headless(function (Context $c) use (&$inner): void {
    $c->safeArea = [50.0, 0.0, 34.0, 0.0];
    $c->safeArea(function () use ($c, &$inner): void { $inner = $c->column(0, 0, 1)->alignSelf(Node::STRETCH); $c->text('inside'); $c->end(); });
}, 390, 844);
$h->frame();
same([0.0, 50.0, 390.0, 760.0], $h->rect($inner), 'safeArea keeps the content below the status bar and above the home indicator');
$h = new Headless(function (Context $c) use (&$inner): void {
    $c->safeArea = [50.0, 0.0, 34.0, 0.0]; $c->keyboard = 300.0;
    $c->safeArea(function () use ($c, &$inner): void { $inner = $c->column(0, 0, 1)->alignSelf(Node::STRETCH); $c->text('inside'); $c->end(); });
}, 390, 844);
$h->frame();
same([0.0, 50.0, 390.0, 494.0], $h->rect($inner), 'the keyboard up, the content ends above it');

// tabBar: a capsule in a band 8 + 56 + the safe area's bottom high, the items sharing its width; a tap chooses, changed() reports it.
$index = 0; $changed = 0; $bar = null;
$h = new Headless(function (Context $c) use (&$index, &$changed, &$bar, $icon): void {
    $c->safeArea = [0.0, 0.0, 34.0, 0.0];
    $c->column(0, 0, 1)->fill();
      $c->text('page ' . $index);
      $c->spacer();
      $bar = $c->tabBar([[$icon, 'Home'], [$icon, 'Search'], [$icon, 'Me']], $index);
      if ($c->changed($bar)) { $changed++; }
    $c->end();
}, 390, 844);
$h->frame();
$r = $h->rect($bar);
same([0.0, 746.0, 390.0, 98.0], $r, 'the tab bar\'s band: the full width, 8 + 56 + 34 high at the bottom');
$ri = $h->rect($h->byText('Search')); $rm = $h->rect($h->byText('Me'));
closeFloat(195.0, $ri[0] + $ri[2] / 2, 'the three items share the width: the second centers in the middle');
closeFloat(311.67, $rm[0] + $rm[2] / 2, 'the third in the last third of the capsule, 16 in from the sides and padded 4');
$h->clickText('Search');
same([1, 1], [$index, $changed], 'a tap chooses the tab and changed() reports it');
check($h->byText('page 1') !== null, 'the page follows the choice');
$h->clickText('Search');
same(1, $changed, 'the tab chosen again changes nothing');

// navigationBar: 44 high under the safe area's top, the title; with a router that can go back, the previous page's title leads and goes back.
$router = new Router('/home'); $nav = null;
$h = new Headless(function (Context $c) use (&$router, &$nav): void {
    $c->safeArea = [50.0, 0.0, 0.0, 0.0];
    $c->column(0, 0, 1)->fill();
      $nav = $c->navigationBar($router->title(), $router, function () use ($c): void { $c->text('Edit', 17, $c->theme->accent); });
      $router->view($c, function (\MyPHP\UI\Route $r) use ($c): void {
          if ($r->match('/home')) { $r->title('Home'); $c->text('the home'); }
          elseif ($r->match('/note')) { $r->title('Note'); $c->text('the note'); }
      });
    $c->end();
}, 390, 844);
$h->rt->touch = true;
$h->frame();
$r = $h->rect($nav);
same([0.0, 0.0, 390.0, 94.0], $r, 'the navigation bar: 44 + 50 high at the top');
check($h->byText('Home') !== null && $h->byText('Edit') !== null, 'the title and the trailing item show');
check($h->byLabel('Back') === null, 'no way back at the first page');
$router->push('/note');
$h->frame();
check($h->byText('Note') !== null && $h->byLabel('Back') !== null && $h->byText('Home') === null, 'pushed: the title, and the way back a chevron alone, as iOS 26\'s');
same([44.0, 44.0], array_slice($h->rect($h->byLabel('Back')), 2), 'the way back is a 44-point circle');
$rt = $h->rect($h->byText('Note'));
closeFloat(195.0, $rt[0] + $rt[2] / 2, 'the title centers in the bar');
$h->clickOn($h->byLabel('Back'));
$h->frame();
check(!$router->canGoBack() && $h->byText('the note') === null, 'a tap on the way back goes back');
$h->rt->touch = false; $router->push('/note'); $h->frame(2);
same([32.0, 32.0], array_slice($h->rect($h->byLabel('Back')), 2), 'not touched (a Mac, a PC), the bar is a window\'s toolbar: the way back 32 high');
same(52.0 + 50.0, $h->rect($nav)[3], 'and the bar 52 high under the safe area');
$router->back(); $h->frame(2);

// sheet: rises from the bottom, keeps out of the safe area's bottom; Escape and a tap outside close it, dismissed() reports.
$open = false; $dismissed = 0; $panelText = null;
$h = poseTester(function (Context $c) use (&$open, &$dismissed, &$panelText): void {
    $c->windowSafeArea = $c->safeArea = [0.0, 0.0, 34.0, 0.0];
    if ($c->clicked($c->button('Show'))) { $open = true; }
    $s = $c->sheet($open, function () use ($c, &$panelText): void { $panelText = $c->text('in the sheet'); });
    if ($s !== null && $c->dismissed($s)) { $dismissed++; }
}, $now, 390, 844);
$h->clickText('Show');
$now += 1_000_000_000; $h->frame();
check($open && $h->byText('in the sheet') !== null, 'the sheet shows');
$r = $h->rect($panelText);
closeFloat(16.0 + 34.0, 844 - $r[1] - $r[3], 'its content ends 16 above the safe area');
$h->escape();
same([false, 1], [$open, $dismissed], 'Escape closes it, dismissed() reports');
$h->clickText('Show');
$now += 1_000_000_000; $h->frame();
$h->click(195, 100);
same([false, 2], [$open, $dismissed], 'a tap outside closes it');

// actionSheet: the actions, a destructive one in the danger color, the cancel button; what a tap returns.
$open = false; $got = [];
$h = poseTester(function (Context $c) use (&$open, &$got): void {
    if ($c->clicked($c->button('More'))) { $open = true; }
    $r = $c->actionSheet($open, 'Note', 'Share', 'Rename', '!Delete');
    if ($r !== -1) { $got[] = $r; }
}, $now, 390, 844);
$h->clickText('More');
$now += 1_000_000_000; $h->frame();
check($open && $h->byText('Share') !== null && $h->byText('Delete') !== null && $h->byText('Cancel') !== null, 'the action sheet shows its actions and Cancel');
same($h->theme->danger, $h->byText('Delete')->fg, 'a "!" action is destructive, in the danger color');
$h->clickText('Rename');
same([1], $got, 'the action tapped is returned, and the sheet closes');
check(!$open && $h->byText('Share') === null, 'closed');
$h->clickText('More');
$now += 1_000_000_000; $h->frame();
$h->clickText('Cancel');
same([1, Context::SHEET_DISMISSED], $got, 'Cancel returns SHEET_DISMISSED');
$h->clickText('More');
$now += 1_000_000_000; $h->frame();
$h->escape();
same([1, Context::SHEET_DISMISSED, Context::SHEET_DISMISSED], $got, 'so does Escape');

// tabView: the tabs across the bottom on a phone, across the top on a tablet upright, a sidebar on its side and on the desktop; the page follows.
foreach ([[390, 844, true, Context::TABS_BOTTOM], [820, 1180, true, Context::TABS_TOP], [1180, 820, true, Context::TABS_SIDEBAR], [1000, 700, false, Context::TABS_SIDEBAR], [932, 430, true, Context::TABS_BOTTOM]] as [$w, $hh, $touch, $want]) {
    $tab = 0; $where = ''; $changed = 0;
    $h = new Headless(function (Context $c) use (&$tab, &$where, &$changed, $icon): void {
        $v = $c->tabView([[$icon, 'Home'], [$icon, 'Search'], [$icon, 'Me']], $tab, function (int $i, string $placement) use ($c, &$where): void {
            $where = $placement;
            $c->text("page $i");
        });
        if ($c->changed($v)) { $changed++; }
    }, $w, $hh);
    $h->rt->touch = $touch;
    $h->frame();
    same($want, $where, "{$w}x{$hh}" . ($touch ? ' touch' : ' mouse') . ": the tabs go $want");
    check($h->byText('page 0') !== null, 'the first page shows');
    $h->clickText('Search');
    $h->frame();
    same([1, 1], [$tab, $changed], "$want: a tap on a tab chooses it and changed() reports");
    check($h->byText('page 1') !== null, 'the page follows');
}

// tabView + navigationBar + safeArea: the bars take the top and bottom safe areas, so safeArea() inside starts right under the navigationBar and ends right above the tabBar, and the Route's title shows in the first frame.
$tab = 0; $router = new Router('/home'); $body = null;
$h = new Headless(function (Context $c) use (&$tab, $router, &$body, $icon): void {
    $c->safeArea = [50.0, 0.0, 34.0, 0.0];
    $c->tabView([[$icon, 'Home'], [$icon, 'Me']], $tab, function () use ($c, $router, &$body): void {
        $c->navigationBar($router->title(), $router);
        $c->safeArea(function () use ($c, $router, &$body): void {
            $router->view($c, function (\MyPHP\UI\Route $r) use ($c, &$body): void {
                if ($r->match('/home')) {
                    $r->title('First Title');
                    $body = $c->column(0, 0, 1)->alignSelf(Node::STRETCH); $c->text('body'); $c->end();
                }
            });
        });
    });
}, 390, 844);
$h->rt->touch = true;
$h->frame();
check($h->byText('First Title') !== null, 'the Route title shows in the navigationBar on the first frame');
same([0.0, 94.0, 390.0, 652.0], $h->rect($body), 'safeArea inside tabView + navigationBar does not double-count top or bottom insets: the bar 94, the tab bar\'s band 98');


// section and cell: the inset grouped list — the group 16 in from the sides, rounded as the theme's groups, the header as written above it, the footer below; cells 44 high, padded 16, clicked() reports a tap.
$section = $group = $cell = $plain = null; $opened = 0;
$h = new Headless(function (Context $c) use (&$section, &$group, &$cell, &$plain, &$opened): void {
    $c->scroll(0, 0, 1)->fill();
      $section = $c->section('General', function () use ($c, &$cell, &$plain): void {
          $cell = $c->cell('Language', 'English', true);
          $plain = $c->cell('About');
      }, 'The language of the app.');
    $c->end();
    if ($c->clicked($cell)) { $opened++; }
}, 390, 844);
$h->rt->touch = true;
$h->frame();
$h->paint(); // the separators drawn over the group
$r = $h->rect($section);
same([0.0, 0.0, 390.0], [$r[0], $r[1], $r[2]], 'a section stretches across its parent');
check($h->byText('General') !== null, 'the header shows as written, as iOS 26\'s: ' . json_encode($h->texts()));
check($h->byText('The language of the app.') !== null, 'the footer shows');
$rc = $h->rect($cell); $rp = $h->rect($plain);
same([16.0, 358.0, 44.0], [$rc[0], $rc[2], $rc[3]], 'a cell is 44 high across the group, 16 in from the sides');
same($rc[1] + 44.0, $rp[1], 'the cells follow each other without a gap');
$rt = $h->rect($h->byText('Language')); $rd = $h->rect($h->byText('English'));
same(32.0, $rt[0], 'the title starts 16 in from the group');
check($rd[0] > $rt[0] + $rt[2] && $rd[0] + $rd[2] < 390.0 - 16.0 - 16.0, 'the detail sits at the end, before the chevron: ' . json_encode($rd));
$h->clickText('Language');
same(1, $opened, 'a tap on the cell reports clicked()');

// swipeActions: the content dragged toward the start uncovers the actions at its end; let go past half their width it stays open, a tap on an action reports its index and closes it; a full swipe performs the first; a tap on the open row closes it.
$action = -1; $chosen = []; $swipe = null; $taps = 0; $now = 0;
$h = poseTester(function (Context $c) use (&$action, &$chosen, &$swipe, &$taps): void {
    $c->column(0, 0, 1)->fill();
      $swipe = $c->key('row')->swipeActions($action, function () use ($c): void {
          $c->text('Milk')->padding(11, 16);
      }, 'Flag', '!Delete');
      if ($action >= 0) { $chosen[] = $action; } // read in the pass that reports it, as an app acts on it
      if ($c->clicked($swipe)) { $taps++; }
    $c->end();
}, $now, 390, 844);
$h->rt->touch = true;
check($h->byText('Delete') === null, 'closed, no action shows');
$rec = $h->rt->dragRecs[$swipe->id];
$total = -$rec->bounds[0] - 48; // the bounds reach 48 past the buttons, the full swipe
check($total >= 148.0, "two actions, 74 points at least each: $total");
// a slow drag of 100 points to the left, let go: past half the buttons' width, it rests open
$h->hover(300, 20); $h->press(300, 20);
foreach ([290, 270, 250, 230, 210, 200] as $x) { $now += 10_000_000; $h->drag($x, 20); }
$now += 200_000_000; $h->release(200, 20);
$now += 2_000_000_000; $h->frame();
same(-$total, $rec->own[0], 'let go 100 points in, the row rests open at the buttons\' width');
check($taps === 0, 'the swipe was no tap');
$del = $h->byText('Delete'); $flag = $h->byText('Flag');
check($del !== null && $flag !== null, 'open, both actions show: ' . json_encode($h->texts()));
$rd = $h->rect($del); $rf = $h->rect($flag);
check($rf[0] < $rd[0] && $rd[0] + $rd[2] <= 390.0 + 1, 'Flag, the first, is nearest the content, Delete at the end');
$h->clickText('Delete');
same([1], $chosen, 'a tap on Delete reports its index');
$now += 2_000_000_000; $h->frame();
same(0.0, $rec->own[0], 'and the row closes');
check($h->byText('Delete') === null, 'closed again, the actions are gone');
// a full swipe: 48 points past the buttons, let go, performs the first action
$h->hover(380, 20); $h->press(380, 20);
foreach ([370, 340, 300, 260, 220, 180, 150] as $x) { $now += 10_000_000; $h->drag($x, 20); }
$now += 200_000_000; $h->release(150, 20);
same([1, 0], $chosen, 'swiped 230 points, past the buttons by more than 48, the first action is performed');
$now += 2_000_000_000; $h->frame();
same(0.0, $rec->own[0], 'and the row is closed again');
// a tap on a closed row is the row's click
$h->click(100, 20);
same(1, $taps, 'a tap on the closed row is its click');
// a tap on an open row closes it, and is not the row's click
$h->hover(300, 20); $h->press(300, 20);
foreach ([290, 270, 250, 230, 210, 200] as $x) { $now += 10_000_000; $h->drag($x, 20); }
$now += 200_000_000; $h->release(200, 20);
$now += 2_000_000_000; $h->frame();
same(-$total, $rec->own[0], 'open again');
$h->click(100, 20);
$now += 2_000_000_000; $h->frame();
same(0.0, $rec->own[0], 'a tap on the open row closes it');
same(1, $taps, 'and is not the row\'s click');

// The interactive pop gesture: a finger at the page's start edge drags the page sideways, the page before showing under it; let go past a third of the width the router goes back at once, let go before it the page comes back.
$router = new Router('/home'); $now = 0; $leaving = [];
$h = poseTester(function (Context $c) use ($router, &$leaving): void {
    $c->column(0, 0, 1)->fill();
      $router->view($c, function (\MyPHP\UI\Route $r) use ($c): void {
          if ($r->match('/home')) { $r->title('Home'); $c->text('the home'); }
          elseif ($r->match('/note')) { $r->title('Note'); $c->text('the note'); }
      });
    $c->end();
}, $now, 390, 844);
$h->rt->touch = true;
$router->push('/note');
$h->frame(); // the push starts the slide
$now += 2_000_000_000; $h->frame();
check($h->byText('the note') !== null && $h->byText('the home') === null, 'the note shows alone');
// a short drag from the edge, let go: the page comes back
$h->hover(10, 300); $h->press(10, 300);
foreach ([20, 40, 60] as $x) { $now += 10_000_000; $h->drag($x, 300); }
$under = function (Node $n) use (&$under): bool { if ($n->text === 'the home') { return true; } foreach ($n->kids as $k) { if ($under($k)) { return true; } } return false; }; // the page under is inert: texts() leaves it out
check($under($h->root), 'dragged from the edge, the page before shows under the page, which moved with the finger');
$rn = $h->rect($h->byText('the note'));
check($rn[0] >= 50.0, 'the page moved 50 points with the finger: ' . json_encode($rn));
$now += 300_000_000; $h->release(60, 300);
$now += 2_000_000_000; $h->frame();
check($router->canGoBack() && $h->byText('the note') !== null && $h->byText('the home') === null, 'let go at 50, before a third of the width, the page comes back');
// a long drag, let go: the router goes back, at once
$h->hover(10, 300); $h->press(10, 300);
foreach ([30, 80, 130, 180, 230] as $x) { $now += 10_000_000; $h->drag($x, 300); }
$now += 300_000_000; $h->release(230, 300);
$now += 2_000_000_000; $h->frame();
check(!$router->canGoBack() && $h->byText('the home') !== null && $h->byText('the note') === null, 'let go at 220, past a third, the router went back: ' . json_encode($h->texts()));

// refreshable: a finger dragging down at the top pulls the content rubber-banded; let go far enough, $refreshing turns true and the content rests 60 down until the app is done; not far enough, it goes back.
$state = new \MyPHP\UI\ScrollState; $refreshing = false; $starts = 0; $first = null; $now = 0;
$h = poseTester(function (Context $c) use ($state, &$refreshing, &$starts, &$first): void {
    $c->column(0, 0, 1)->fill();
      $list = $c->refreshable($state, $refreshing, function () use ($c, &$first): void {
          for ($i = 0; $i < 40; $i++) { $n = $c->text("row $i")->padding(12, 16); if ($i === 0) { $first = $n; } }
      });
      if ($c->changed($list)) { $starts++; }
    $c->end();
}, $now, 390, 844);
$h->rt->touch = true;
$at = function () use (&$first): float { return $first->world->y; }; // where it shows, through the translation (the node of the last frame)
$top = $at();
$pull = function (float $by) use ($h, &$now): void { // a finger's drag down by $by, in steps, then lifted
    for ($d = 0; $d < $by; $d += 20) { $now += 16_000_000; $h->input->push(['scroll', 200, 300, 0, 20, true, false]); $h->frame(); }
};
$pull(60);
check($at() > $top && $at() < $top + 60, 'pulled 60 points, the content follows rubber-banded: ' . ($at() - $top));
$h->input->push(['scrollEnd', 200, 300]); $h->frame();
$now += 2_000_000_000; $h->frame();
same([false, 0], [$refreshing, $starts], 'let go before 60 points showed, no refresh');
closeFloat($top, $at(), 'and the content goes back');
$pull(200);
$h->input->push(['scrollEnd', 200, 300]); $h->frame();
same([true, 1], [$refreshing, $starts], 'pulled 200 points, let go: refreshing, changed() reports it');
$now += 2_000_000_000; $h->frame();
closeFloat($top + 60, $at(), 'the content rests 60 down while it refreshes');
$refreshing = false; $h->frame();
$now += 2_000_000_000; $h->frame();
closeFloat($top, $at(), 'done, it goes back up');
$now += 16_000_000; $h->input->push(['scroll', 200, 300, 0, -100, true, false]); $h->frame();
check($state->y > 0 && $state->pull == 0.0, 'a drag up scrolls the content as any scroll');

// largeTitle: the bar's title shows only once the large title scrolled under it.
$state = new \MyPHP\UI\ScrollState; $inline = null;
$h = new Headless(function (Context $c) use ($state): void {
    $c->column(0, 0, 1)->fill();
      $c->navigationBar('Mail', null, null, $state);
      $c->column(0, 0, 1)->alignSelf(Node::STRETCH)->scroll($state);
        $c->largeTitle('Mail');
        for ($i = 0; $i < 40; $i++) { $c->text("mail $i")->padding(12, 16); }
      $c->end();
    $c->end();
}, 390, 844);
$h->frame();
$find = function (Node $n, array &$out) use (&$find): void { if (($n->kind === Node::RICH ? $n->plainText() : $n->text) === 'Mail') { $out[] = $n; } foreach ($n->kids as $k) { $find($k, $out); } };
$out = []; $find($h->root, $out);
same(2, count($out), 'two titles: the bar\'s and the large one');
same([0.0, 34.0], [$out[0]->opacity, $out[1]->fontSize], 'at the top the bar\'s title is hidden, the large one 34 points');
$state->y = 60; $h->frame();
$out = []; $find($h->root, $out);
same(1.0, $out[0]->opacity, 'scrolled past it, the bar\'s title shows');

// A window whose system draws its materials over its view (iOS: Runtime::$overLayer): the tab bar's glass leaves no hole in the view, which shows the page under it for the material to blur; the glass's node and what it holds paint in the view over it, which records where the material goes.
$icon2 = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20"/></svg>');
$tab = 0;
$h = new Headless(function (Context $c) use (&$tab, $icon2): void {
    $c->safeArea = [0.0, 0.0, 34.0, 0.0];
    $c->column(0, 0, 1)->fill();
      $c->text('page')->grow(1);
      $c->tabBar([[$icon2, 'Home'], [$icon2, 'Me']], $tab);
    $c->end();
}, 390, 844);
$h->rt->overLayer = true; $h->rt->underLayer = false;
$h->frame();
$h->rt->materialPlaces = []; $h->rt->overPaints = [];
$list = $h->paint();
same(1, count($h->rt->overPaints), 'the glass\'s node is left to the view over the materials');
same([[], []], [$list->holes, $h->rt->materialPlaces], 'no hole in the view, and no material placed yet');
$ffi = \FFI::cdef();
$over = new \MyPHP\UI\DisplayList($ffi->new('float[' . (65536 * \MyPHP\UI\DisplayList::FLOATS_PER_RECT) . ']'), 65536, $ffi->new('float[' . (4096 * \MyPHP\UI\DisplayList::FLOATS_PER_TRANSFORM) . ']'), 4096);
$over->pathQuad = fn(\MyPHP\UI\Path $p, float $w, ?\Closure $visible = null) => ($b = $p->bounds()) === null ? [] : [[$b[0], $b[1], $b[2] - $b[0], $b[3] - $b[1], 0, 0]];
$over->pushClip(0, 0, 390, 844);
$h->rt->paintingOver = true;
foreach ($h->rt->overPaints as $o) { \MyPHP\UI\Painter::paint($o['node'], $over, $h->metrics, $h->theme, $h->rt); }
$h->rt->paintingOver = false;
same(1, count($h->rt->materialPlaces), 'painted over the materials, the node places its glass');
$p = $h->rt->materialPlaces[0];
same(['glass', 28.0], [$p['material'], $p['radius']], 'glass, rounded as the capsule');
same([], $over->holes, 'and the view over them has no hole either');
check($over->n > 0, 'the tabs\' icons and labels paint in the view over the glass');
