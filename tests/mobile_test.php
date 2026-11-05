<?php
// The widgets of a phone (ContextMobile, not mygo's): the safe area and
// the keyboard a safeArea keeps out of, a tab bar 49 points high into the
// safe area's bottom, a navigation bar 44 high under its top with the
// router's way back, a sheet rising from the bottom that Escape and a tap
// outside close, and an action sheet of choices, its cancel button and
// its destructive action.
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

// tabBar: 49 high plus the safe area's bottom, the items sharing the width; a tap chooses, changed() reports it.
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
same([0.0, 761.0, 390.0, 83.0], $r, 'the tab bar: the full width, 49 + 34 high at the bottom');
$ri = $h->rect($h->byText('Search')); $rm = $h->rect($h->byText('Me'));
closeFloat(195.0, $ri[0] + $ri[2] / 2, 'the three items share the width: the second centers in the middle');
closeFloat(325.0, $rm[0] + $rm[2] / 2, 'the third in the last third');
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
$h->frame();
$r = $h->rect($nav);
same([0.0, 0.0, 390.0, 94.0], $r, 'the navigation bar: 44 + 50 high at the top');
check($h->byText('Home') !== null && $h->byText('Edit') !== null, 'the title and the trailing item show');
check($h->byLabel('Back') === null, 'no way back at the first page');
$router->push('/note');
$h->frame();
check($h->byText('Note') !== null && $h->byLabel('Back') !== null && $h->byText('Home') !== null, 'pushed: the title, and the previous page\'s title as the way back');
$rt = $h->rect($h->byText('Note'));
closeFloat(195.0, $rt[0] + $rt[2] / 2, 'the title centers in the bar');
$h->clickOn($h->byLabel('Back'));
$h->frame();
check(!$router->canGoBack() && $h->byText('the note') === null, 'a tap on the way back goes back');

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
same([0.0, 94.0, 390.0, 667.0], $h->rect($body), 'safeArea inside tabView + navigationBar does not double-count top or bottom insets');

