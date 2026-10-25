<?php
// SVGs as icons and images (mygo's svg_test.go TestIconTakesTheTextColorAndSize,
// TestImageOfAnSVG, TestIconsInAccessibility, TestParseSVGErrors): an
// icon as high as the font size around it and in its color, fitting a
// size of its own; an SVG image at its size; what assistive technology
// sees of icons; documents that are not SVG refused. The renderer is
// svg_test's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, Role, SVG};

$squareSVG = '<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
$checkSVG = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
$logoSVG = '<svg viewBox="0 0 30 10"><rect width="10" height="10" fill="#f00"/><rect x="10" width="10" height="10" fill="#0f0"/><rect x="20" width="10" height="10" fill="currentColor"/></svg>';

/** The icon quads (glyph kind) of the last paint inside $r: x, y, w, h and the color. @return list<array{0: float, 1: float, 2: float, 3: float, 4: int}> */
function svgQuads(Headless $h, array $r): array
{
    $list = $h->paint(); $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $k = $i * 16; $b = $list->buf;
        if (fmod($b[$k + 9], 16) != 1 || $b[$k] < $r[0] - 0.5 || $b[$k] > $r[0] + $r[2] || $b[$k + 1] < $r[1] - 0.5 || $b[$k + 1] > $r[1] + $r[3]) { continue; }
        $out[] = [(float)$b[$k], (float)$b[$k + 1], (float)$b[$k + 2], (float)$b[$k + 3], ((int)round($b[$k + 4] * 255) << 16) | ((int)round($b[$k + 5] * 255) << 8) | (int)round($b[$k + 6] * 255)];
    }
    return $out;
}

// An icon is as high as the font size around it, in the color of the text around it; a size of its own fits it, centered.
$square = SVG::parse($squareSVG);
$h = new Headless(function (Context $c) use ($square): void {
    $c->column(10, 10, 1);
      $c->icon($square)->label('plain');
      $c->row()->textColor(0x0000ff)->fontSize(24);
        $c->icon($square)->label('inherited');
      $c->end();
      $c->icon($square)->size(40, 20)->textColor(0x0000ff)->label('sized');
    $c->end();
}, 200, 200);
$h->frame();
$th = $h->theme;
$plain = $h->rect($h->byLabel('plain'));
check($plain[2] == $th->fontSize && $plain[3] == $th->fontSize && $plain[0] == 10, 'an icon is the font size, at the start of its column: ' . json_encode($plain));
$inherited = $h->rect($h->byLabel('inherited'));
same([24.0, 24.0], [(float)$inherited[2], (float)$inherited[3]], 'an icon takes its row\'s font size');
same($th->text, svgQuads($h, $plain)[0][4] ?? null, 'in the theme\'s text color');
same(0x0000ff, svgQuads($h, $inherited)[0][4] ?? null, 'in its row\'s text color');
$sized = $h->rect($h->byLabel('sized'));
$q = svgQuads($h, $sized)[0] ?? null;
check($sized[2] == 40 && $sized[3] == 20 && $q !== null && $q[2] == 20 && $q[0] == $sized[0] + 10 && $q[4] === 0x0000ff, 'a sized icon keeps its aspect ratio, centered, in its own text color: ' . json_encode([$sized, $q]));

// An SVG as an image is its size, or the size given.
$logo = SVG::parse($logoSVG);
same([30.0, 10.0], [(float)$logo->w, (float)$logo->h], 'its size');
$h = new Headless(function (Context $c) use ($logo): void {
    $c->column()->align(Node::START);
      $c->image($logo)->label('logo');
      $c->image($logo, 60, 60)->fit(Node::COVER)->label('cover');
    $c->end();
}, 200, 200);
$h->frame();
same([30.0, 10.0], array_map('floatval', array_slice($h->rect($h->byLabel('logo')), 2)), 'the logo is its size');
same([60.0, 60.0], array_map('floatval', array_slice($h->rect($h->byLabel('cover')), 2)), 'or the size given');

// Assistive technology sees the icons labeled, as images; the others are decoration.
$check = SVG::parse($checkSVG);
$h = new Headless(function (Context $c) use ($check): void {
    $c->row(); $c->icon($check); $c->icon($check)->label('Done'); $c->end();
}, 100, 50);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
same(['Done'], array_values(array_map(fn($n) => $n->label, array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::IMAGE))), 'the images seen');

// Documents that are not SVG are refused.
foreach (['<html/>', 'nope'] as $doc) {
    try { SVG::parse($doc); check(false, "parsed $doc"); } catch (\Throwable $e) { check(true, "$doc is refused"); }
}
