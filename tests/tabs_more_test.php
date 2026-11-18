<?php
// A TabView of more than five tabs: a phone's bar has the first four and More, which lists the others, grouped as the
// TabSections were; a tap on More again from one of them goes back to the list. A sidebar (a tablet on its side, the
// desktop) lists them all under their sections' titles.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context};

$app = new class { public string $tab = 'a'; };
$make = fn(string $v, string $t) => \Tab($t, systemImage: 'house', value: $v)(\Text("Page $t"));
$view = fn(Context $c) => Content::render(fn() => \TabView(selection: $app->tab)(
    $make('a', 'Alpha'), $make('b', 'Beta'),
    \TabSection('Tools')($make('c', 'Gamma'), $make('d', 'Delta'), $make('e', 'Epsilon')),
    \TabSection('Other')($make('f', 'Zeta'), $make('g', 'Eta')),
)->tabViewStyle(\TabViewStyle::sidebarAdaptable), $c);
$settle = function (Headless $h): void { $h->frame(2); usleep(700_000); $h->frame(6); };

// A phone: four tabs and More.
$h = new Headless($view, 393, 852);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(3);
same([true, true, true, true, true, false, false], array_map(fn(string $l) => $h->byLabel($l) !== null, ['Alpha', 'Beta', 'Gamma', 'Delta', 'More', 'Epsilon', 'Zeta']), 'the bar has the first four tabs and More');
$h->clickOn($h->byLabel('More')); $settle($h);
same([true, true, true, false], [$h->byText('Epsilon') !== null, $h->byText('Zeta') !== null, $h->byText('Other') !== null, $h->byText('Tools') !== null && false], 'More lists the others, under their sections');
$h->clickOn($h->byText('Zeta')); $settle($h);
same(['f', true], [$app->tab, $h->byText('Page Zeta') !== null], 'a row of the list chooses its tab');
$h->clickOn($h->byLabel('More')); $settle($h);
same(true, $h->byText('Eta') !== null, 'More again goes back to the list');
$h->clickOn($h->byLabel('Beta')); $settle($h);
same(['b', true], [$app->tab, $h->byText('Page Beta') !== null], 'a tab of the bar goes on working');

// Five tabs or fewer: no More.
$few = new class { public string $tab = 'a'; };
$h = new Headless(fn(Context $c) => Content::render(fn() => \TabView(selection: $few->tab)($make('a', 'Alpha'), $make('b', 'Beta'), $make('c', 'Gamma'), $make('d', 'Delta'), $make('e', 'Epsilon')), $c), 393, 852);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(3);
same([true, false], [$h->byLabel('Epsilon') !== null, $h->byLabel('More') !== null], 'five tabs fit the bar');

// A sidebar: all of them, under the titles of their sections.
$h = new Headless($view, 1024, 768);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(3);
same([true, true, true, true], [$h->byText('Tools') !== null, $h->byText('Other') !== null, $h->byText('Epsilon') !== null, $h->byText('Eta') !== null], 'a sidebar lists every tab under its section');

// The app chooses a tab while the list shows: that tab shows, not the list.
$h = new Headless($view, 393, 852);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$app->tab = 'a'; $h->frame(3);
$h->clickOn($h->byLabel('More')); $settle($h);
$app->tab = 'e'; $settle($h);
same([true, false], [$h->byText('Page Epsilon') !== null, $h->byText('Eta') !== null], 'a tab chosen by the app shows over the list');

// A button on a page that sets the selection while the frame is built is not overwritten by the tab shown.
$go = new class { public string $tab = 'a'; };
$h = new Headless(fn(Context $c) => Content::render(fn() => \TabView(selection: $go->tab)(
    \Tab('One', systemImage: 'house', value: 'a')(\Button('Go to Two', action: function () use ($go): void { $go->tab = 'b'; })),
    \Tab('Two', systemImage: 'house', value: 'b')(\Text('Page Two')),
), $c), 393, 852);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(3);
$h->clickText('Go to Two'); $settle($h);
same(['b', true], [$go->tab, $h->byText('Page Two') !== null], 'a button on a page can go to another tab');
