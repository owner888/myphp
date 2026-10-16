<?php
// The SVG renderer, pure PHP: what it draws into pixels — shapes, the
// path commands, transforms, styles and their cascade, gradients, strokes
// with joins, caps and dashes, opacity, clip paths, masks, use and symbol
// elements, currentColor — read off the pixels it leaves.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Svg\{Doc, Matrix, Path, Style};

/** The pixel at (x, y) of a drawing $w wide: premultiplied [r, g, b, a]. */
$px = fn(array $pix, int $w, int $x, int $y) => array_slice($pix, ($y * $w + $x) * 4, 4);
$alpha = fn(array $pix, int $w, int $x, int $y) => $pix[($y * $w + $x) * 4 + 3];
$draw = fn(string $svg, int $w = 10, int $h = 10, array $current = Style::BLACK) => (new Doc($svg))->pixels($w, $h, $current);

// Sizes: width and height, a viewBox, one from the other, the 300 × 150 default.
same([24.0, 24.0], [(new Doc('<svg viewBox="0 0 24 24"/>'))->w, (new Doc('<svg viewBox="0 0 24 24"/>'))->h], 'the size from the viewBox');
same([32.0, 16.0], [(new Doc('<svg width="32" height="16"/>'))->w, (new Doc('<svg width="32" height="16"/>'))->h], 'width and height');
same([40.0, 20.0], [(new Doc('<svg width="40" viewBox="0 0 2 1"/>'))->w, (new Doc('<svg width="40" viewBox="0 0 2 1"/>'))->h], 'the height from the width and the viewBox');
same([300.0, 150.0], [(new Doc('<svg/>'))->w, (new Doc('<svg/>'))->h], 'the default size');
try { new Doc('<div/>'); check(false, 'no svg element'); } catch (\RuntimeException $e) { same('svg: no svg element', $e->getMessage(), 'no svg element is an error'); }

// A filled rect covers its pixels in full, the rest not at all; the edges are anti-aliased.
$p = $draw('<svg viewBox="0 0 10 10"><rect x="2" y="2" width="4" height="4" fill="#ff0000"/></svg>');
same([255, 0, 0, 255], $px($p, 10, 3, 3), 'inside: red, opaque'); same(0, $alpha($p, 10, 0, 0), 'outside: clear'); same(0, $alpha($p, 10, 6, 3), 'just past the edge');
$p = $draw('<svg viewBox="0 0 10 10"><rect x="2.5" y="2" width="4" height="4"/></svg>');
same(128, $alpha($p, 10, 2, 3), 'half a pixel covered: half alpha'); same([0, 0, 0, 255], $px($p, 10, 4, 3), 'the default fill is black');
// Circles, ellipses, lines, polylines and polygons; a path with every command.
$p = $draw('<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>');
check($alpha($p, 10, 5, 5) === 255 && $alpha($p, 10, 0, 0) === 0 && $alpha($p, 10, 5, 1) > 200, 'a circle: full at the center, clear at the corner, on at the top');
$p = $draw('<svg viewBox="0 0 10 10"><ellipse cx="5" cy="5" rx="4" ry="1"/></svg>');
check($alpha($p, 10, 5, 5) >= 240 && $alpha($p, 10, 5, 2) === 0, 'an ellipse: flat (its chords stray a tenth of a pixel inside at most)');
$p = $draw('<svg viewBox="0 0 10 10"><line x1="0" y1="5" x2="10" y2="5" stroke="black" stroke-width="2"/></svg>');
check($alpha($p, 10, 5, 4) === 255 && $alpha($p, 10, 5, 5) === 255 && $alpha($p, 10, 5, 3) === 0, 'a line 2 wide covers two rows');
$p = $draw('<svg viewBox="0 0 10 10"><line x1="0" y1="5" x2="10" y2="5"/></svg>');
same(0, array_sum($p), 'a line without a stroke draws nothing');
$p = $draw('<svg viewBox="0 0 10 10"><polygon points="0,0 10,0 5,10"/></svg>');
check($alpha($p, 10, 5, 2) === 255 && $alpha($p, 10, 0, 9) === 0, 'a polygon');
$p = $draw('<svg viewBox="0 0 10 10"><polyline points="0,5 10,5" stroke="black" fill="none"/></svg>');
check($alpha($p, 10, 5, 4) > 0 && $alpha($p, 10, 5, 2) === 0, 'a polyline stroked 1 wide');
$pa = Path::parse('M1 1 h3 v3 H1 z m9 9 Q 12 0 14 10 T 18 10 C 1 2 3 4 5 6 S 7 8 9 10 A 5 5 0 1 0 20 20 L 2 2 l 1 1 V 9 v 1 X 5 5');
same([Path::MOVE, Path::LINE, Path::LINE, Path::LINE, Path::CLOSE, Path::MOVE, Path::CUBIC, Path::CUBIC, Path::CUBIC, Path::CUBIC], array_slice($pa->ops, 0, 10), 'every command: lines, quadratics and arcs as cubics');
check(end($pa->ops) === Path::LINE && count($pa->ops) === 16, 'parsed up to the first error (X), as SVG asks');
same([10.0, 10.0], [$pa->pts[8], $pa->pts[9]], 'm after z is relative to the subpath start');
$pa = Path::parse('M0 0 L10 0 L10 10 Z'); same([0.0, 0.0, 10.0, 10.0], $pa->bounds(), 'the bounds');
$pa = Path::parse('M0 5 C 0 -5 10 -5 10 5'); same(-2.5, round($pa->bounds()[1], 3), 'the bounds of a curve reach its extremum (y = −2.5 at t = ½), not its control points (−5)');

// Transforms: nested, on groups and shapes, in error.
$p = $draw('<svg viewBox="0 0 10 10"><g transform="translate(5 5)"><rect width="2" height="2" transform="scale(2)"/></g></svg>');
check($alpha($p, 10, 8, 8) === 255 && $alpha($p, 10, 4, 4) === 0, 'translate then scale, nested');
$p = $draw('<svg viewBox="0 0 10 10"><rect y="-2" width="5" height="2" transform="rotate(90)"/></svg>');
check($alpha($p, 10, 1, 3) === 255 && $alpha($p, 10, 3, 1) === 0, 'rotate(90) turns a strip above the origin down the left edge');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="2" height="2" transform="bogus(1)"/></svg>');
same(255, $alpha($p, 10, 0, 0), 'a transform in error transforms nothing');
same([1.0, 0.0, 0.0, 1.0, 5.0, 5.0], Matrix::parse('matrix(1 0 0 1 5 5)'), 'matrix()');
same([0.0, 1.0, -1.0, 0.0, 2.0, 0.0], array_map(fn($v) => round($v, 6), Matrix::parse('rotate(90 1 1)')), 'rotate about a point');
same(2.0, round(Matrix::stretch(Matrix::parse('scale(2 0.5)')), 6), 'stretch: the largest scale');

// Style: attributes, the style attribute over them, style sheets by type, class and id in specificity order, inherit, currentColor.
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="red" style="fill: #00ff00"/></svg>');
same([0, 255, 0, 255], $px($p, 10, 5, 5), 'the style attribute wins over the fill attribute');
$p = $draw('<svg viewBox="0 0 10 10"><style>rect { fill: blue } .a { fill: red } #b { fill: rgb(0, 255, 0) }</style><rect class="a" id="b" width="10" height="10"/></svg>');
same([0, 255, 0, 255], $px($p, 10, 5, 5), 'an id rule wins over a class rule over a type rule');
$p = $draw('<svg viewBox="0 0 10 10"><style>.a.b { fill: red } .a { fill: blue }</style><rect class="b a" width="10" height="10"/></svg>');
same([255, 0, 0, 255], $px($p, 10, 5, 5), 'two classes are more specific than one, whatever the order');
$p = $draw('<svg viewBox="0 0 10 10"><g fill="red"><rect width="10" height="10" fill="inherit" style="fill: blue; fill: inherit"/></g></svg>');
same([255, 0, 0, 255], $px($p, 10, 5, 5), 'inherit takes the parent\'s fill');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="currentColor"/></svg>', 10, 10, [0.0, 0.0, 1.0, 1.0]);
same([0, 0, 255, 255], $px($p, 10, 5, 5), 'currentColor is the color it is drawn with');
$p = $draw('<svg viewBox="0 0 10 10" color="green"><rect width="10" height="10" fill="currentColor"/></svg>', 10, 10, [0.0, 0.0, 1.0, 1.0]);
same([0, 128, 0, 255], $px($p, 10, 5, 5), 'unless the document sets the color property');
same(false, (new Doc('<svg viewBox="0 0 10 10" color="green"><rect width="10" height="10" fill="currentColor"/></svg>'))->usesCurrentColor, 'and then it does not use the color it is drawn with');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="hsl(120 100% 50%)" fill-opacity="0.5"/></svg>');
same([0, 128, 0, 128], $px($p, 10, 5, 5), 'hsl(), fill-opacity: premultiplied');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="#f008"/></svg>');
same([136, 0, 0, 136], $px($p, 10, 5, 5), '#rgba');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" display="none"/><rect width="5" height="5" visibility="hidden"/></svg>');
same(0, array_sum($p), 'display none and visibility hidden draw nothing');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="none" stroke="black" stroke-width="2"/></svg>');
check($alpha($p, 10, 0, 5) === 255 && $alpha($p, 10, 5, 5) === 0, 'a stroke straddles the edge: one pixel inside');

// Gradients: linear along x, radial, objectBoundingBox and userSpaceOnUse, stops, spread, href inheritance.
$p = $draw('<svg viewBox="0 0 10 10"><defs><linearGradient id="g"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect width="10" height="10" fill="url(#g)"/></svg>');
[$r0] = $px($p, 10, 0, 5); [$r9, , $b9] = $px($p, 10, 9, 5);
check($r0 > 230 && $b9 > 230 && $r9 < 30, 'red at the left, blue at the right');
$p = $draw('<svg viewBox="0 0 10 10"><radialGradient id="g"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient><rect width="10" height="10" fill="url(#g)"/></svg>');
check($px($p, 10, 5, 5)[0] > 200 && $px($p, 10, 0, 0)[0] < 30, 'a radial gradient: white at the center, black at the corner');
$p = $draw('<svg viewBox="0 0 10 10"><linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0" x2="10"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient><rect x="5" width="5" height="10" fill="url(#g)"/></svg>');
check($px($p, 10, 5, 5)[0] > 100 && $px($p, 10, 5, 5)[2] > 100, 'userSpaceOnUse: the rect at 5 starts halfway along');
$p = $draw('<svg viewBox="0 0 10 10"><linearGradient id="a"><stop offset="0" stop-color="green"/><stop offset="1" stop-color="green"/></linearGradient><linearGradient id="g" href="#a"/><rect width="10" height="10" fill="url(#g)"/></svg>');
same([0, 128, 0, 255], $px($p, 10, 5, 5), 'a gradient takes the stops of the one it refers to');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="url(#nope) red"/></svg>');
same([255, 0, 0, 255], $px($p, 10, 5, 5), 'a reference to nothing falls back');
$p = $draw('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="url(#nope)"/></svg>');
same(0, array_sum($p), 'or paints nothing');

// Strokes: joins, caps, dashes, paint-order.
$p = $draw('<svg viewBox="0 0 20 20"><path d="M2 18 L10 4 L18 18" fill="none" stroke="black" stroke-width="2" stroke-linejoin="miter"/></svg>', 20, 20);
$miter = $alpha($p, 20, 10, 2); // the miter reaches above the vertex
$p = $draw('<svg viewBox="0 0 20 20"><path d="M2 18 L10 4 L18 18" fill="none" stroke="black" stroke-width="2" stroke-linejoin="bevel"/></svg>', 20, 20);
check($miter > 0 && $alpha($p, 20, 10, 2) === 0, 'a miter join reaches further than a bevel');
$p = $draw('<svg viewBox="0 0 10 10"><line x1="2" y1="5" x2="8" y2="5" stroke="black" stroke-width="4" stroke-linecap="butt"/></svg>');
$pr = $draw('<svg viewBox="0 0 10 10"><line x1="2" y1="5" x2="8" y2="5" stroke="black" stroke-width="4" stroke-linecap="round"/></svg>');
$ps = $draw('<svg viewBox="0 0 10 10"><line x1="2" y1="5" x2="8" y2="5" stroke="black" stroke-width="4" stroke-linecap="square"/></svg>');
check($alpha($p, 10, 1, 5) === 0 && $alpha($pr, 10, 1, 5) > 0 && $alpha($ps, 10, 0, 5) === 255 && $alpha($pr, 10, 0, 3) < $alpha($ps, 10, 0, 3), 'butt, round and square caps');
$p = $draw('<svg viewBox="0 0 10 10"><line x1="0" y1="5" x2="10" y2="5" stroke="black" stroke-width="2" stroke-dasharray="2 2"/></svg>');
check($alpha($p, 10, 0, 4) === 255 && $alpha($p, 10, 1, 4) === 255 && $alpha($p, 10, 2, 4) === 0 && $alpha($p, 10, 4, 4) === 255, 'dashes 2 on, 2 off');
$p = $draw('<svg viewBox="0 0 10 10"><line x1="0" y1="5" x2="10" y2="5" stroke="black" stroke-width="2" stroke-dasharray="2 2" stroke-dashoffset="2"/></svg>');
check($alpha($p, 10, 0, 4) === 0 && $alpha($p, 10, 2, 4) === 255, 'the offset starts in the gap');
$p = $draw('<svg viewBox="0 0 10 10"><rect x="2" y="2" width="6" height="6" fill="red" stroke="blue" stroke-width="2" paint-order="stroke"/></svg>');
same([255, 0, 0, 255], $px($p, 10, 2, 5), 'paint-order stroke: the fill covers the stroke\'s inner half');

// Opacity of a group applies to it as a whole; clip paths; masks; nested svg clips; use and symbol.
$p = $draw('<svg viewBox="0 0 10 10"><g opacity="0.5"><rect width="10" height="10"/><rect width="10" height="10"/></g></svg>');
same(128, $alpha($p, 10, 5, 5), 'group opacity: two opaque rects at half, not three quarters');
$p = $draw('<svg viewBox="0 0 10 10"><clipPath id="c"><rect width="5" height="10"/></clipPath><rect width="10" height="10" clip-path="url(#c)"/></svg>');
check($alpha($p, 10, 2, 5) === 255 && $alpha($p, 10, 7, 5) === 0, 'a clip path keeps the left half');
$p = $draw('<svg viewBox="0 0 10 10"><clipPath id="c" clipPathUnits="objectBoundingBox"><rect width="0.5" height="1"/></clipPath><rect x="4" width="4" height="10" clip-path="url(#c)"/></svg>');
check($alpha($p, 10, 5, 5) === 255 && $alpha($p, 10, 7, 5) === 0, 'objectBoundingBox clips in the shape\'s box');
$p = $draw('<svg viewBox="0 0 10 10"><mask id="m"><rect width="10" height="10" fill="white"/><rect width="5" height="10" fill="black"/></mask><rect width="10" height="10" fill="red" mask="url(#m)"/></svg>');
check($alpha($p, 10, 2, 5) === 0 && $alpha($p, 10, 7, 5) === 255, 'a luminance mask: black hides, white shows');
$p = $draw('<svg viewBox="0 0 10 10"><mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="5" height="10"><rect width="10" height="10" fill="white"/></mask><rect width="10" height="10" mask="url(#m)"/></svg>');
check($alpha($p, 10, 2, 5) === 255 && $alpha($p, 10, 7, 5) === 0, 'the mask shows within its region only');
$p = $draw('<svg viewBox="0 0 10 10"><svg x="2" y="2" width="4" height="4" viewBox="0 0 1 1"><rect width="10" height="10"/></svg></svg>');
check($alpha($p, 10, 3, 3) === 255 && $alpha($p, 10, 7, 7) === 0, 'a nested svg scales its viewBox and clips to its viewport');
$p = $draw('<svg viewBox="0 0 10 10"><defs><rect id="r" width="2" height="2"/></defs><use href="#r" x="5" y="5"/><use xlink:href="#r" xmlns:xlink="http://www.w3.org/1999/xlink" x="0" y="8"/></svg>');
check($alpha($p, 10, 6, 6) === 255 && $alpha($p, 10, 0, 9) === 255 && $alpha($p, 10, 0, 0) === 0, 'use draws the shape where it says, by href or xlink:href');
$p = $draw('<svg viewBox="0 0 10 10"><symbol id="s" viewBox="0 0 1 1"><rect width="1" height="1"/></symbol><use href="#s" x="5" y="0" width="5" height="5"/></svg>');
check($alpha($p, 10, 7, 2) === 255 && $alpha($p, 10, 2, 2) === 0, 'a symbol is drawn in the viewport the use gives');
$p = $draw('<svg viewBox="0 0 10 10"><g id="g"><use href="#g"/><rect width="2" height="2"/></g></svg>');
same(255, $alpha($p, 10, 0, 0), 'a use of its own ancestor draws nothing, and does not loop');
$p = $draw('<svg viewBox="0 0 10 10" preserveAspectRatio="xMinYMin meet"><rect width="10" height="10"/></svg>', 20, 10);
check($alpha($p, 20, 5, 5) === 255 && $alpha($p, 20, 15, 5) === 0, 'xMinYMin meet: fitted at the left');
$p = (new Doc('<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'))->pixels(20, 10, Style::BLACK, true);
same(255, $alpha($p, 20, 15, 5), 'stretched to the box');
$p = (new Doc('<svg viewBox="0 0 10 10"><rect width="10" height="2"/></svg>'))->rotated(10, 10, 16, 16, 90);
check($alpha($p, 16, 12, 8) === 255 && $alpha($p, 16, 8, 4) === 0, 'rotated 90° clockwise: the top strip is on the right');

// Path masks: a UI path filled, or stroked with round caps, rasterized the same way (what Text::pathQuad puts in the atlas).
$sq = (new \MyPHP\UI\Path)->moveTo(1, 1)->lineTo(3, 1)->lineTo(3, 3)->lineTo(1, 3)->close();
$m = \MyPHP\UI\Svg\Mask::of($sq, 0, 2.0, 0, 0, 8, 8); // at scale 2: pixels 2..5
same([255, 0, 0], [ord($m[3 * 8 + 3]), ord($m[1 * 8 + 1]), ord($m[6 * 8 + 6])], 'a filled square at scale 2');
$ln = (new \MyPHP\UI\Path)->moveTo(2, 4)->lineTo(6, 4);
$m = \MyPHP\UI\Svg\Mask::of($ln, 2, 1.0, 0, 0, 8, 8);
check(ord($m[3 * 8 + 4]) === 255 && ord($m[3 * 8 + 1]) > 0 && ord($m[3 * 8 + 0]) <= 1 && ord($m[1 * 8 + 4]) === 0, 'a line stroked 2 wide with round caps: they reach a pixel past the ends');
