<?php
// Poses and content transitions (mygo's pose_test.go and content_test.go): a
// node moved, scaled, turned and faded after its transform, the pointer
// following it; a text's characters rolling or fading as it changes, an
// icon replaced shrinking away as the new grows in.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, ContentTransition, DisplayList, Easing, Node, Pose, SVG};

$lin = Easing::linear(...);
$a = new Animation(0.1, $lin);

// A pose moves the node after its layout, faded; the pointer finds it where it shows.
$lifted = false; $card = null; $clicks = 0;
$t = poseTester(function (Context $c) use (&$lifted, &$card, &$clicks, $a) {
    $card = $c->box(40, 40, 0xff0000)->animatePose($lifted ? new Pose(x: 100, fade: 0.5) : new Pose(), $a);
    if ($c->clicked($card)) { $clicks++; }
}, $now, 300, 100);
$lifted = true; $t->frame(1);
check($t->rt->needsFrame, 'the pose moving asks for frames');
$now += 50_000_000; $t->frame(1);
$w = $card->world;
check($w->set && $w->x >= 49 && $w->x <= 51, "halfway, the card shows at {$w->x}");
closeFloat(0.25, $card->poseFade, 'halfway, a quarter faded');
$q = null; $list = $t->paint();
for ($i = 0; $i < $list->n; $i++) { if ($list->buf[$i * 16 + 2] == 40.0) { $q = $list->buf[$i * 16 + 7]; } }
closeFloat(0.75, $q, 'painted three quarters opaque');
$t->click(70, 20);
same(1, $clicks, 'a click where it shows');
$now += 100_000_000; $t->frame(1);
$now += 10_000_000; $t->frame(1);
check(!$t->rt->needsFrame, 'in place, it asks for no frames');
same(100.0, $card->world->x, 'in place, the card shows at 100');

// Without motion it moves at once and only fades smoothly.
$lifted = false;
$t = poseTester(function (Context $c) use (&$lifted, &$card, $a) {
    $card = $c->box(40, 40, 0)->animatePose($lifted ? new Pose(y: -20, scale: 2, fade: 1) : new Pose(), $a);
}, $now, 200, 200);
$t->rt->reduceMotion = true;
$lifted = true; $t->frame(1);
$now += 50_000_000; $t->frame(1);
check($card->world->a == 2 && $card->poseFade >= 0.4 && $card->poseFade <= 0.6 && $t->rt->transitioning($card->id), "without motion, the card is at once twice as large, faded {$card->poseFade}");

// Poses nest: what is inside moving poses follows them, its own pose moving or at rest.
$moved = false; $leaf = $still = null;
$t = poseTester(function (Context $c) use (&$moved, &$leaf, &$still, $a) {
    $c->column(0, 0)->size(40, 40)->animatePose($moved ? new Pose(x: 100) : new Pose(), $a);
      $c->column(0, 0)->size(40, 40)->animatePose(new Pose(x: 10), $a);
        $c->column(0, 0)->size(40, 40)->animatePose($moved ? new Pose(y: 60) : new Pose(), $a);
          $leaf = $c->box(10, 10, 0);
        $c->end();
        $still = $c->box(10, 10, 0);
      $c->end();
    $c->end();
}, $now, 300, 200);
$moved = true; $t->frame(1);
$now += 50_000_000; $t->frame(1);
[$lx, $ly] = $leaf->world->point(0, 0);
check(abs($lx - 60) < 1 && abs($ly - 30) < 1, "halfway, the innermost node shows at $lx, $ly");
[$sx, $sy] = $still->world->point($still->x, $still->y);
check(abs($sx - 60) < 1 && abs($sy - $still->y) < 0.01, "halfway, the node under the resting pose shows at $sx, $sy");

/** The glyph quads of $list: alpha each. @return list<float> */
function glyphAlphas(DisplayList $list): array
{
    $g = [];
    for ($i = 0; $i < $list->n; $i++) {
        $kind = $list->buf[$i * 16 + 9];
        if (fmod($kind, DisplayList::KIND_STRIDE) == 1.0) { $g[] = $list->buf[$i * 16 + 7]; }
    }
    return $g;
}

// Numeric text rolls the characters that change, the others staying; plain, it fades across.
$value = '120'; $numeric = true;
$t = poseTester(function (Context $c) use (&$value, &$numeric, $a) {
    $c->text($value, 30)->contentTransition(new ContentTransition(numeric: $numeric, animation: $a));
}, $now, 200, 60);
$t->paint();
$g = glyphAlphas($t->paint());
check(count($g) === 3 && min($g) == 1.0, 'at rest, three opaque glyphs');
$value = '130'; $t->frame(1); $t->paint();
$now += 50_000_000; $t->frame(1);
$g = glyphAlphas($t->paint());
$faded = count(array_filter($g, fn($v) => $v > 0.05 && $v < 0.95));
check($faded >= 2, "rolling, $faded of " . count($g) . ' glyphs faded');
same([1, true], [$t->rt->pace(60.0), $t->rt->repaints()], 'rolling, it asks for frames that only paint again');
$now += 100_000_000; $t->frame(1);
$g = glyphAlphas($t->paint());
check(count($g) === 3 && min($g) == 1.0, 'over, three opaque glyphs');
$numeric = false; $value = 'hello'; $t->frame(1); $t->paint();
$now += 50_000_000; $t->frame(1);
$g = glyphAlphas($t->paint());
same(8, count($g), 'fading, the old and the new glyphs');
same(8, count(array_filter($g, fn($v) => $v > 0.05 && $v < 0.95)), 'fading, all of them faded');

// An icon replaced: the old shrinks away as the new grows in.
$sa = SVG::parse('<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
$sb = SVG::parse('<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"/></svg>');
$icon = $sa;
$t = poseTester(function (Context $c) use (&$icon, $a) {
    $c->icon($icon, 40)->contentTransition(new ContentTransition(animation: $a));
}, $now, 100, 100);
$count = function (DisplayList $list): array {
    $n = 0; $scaled = 0;
    for ($i = 0; $i < $list->n; $i++) { $kind = $list->buf[$i * 16 + 9]; if (fmod($kind, DisplayList::KIND_STRIDE) == 1.0) { $n++; if ($kind >= DisplayList::KIND_STRIDE) { $scaled++; } } }
    return [$n, $scaled];
};
[$n0] = $count($t->paint());
check($n0 > 0, 'the icon paints');
$icon = $sb; $t->frame(1); $t->paint();
$now += 50_000_000; $t->frame(1);
[$n, $scaled] = $count($t->paint());
check($n === 2 * $n0 && $scaled === $n, "replacing, $n pictures ($n0 at rest), $scaled scaled");
$now += 1_000_000_000; $t->frame(1);
[$n, $scaled] = $count($t->paint());
check($n === $n0 && $scaled === 0, "replaced, $n pictures, $scaled scaled");
