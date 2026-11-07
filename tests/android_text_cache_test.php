<?php
// Android's text engine keeps what it shaped (Text\System moves a line's glyphs
// onto its baseline as it lays it out), so a text shaped again must come back
// in glyphs of its own: the cache's, moved at each layout, sank the tab bar's
// labels a baseline at a time.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Platform\Android\{AndroidTextEngine, Fonts};
use MyPHP\Text\{Glyph, Style};

$e = new AndroidTextEngine(new Fonts());
$style = new Style(size: 10);
$font = $e->font($style);
$runes = [ord('H'), ord('i')];
$glyphs = [new Glyph($font, 1, 10, 0.0, 0.0, 5.0, 0, 0, false), new Glyph($font, 2, 10, 5.0, 0.0, 5.0, 1, 0, false)];
$cache = new \ReflectionProperty(AndroidTextEngine::class, 'shapeCache');
$cache->setValue($e, ["{$font->id}:0:Hi" => [['start' => 0, 'end' => 2, 'runs' => [['font' => $font, 'start' => 0, 'end' => 2, 'glyphs' => $glyphs]]]]]);

$first = $e->shape($runes, $style, [], 100.0, false, false);
foreach ($first[0]['runs'][0]['glyphs'] as $g) { $g->y += 8.0; $g->x -= 1.0; } // what a layout does to them
$again = $e->shape($runes, $style, [], 100.0, false, false);
same([[0.0, 0.0], [5.0, 0.0]], array_map(fn(Glyph $g) => [$g->x, $g->y], $again[0]['runs'][0]['glyphs']), 'a text shaped again starts where it was shaped, not where the last layout moved it');
check($again[0]['runs'][0]['glyphs'][0] !== $glyphs[0], 'the glyphs given are not the cache\'s');

// It breaks lines at the width asked (Engine::shape), which TextRunShaper does not: after spaces, beside CJK
// characters, inside a word longer than a line unless whole words are asked; each line's runes and clusters the
// paragraph's (a footer ran past the phone's edge in one line).
$e = new AndroidTextEngine(new Fonts());
$font = $e->font($style);
$fill = function (string $text) use ($e, $font, $cache): void { // every part of $text shaped as TextRunShaper would, 10 points a rune
    $runes = mb_str_split($text);
    $all = $cache->getValue($e);
    for ($a = 0; $a < count($runes); $a++) {
        for ($b = $a + 1; $b <= count($runes); $b++) {
            $part = array_slice($runes, $a, $b - $a);
            $glyphs = array_map(fn(int $i) => new Glyph($font, 1, 10, $i * 10.0, 0.0, 10.0, $i, 0, false), array_keys($part));
            $all["{$font->id}:0:" . implode('', $part)] = [['start' => 0, 'end' => count($part), 'runs' => [['font' => $font, 'start' => 0, 'end' => count($part), 'glyphs' => $glyphs]]]];
        }
    }
    $cache->setValue($e, $all);
};
$lines = function (string $text, float $width, bool $whole = false) use ($e, $style): array {
    $out = [];
    foreach ($e->shape(array_map(fn(string $c) => mb_ord($c), mb_str_split($text)), $style, [], $width, false, $whole) as $l) {
        $out[] = [$l['start'], $l['end'], $l['runs'][0]['glyphs'][0]->cluster];
    }
    return $out;
};
$fill('ab cd ef'); $fill('设置文字'); $fill('abcdefg h');
same([[[0, 3, 0], [3, 6, 3], [6, 8, 6]], [[0, 2, 0], [2, 4, 2]], [[0, 4, 0], [4, 8, 4], [8, 9, 8]], [[0, 8, 0], [8, 9, 8]], [[0, 8, 0]]],
    [$lines('ab cd ef', 45), $lines('设置文字', 25), $lines('abcdefg h', 45), $lines('abcdefg h', 45, true), $lines('ab cd ef', 0)],
    'lines at the width: after spaces, between CJK characters, a long word broken (or whole), one line with no width');
