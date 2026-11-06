<?php
// The phone's theme (Theme::phone; not mygo's): UIKit's system colors and
// 17-point text, and UIKit's control sizes — a button, a field and a select
// 44 points high, the button a capsule; a switch's track 51 × 31; a
// segmented control 32 — where the desktop's theme keeps mygo's measured
// sizes; the grouped list's page and sections in UIKit's colors.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, TextState, Theme};

$light = Theme::phone(false); $dark = Theme::phone(true);
same([0x007aff, 0xffffff, 0xf2f2f7, 0xc6c6c8, 0x000000, 0x8e8e93], [$light->accent, $light->background, $light->surface, $light->border, $light->text, $light->textMuted], 'the light phone theme: systemBlue, systemBackground, secondarySystemBackground, separator, label, secondaryLabel');
same([0x0a84ff, 0x000000, 0x1c1c1e, 0xffffff], [$dark->accent, $dark->background, $dark->surface, $dark->text], 'the dark phone theme: pure black, the elevated gray, white text');
same([17.0, 17.0, 1.0, 12.0], [$light->fontSize, $light->baseFontSize, $light->textScale(), $light->radius], '17-point text at scale 1, corners rounded 12');
same([0xf2f2f7, 0xffffff], [$light->groupBackground, $light->groupSurface], 'grouped lists in the light: a gray page, white sections');
same([0x000000, 0x1c1c1e], [$dark->groupBackground, $dark->groupSurface], 'in the dark: a black page, dark gray sections');
same([0.0, 0.0, null], [Theme::light()->controlHeight, Theme::light()->switchWidth, Theme::light()->groupSurface], 'the desktop theme keeps mygo\'s sizes and colors');

// The controls at UIKit's sizes on the phone theme, at mygo's on the desktop's.
$on = false; $index = 0; $sel = 0; $state = new TextState;
$build = function (Context $c) use (&$on, &$index, &$sel, $state, &$nodes): void {
    $c->column(0, 8);
      $nodes['button'] = $c->primaryButton('Save');
      $nodes['switch'] = $c->switch('', $on);
      $nodes['segmented'] = $c->segmented(['Day', 'Week'], $index);
      $nodes['input'] = $c->input($state, 'Name');
      $nodes['select'] = $c->select(['Small', 'Large'], $sel);
    $c->end();
};
$nodes = [];
$h = new Headless($build, 390, 844);
$h->theme = Theme::phone(false);
$h->frame();
same(44.0, $h->rect($nodes['button'])[3], 'a button is 44 high on the phone');
same(22.0, $nodes['button']->radius, 'and a capsule');
same([51.0, 31.0], [$h->rect($nodes['switch'])[2], $h->rect($nodes['switch'])[3]], 'a switch without a label is its 51 × 31 track');
check($h->rect($nodes['segmented'])[3] >= 32.0, 'a segmented control is 32 high at least: ' . $h->rect($nodes['segmented'])[3]);
same(44.0, $h->rect($nodes['input'])[3], 'a field is 44 high');
check($h->rect($nodes['select'])[3] >= 44.0, 'a select is 44 high at least: ' . $h->rect($nodes['select'])[3]);
$nodes = [];
$h = new Headless($build, 390, 844);
$h->frame();
check($h->rect($nodes['button'])[3] < 44.0 && $nodes['button']->radius == 0.0, 'on the desktop the button keeps mygo\'s height and the theme\'s radius');
same([36.0, 20.0], [$h->rect($nodes['switch'])[2], $h->rect($nodes['switch'])[3]], 'and the switch mygo\'s Space(9) × Space(5) track');

// The grouped list on the phone theme: the page gray, the sections white.
$page = $group = null;
$h = new Headless(function (Context $c) use (&$page, &$group): void {
    $page = $c->insetGroupedList(function () use ($c, &$group): void {
        $c->section('General', function () use ($c): void { $c->cell('About'); });
    });
}, 390, 844);
$h->theme = Theme::phone(false);
$h->frame();
same(0xf2f2f7, $page->color, 'the page is systemGroupedBackground');
$cells = array_filter($page->kids[0]->kids, fn($k) => $k->color === 0xffffff);
check($cells !== [], 'the section is secondarySystemGroupedBackground, white: ' . json_encode(array_map(fn($k) => $k->color, $page->kids[0]->kids)));
