<?php
// CSS's oklch() as a color myphp draws (mygo's widecolor_test.go, the parts
// of an sRGB renderer: TestOklchInsideSRGBHasNoWideColor,
// TestOklchOutsideSRGBKeepsWideColor's sRGB fallback, TestOklchOddInput):
// grays, white, the nearest sRGB of a vivid color, odd input.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Oklch;

$rgb = fn(int $c): array => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
[$r, $g, $b] = $rgb(Oklch::rgb(0.6, 0, 0));
check($r === $g && $g === $b, "no chroma is a gray: $r $g $b");
same(0xffffff, Oklch::rgb(1, 0.2, 30), 'full lightness is white');
[$r, $g, $b] = $rgb(Oklch::rgb(0.85, 0.3, 145));
check($g > $r && $g > $b, "a vivid green outside sRGB draws as the nearest green it shows: $r $g $b");
same(0x000000, Oklch::rgb(NAN, 0.3, 145), 'a NaN lightness is black');
[$r, $g, $b] = $rgb(Oklch::rgb(0.7, -0.1, 30));
check($r === $g && $g === $b, 'a negative chroma is 0, as in CSS: a gray');
$at = hrtime(true);
$c = Oklch::rgb(0.5, INF, 30);
check((hrtime(true) - $at) / 1e9 < 5, 'an infinite chroma is the most vivid color of the hue, at once: ' . dechex($c));
check(Oklch::rgb(0.5, 0.2, INF) >= 0, 'an infinite hue is a color');
