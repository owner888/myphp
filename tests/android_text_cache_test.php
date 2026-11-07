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
