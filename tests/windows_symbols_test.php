<?php
// The system's icons on Windows (Platform\Windows\Symbols, not mygo's): the SF Symbol names apps
// give, drawn as glyphs of Segoe Fluent Icons or Segoe MDL2 Assets, Material Symbols where no
// Fluent icon means one. Every name Linux draws, Windows maps; on Windows, every code point mapped
// is a glyph of the icon font, an icon draws in the color asked at its size, and one of no Fluent
// icon draws too.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Platform\Windows\Symbols;

(function (): void {
    $linux = (new \ReflectionClassConstant(\MyPHP\Platform\Linux\Symbols::class, 'NAMES'))->getValue();
    same([], array_values(array_diff(array_keys($linux), array_keys(Symbols::GLYPHS))), 'every name Linux draws, Windows maps');
    same([], array_values(array_diff(array_keys(Symbols::GLYPHS), array_keys($linux))), 'and no other');
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped the rest: Windows's\n";
        return;
    }
    $s = new Symbols();
    check($s->font() !== '', 'an icon font: ' . ($s->font() ?: 'none'));
    $missing = [];
    foreach (array_unique(array_filter(Symbols::GLYPHS)) as $cp) {
        if ($s->codePoint($cp) === null) { $missing[] = sprintf('U+%04X', $cp); }
    }
    same([], $missing, "every code point mapped is a glyph of {$s->font()}");
    $inked = function (array $icon): array {
        [$w, $h, $rgba] = $icon;
        $n = 0; $red = true;
        for ($i = 0; $i < $w * $h; $i++) {
            $a = ord($rgba[$i * 4 + 3]);
            if ($a > 128) { $n++; }
            if ($a > 0) { $red = $red && abs(ord($rgba[$i * 4]) - $a) <= 2 && ord($rgba[$i * 4 + 1]) <= 2 && ord($rgba[$i * 4 + 2]) <= 2; } // premultiplied red
        }
        return [$w, $h, strlen($rgba) === $w * $h * 4, $n > 20, $red];
    };
    same([32, 32, true, true, true], $inked($s->draw('play.fill', 16, 0, 0xff0000, 2)), 'a Fluent icon at its size in pixels, in the color asked');
    same([32, 32, true, true, true], $inked($s->draw('pip.enter', 16, 0, 0xff0000, 2)), 'one of no Fluent icon drawn as a Material Symbol');
    check($s->exists('gear') && $s->exists('pip.enter') && !$s->exists('no.such.symbol'), 'exists() tells the names it draws');
})();
