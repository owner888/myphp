<?php
// The system's icons on Android (Platform\Android\Symbols, not mygo's): the SF
// Symbol names apps give, drawn as Google's Material Symbols. Every name maps
// to an icon it holds, every name Linux maps Android maps too, an icon draws
// in the color asked at its size, and a name of none draws empty.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Platform\Android\{MaterialSymbols, Symbols};

$missing = array_keys(array_filter(MaterialSymbols::NAMES, fn(string $icon) => !isset(MaterialSymbols::ICONS[$icon])));
same([], $missing, 'every SF name maps to an icon MaterialSymbols holds');

$linux = (new \ReflectionClassConstant(\MyPHP\Platform\Linux\Symbols::class, 'NAMES'))->getValue();
same([], array_values(array_diff(array_keys($linux), array_keys(MaterialSymbols::NAMES))), 'every name Linux draws, Android draws too');

$s = new Symbols();
[$w, $h, $rgba] = $s->draw('airpods', 16, 0, 0xff0000, 2);
$inked = 0; $red = true;
for ($i = 0; $i < $w * $h; $i++) {
    $a = ord($rgba[$i * 4 + 3]);
    if ($a > 128) { $inked++; }
    if ($a > 0) { $red = $red && abs(ord($rgba[$i * 4]) - $a) <= 2 && ord($rgba[$i * 4 + 1]) <= 2 && ord($rgba[$i * 4 + 2]) <= 2; } // premultiplied: red as opaque as the pixel, no green or blue
}
check($w === 32 && $h === 32 && strlen($rgba) === 32 * 32 * 4 && $inked > 100 && $red, "an icon at its size in pixels, in the color asked: {$w}x{$h}, $inked inked");
check($s->exists('airpods') && !$s->exists('no.such.symbol'), 'exists() tells the names it maps');
[$w, $h, $rgba] = $s->draw('no.such.symbol', 16, 0, 0, 1);
same([16, 16, str_repeat("\0", 16 * 16 * 4)], [$w, $h, $rgba], 'a name of no icon draws a clear square of its size');
