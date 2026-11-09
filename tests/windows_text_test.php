<?php
// DirectWrite's text on Windows (Native\DWrite, DWriteEngine): the system's families, a
// style's font and its metrics, a line shaped (a font falling back for Han), a paragraph broken
// into lines that hold all its runes, right-to-left runs, a span's weight, a glyph rasterized,
// color emoji told apart.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\{DWrite, Mem};
use MyPHP\Text\{Span, Style};

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $d = new DWrite(1.0);
    $e = $d->engine();
    [$app, $system] = $e->fontFamilies();
    $names = array_column($system, 0);
    check(in_array('Segoe UI', $names, true) && in_array('Consolas', $names, true), 'the system\'s families: ' . count($names));
    check(in_array(['Consolas', true], $system, true) && in_array(['Segoe UI', false], $system, true), 'each with whether it is monospaced');
    $font = $e->font(new Style('system-ui', 14));
    check($font !== null && $font->size === 14.0 && $font->ascent > 10 && $font->ascent < 20 && $font->descent > 0 && $font->underlineThick > 0, 'the interface font at 14 points, its metrics: ' . json_encode($font));

    $line = $d->shape('Hello, 世界', 16);
    check($line['width'] > 50 && $line['ascent'] > 0 && count($line['glyphs']) === 9, "a line shaped: {$line['width']} wide, " . count($line['glyphs']) . ' glyphs');
    check(count(array_unique(array_column($line['glyphs'], 'font'))) === 2, 'its Han from a font falling back');
    same(range(0, 8), array_column($line['glyphs'], 'index'), 'each glyph its UTF-16 index');
    $xs = array_column($line['glyphs'], 'x');
    $sorted = $xs;
    sort($sorted);
    check($xs === $sorted && $xs[0] >= 0 && count(array_unique($xs)) === count($xs), 'its glyphs left to right');

    $text = 'The quick brown fox jumps over the lazy dog';
    $runes = array_map('mb_ord', mb_str_split($text));
    $lines = $e->shape($runes, new Style('system-ui', 14), [], 100, false, false);
    check(count($lines) > 2, 'a paragraph broken into lines of 100 points: ' . count($lines));
    $at = 0;
    foreach ($lines as $l) { check($l['start'] === $at && $l['end'] > $l['start'], "a line from where the last ended ($at)"); $at = $l['end']; }
    same(count($runes), $at, 'the lines hold all its runes');

    $ar = array_map('mb_ord', mb_str_split('مرحبا'));
    $rtl = $e->shape($ar, new Style('system-ui', 14), [], 0, true, false);
    check($rtl !== [] && $rtl[0]['runs'][0]['glyphs'][0]->rtl, 'Arabic runs right to left');

    $bold = $e->shape($runes, new Style('system-ui', 14), [new Span(3, weight: 700)], 0, false, false);
    check(count($bold[0]['runs']) >= 2 && $bold[0]['runs'][0]['font']->id !== $bold[0]['runs'][1]['font']->id, 'a span of weight 700 in a font of its own');

    $g = $line['glyphs'][0];
    $b = $d->rasterize($g['font'], $g['glyph'], 2.0);
    check($b !== null && $b['w'] > 5 && $b['h'] > 10 && !$b['color'] && $b['oy'] <= 0, 'H rasterized at scale 2: ' . json_encode($b === null ? null : [$b['w'], $b['h'], $b['ox'], $b['oy']]));
    if ($b !== null) {
        $ink = Mem::read($b['addr'], $b['w'] * $b['h']);
        check(max(array_map('ord', str_split($ink))) === 255, 'its strokes covered whole');
        Mem::free($b['rows']);
    }
    $emoji = $d->shape('😀', 16);
    check($emoji['glyphs'] !== [] && $d->isColor($emoji['glyphs'][0]['font']) && !$d->isColor($g['font']), 'an emoji of a color font, H not');
})();
