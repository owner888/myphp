<?php
// Colors outside the sRGB gamut (mygo's widecolor_test.go): Oklch's wide
// colors and their sRGB twins, odd input, mixing them; what the display
// list draws of them: the sRGB twin, and the wide color once the frame is
// shown in a wide gamut, for fills, shadows, stripes, gradients, text,
// decorations, dividers and text backgrounds; none where none shows; the
// theme's accent keeping it. A window draws a wide gamut on the GPU alone,
// on a Display P3 screen (Native\Metal::useWide), which the headless
// runtime has none of.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, LinearGradient, Oklch, Span, Theme, WideColor};

$wideGreen = Oklch::rgb(0.85, 0.3, 145);
$bytes = fn(int $c): array => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];

// Inside sRGB, no wide color; outside, the wide one beyond 0 to 1, its sRGB twin the nearest green.
$g = Oklch::rgb(0.6, 0, 0); [$r, $gg, $b] = $bytes($g);
check(!WideColor::isWide($g) && $r === $gg && $gg === $b, 'a gray has no wide color');
same(0xffffff, Oklch::rgb(1, 0.2, 30), 'white');
$w = WideColor::wide($wideGreen);
check($w !== null && (min($w) < 0 || max($w) > 1), 'vivid green keeps a wide color outside 0 to 1: ' . json_encode($w));
[$r, $gg, $b] = $bytes($wideGreen);
check($gg > $r && $gg > $b, "its sRGB twin, the nearest green: $r $gg $b");
check(!WideColor::isWide(WideColor::srgb($wideGreen)) && WideColor::srgb($wideGreen) === ($wideGreen & 0xffffff), 'srgb() drops the wide color');
same($wideGreen, Oklch::rgb(0.85, 0.3, 145), 'the same color, the same int');

// Odd input: NaN is black, a negative chroma a gray, an infinite chroma the most vivid of the hue.
same(0x000000, Oklch::rgb(NAN, 0.3, 145), 'a NaN lightness is black');
[$r, $gg, $b] = $bytes(Oklch::rgb(0.7, -0.1, 30));
check($r === $gg && $gg === $b && !WideColor::isWide(Oklch::rgb(0.7, -0.1, 30)), 'a negative chroma is a gray');
check(WideColor::isWide(Oklch::rgb(0.5, INF, 30)), 'an infinite chroma is wide');

// Mixing: halfway to white, inside sRGB, its bytes the mix of the wide color; toward black, still wide; the ends; sRGB mixes; a color with itself.
$white = 0xffffff;
$m = Theme::mix($wideGreen, $white, 0.5);
$want = array_map(fn(float $v) => (int)(max(0, min(($v + 1) / 2, 1)) * 255 + 0.5), $w);
check(!WideColor::isWide($m) && $bytes($m) === $want, 'halfway to white: ' . json_encode([$bytes($m), $want]));
check(WideColor::isWide(Theme::mix($wideGreen, 0x000000, 0.1)), 'a tenth toward black stays wide');
same($wideGreen, Theme::mix($wideGreen, $white, 0.0), 'none of the way: itself');
same($white, Theme::mix($white, $wideGreen, 0.0), 'white none of the way to the green: white');
check(!WideColor::isWide(Theme::mix(0x010203, 0x040506, 0.5)), 'sRGB colors mix to sRGB');
same($wideGreen, Theme::mix($wideGreen, $wideGreen, 0.3), 'a color mixed with itself is itself');

/** The display list $h paints. */
function wcPaint(Headless $h): \MyPHP\UI\DisplayList { return $h->paint(); }

// A fill draws its sRGB twin, its wide color once shown wide, the twin again after.
$h = new Headless(function (Context $c) use ($wideGreen): void { $c->column()->size(20, 20)->background($wideGreen); $c->end(); }, 20, 20);
$h->frame();
$list = wcPaint($h);
check(count($list->wide) === 1, 'one quad in a wide color');
$k = $list->wide[0][0];
$rgb = fn() => [round($list->buf[$k + 4] * 255), round($list->buf[$k + 5] * 255), round($list->buf[$k + 6] * 255)];
same(array_map('floatval', $bytes($wideGreen)), $rgb(), 'drawn as its sRGB twin');
$list->showWide(true);
check(abs($list->buf[$k + 5] - $w[1]) < 1e-6 && abs($list->buf[$k + 4] - $w[0]) < 1e-6, 'shown wide, its extended components');
$list->showWide(false);
same(array_map('floatval', $bytes($wideGreen)), $rgb(), 'and the twin again');

// A shadow, stripes, a gradient's start, text and a span: wide; a plain shadow and the plain text not.
$h = new Headless(function (Context $c) use ($wideGreen): void {
    $c->column(20, 10);
      $c->column()->size(40, 20)->shadow(0, 4, 8, 0, 0x000000)->shadow(0, 4, 8, 0, $wideGreen)->background(0xffffff); $c->end();
      $c->column()->size(40, 20)->background(0xffffff)->stripes($wideGreen, 4, 4, 0); $c->end();
      $c->column()->size(40, 20)->linearGradient(new LinearGradient($wideGreen, 1, 0x000000, 1, 90)); $c->end();
      $c->text('wide')->textColor($wideGreen);
      $c->richText([new Span('span', color: $wideGreen, weight: 700), new Span(' plain')]);
    $c->end();
}, 200, 250);
$h->frame();
$list = wcPaint($h);
$kinds = []; $glyphs = 0;
foreach ($list->wide as [$k]) {
    $kind = ((int)$list->buf[$k + 9]) % 16;
    if ($kind === 1) { $glyphs++; } else { $kinds[] = $kind; }
}
$plainGlyphs = 0;
for ($q = 0; $q < $list->n; $q++) { if (((int)$list->buf[$q * 16 + 9]) % 16 === 1 && !in_array($q * 16, array_column($list->wide, 0), true)) { $plainGlyphs++; } }
check($glyphs === 8 && $plainGlyphs > 0, "wide glyphs $glyphs, plain ones $plainGlyphs");
check(count($kinds) >= 3, 'a shadow, the stripes and the gradient wide: kinds ' . json_encode($kinds));

// Decorations, a span's background and dividers in a wide color draw wide.
$h = new Headless(function (Context $c) use ($wideGreen): void {
    $c->richText([new Span('marked', underline: true, decorationColor: $wideGreen), new Span('bg', background: $wideGreen)]);
    $c->column()->dividers(1, $wideGreen);
      $c->column()->size(20, 10); $c->end();
      $c->column()->size(20, 10); $c->end();
    $c->end();
}, 200, 80);
$h->frame();
check(count(wcPaint($h)->wide) >= 3, 'the underline, the background and the divider wide');

// None where none shows: a text color given over a wide one, a border color without a border, a transparent wide color.
foreach ([
    'an sRGB text color after a wide one' => fn(Context $c) => $c->text('plain')->textColor($wideGreen)->textColor(0x0a141e),
    'a border color without a border' => function (Context $c) use ($wideGreen) { $c->column()->size(20, 20)->background(0xffffff)->borderColor($wideGreen); $c->end(); },
    'a transparent wide color' => function (Context $c) use ($wideGreen) { $c->column()->size(20, 20)->background($wideGreen, 0.0)->borderWidth(1)->borderColor(0x000000); $c->end(); },
] as $name => $view) {
    $h = new Headless($view, 100, 40); $h->frame();
    same([], wcPaint($h)->wide, "$name: no wide color");
}

// The theme's accent keeps the wide color, its hover and pressed colors mixed from it.
$t = Theme::light()->withAccent($wideGreen);
check(WideColor::isWide($t->accent) && $t->accent === $wideGreen, 'the accent keeps it');
