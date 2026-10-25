<?php
// Themes (mygo's theme_test.go TestSpacingScalesWidgets, TestParseHex,
// TestInverseColors, TestInverseFillsTooltipsAndToasts): widgets sized in
// the theme's spacing, 0 the default's; colors written in hex; tooltips
// and toasts in the theme turned over, or in its inverse colors.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, Theme};

// Widgets grow with the spacing; none is the default's.
$measure = function (float $spacing): array {
    $h = new Headless(function (Context $c): void {
        $c->column()->align(Node::START);
          $c->button('OK')->label('button');
          $on = false; $tab = 0;
          $c->checkbox('', $on)->label('check');
          $c->tabs(['One', 'Two'], $tab);
        $c->end();
    }, 400, 300);
    $h->theme->spacing = $spacing;
    $h->frame();
    return [$h->rect($h->byLabel('button')), $h->rect($h->byLabel('check')), $h->rect($h->byText('One'))];
};
[$compact, $normal, $roomy, $unset] = [$measure(3), $measure(4), $measure(5), $measure(0)];
same([16.0, 16.0], [(float)$normal[1][2], (float)$normal[1][3]], 'the check box is 16 by 16 at the default spacing');
same($normal, $unset, 'no spacing lays widgets out as the default');
foreach (['button' => 0, 'check box' => 1] as $name => $i) {
    check($compact[$i][2] < $normal[$i][2] && $normal[$i][2] < $roomy[$i][2] && $compact[$i][3] < $normal[$i][3] && $normal[$i][3] < $roomy[$i][3], "the $name grows with the spacing");
}
check($compact[2][0] < $normal[2][0] && $normal[2][0] < $roomy[2][0], 'a tab\'s padding follows the spacing');

// Colors in hex: six digits, without #, around spaces, with alpha, three and four digits; not others.
foreach ([['#2563eb', [0x2563eb, 1.0]], ['2563EB', [0x2563eb, 1.0]], [' #00ff0080 ', [0x00ff00, 0x80 / 255]], ['#fA0', [0xffaa00, 1.0]], ['#fa08', [0xffaa00, 0x88 / 255]],
          ['#12345', null], ['#1234567', null], ['#123456789', null], ['#12345g', null], ['#+12345', null], ['#é12', null], ['', null], ['##123456', null]] as [$in, $want]) {
    same($want, Context::parseHex($in), 'parseHex ' . json_encode($in));
}

// The inverse colors: the text on the background turned over, or those set.
$th = Theme::dark();
same([$th->text, $th->background], $th->inverse(), 'none: the text and the background');
$th->inverse = 0x3a3a40;
same([0x3a3a40, $th->background], $th->inverse(), 'the fill alone');
$th->inverseText = 0xf2f2f7;
same([0x3a3a40, 0xf2f2f7], $th->inverse(), 'both');

// Tooltips and toasts are filled with them.
$fills = function (Theme $th): array {
    $now = 0;
    $h = poseTester(function (Context $c): void {
        $c->column(40, 0);
          if ($c->clicked($c->button('Save')->tooltip('Save the note'))) { $c->toast('Saved'); }
        $c->end();
        $c->toasts();
    }, $now, 400, 300);
    $h->theme = $th; $h->frame(1);
    $h->hover(...$h->middle($h->byText('Save')));
    $now += 2_000_000_000; $h->frame(1); $h->frame(1);
    $colors = function () use ($h): array { $list = $h->paint(); $out = []; for ($i = 0; $i < $list->n; $i++) { $k = $i * 16; if (fmod($list->buf[$k + 9], 16) == 0 && $list->buf[$k + 7] > 0.99) { $out[] = ((int)round($list->buf[$k + 4] * 255) << 16) | ((int)round($list->buf[$k + 5] * 255) << 8) | (int)round($list->buf[$k + 6] * 255); } } return $out; };
    $tip = in_array($th->inverse()[0], $colors(), true);
    $h->clickText('Save');
    $now += 1_000_000_000; $h->frame(1); $h->frame(1);
    return [$tip, in_array($th->inverse()[0], $colors(), true) && $h->byText('Saved') !== null];
};
$th = Theme::dark();
same([true, true], $fills($th), 'a dark theme fills a tooltip and a toast with its text color');
$th = Theme::dark(); $th->inverse = 0x3a3a40; $th->inverseText = 0xf2f2f7;
same([true, true], $fills($th), 'or with its inverse');

// The theme follows the desktop's accent (mygo's preferences_test.go TestThemeFollowsTheAccent): its shades, the focus ring and the selection of it, text on it white, or dark on a light accent; in dark mode too.
$pink = 0xdb2777;
$t = Theme::light()->follow(new \MyPHP\UI\Preferences(accent: $pink));
check($t->accent === $pink && $t->accentText === 0xffffff && $t->accentHover !== $pink, 'pink: the accent, white text, a hover shade');
check($t->focusAlpha > 0 && $t->selectionAlpha > 0 && ($t->focus >> 16) === ($pink >> 16), 'the focus ring and the selection of it');
$yellow = 0xfacc15;
check((Theme::luminance(Theme::light()->follow(new \MyPHP\UI\Preferences(accent: $yellow))->accentText) & 255) <= 100, 'text on yellow is dark');
$d = Theme::dark()->follow(new \MyPHP\UI\Preferences(accent: $yellow));
check($d->dark && $d->accent === $yellow, 'dark, the accent kept');
same(Theme::light()->accent, Theme::light()->follow(new \MyPHP\UI\Preferences())->accent, 'without an accent of the desktop, the theme\'s');
