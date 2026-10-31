<?php
// Indicators and the controls of a value (mygo's indicators_test.go
// TestSpinner, TestMeter, TestRating, TestStepper, TestStepSlider,
// TestRangeSlider, TestAvatar, TestInitials; TestSpinnerColor is in
// spinner_test): what assistive technology sees of them, and what clicks
// and keys set.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, AccessTree, Context, Key, MeterLevels, Node, Role};

/** The node of $tree with $role named $label exactly. */
function indNode(AccessTree $tree, int $role, string $label): AccessNode
{
    foreach ($tree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label");
}

/** A tester with assistive technology on. */
function indTester(\Closure $view, float $w, float $h): Headless
{
    $t = new Headless($view, $w, $h);
    $t->frame();
    $t->input->push(['accessOn']);
    $t->frame();
    return $t;
}

// A spinner is a progress of unknown length; painted, it asks for a frame for its next spoke, none before.
$h = indTester(function (Context $c): void { $c->spinner()->label('Loading'); }, 200, 100);
$n = indNode($h->rt->accessTree, Role::PROGRESS, 'Loading');
check($n->now < $n->min, 'the spinner is of unknown length: ' . json_encode([$n->now, $n->min]));
$h->paint();
check(!$h->rt->needsFrame && ($h->rt->nextWake() ?? 1) <= 0.075, 'it is painted again for its next spoke: ' . json_encode($h->rt->nextWake()));

// A meter in the success color, the warning and danger colors at its levels, low ones for a battery.
$levels = new MeterLevels(warning: 70, critical: 90); $battery = new MeterLevels(warning: 20, critical: 10);
$meters = [];
$h = indTester(function (Context $c) use ($levels, $battery, &$meters): void {
    $c->column(10, 10)->alignItems(Node::START);
      foreach (['Fine' => [50, $levels], 'Warning' => [80, $levels], 'Critical' => [95, $levels], 'Battery' => [15, $battery]] as $name => [$v, $l]) {
          $meters[$name] = $c->meter($v, 0, 100, $l, 100)->label($name);
      }
    $c->end();
}, 200, 200);
$th = $h->theme;
foreach (['Fine' => $th->success, 'Warning' => $th->warning, 'Critical' => $th->danger, 'Battery' => $th->warning] as $name => $color) {
    $n = indNode($h->rt->accessTree, Role::METER, $name);
    check($n->min == 0 && $n->max == 100, "$name: from 0 to 100");
    same($color, $meters[$name]->color, "$name: its color");
}

// A rating: a click on a star sets it, on the star set clears it; the arrows and End; what it says.
$stars = 0; $changes = 0;
$h = indTester(function (Context $c) use (&$stars, &$changes): void {
    $c->column()->alignItems(Node::START);
      if ($c->changed($c->rating($stars, 5)->label('Rating'))) { $changes++; }
    $c->end();
}, 300, 100);
$r = indNode($h->rt->accessTree, Role::SLIDER, 'Rating')->bounds;
$star = $h->theme->fontSize * 1.25 + $h->theme->space(0.5);
$h->click($r[0] + $star * 2.5, $r[1] + $r[3] / 2);
check($stars === 3 && $changes === 1, "a click on the third star: $stars ($changes changes)");
$h->click($r[0] + $star * 2.5, $r[1] + $r[3] / 2);
same(0, $stars, 'a second click clears it');
$h->right(); $h->right();
same(2, $stars, 'Right twice');
$h->end();
$n = indNode($h->rt->accessTree, Role::SLIDER, 'Rating');
check($stars === 5 && $n->now == 5 && $n->max == 5 && ($n->actions & AccessNode::ACTION_INCREMENT) !== 0, 'End: five, and it says so');

// A stepper: clicks on its arrows, Up and End, the top, assistive technology's decrement, and held.
$v = 5.0; $now = 0;
$h = poseTester(function (Context $c) use (&$v): void { $c->stepper($v, 0, 10, 0.5)->label('Copies'); }, $now, 200, 200);
$h->input->push(['accessOn']); $h->frame();
$b = indNode($h->rt->accessTree, Role::STEPPER, 'Copies')->bounds;
[$ux, $uy] = [$b[0] + $b[2] / 2, $b[1] + $b[3] / 4]; [$dx, $dy] = [$b[0] + $b[2] / 2, $b[1] + $b[3] * 3 / 4];
$h->click($ux, $uy);
same(5.5, $v, 'a click on the up arrow');
$h->click($dx, $dy);
same(5.0, $v, 'a click on the down arrow');
$h->up(); $h->end();
same(10.0, $v, 'Up and End');
$h->click($ux, $uy);
same(10.0, $v, 'not past the top');
$n = indNode($h->rt->accessTree, Role::STEPPER, 'Copies');
check($n->max == 10 && ($n->actions & AccessNode::ACTION_DECREMENT) !== 0, 'it can be decremented');
$h->input->push(['access', $n->id, AccessNode::DECREMENT]); $h->frame();
same(9.5, $v, 'decremented');
$h->press($dx, $dy);
for ($k = 0; $k < 7; $k++) { $now += 100_000_000; $h->frame(); }
$h->release($dx, $dy);
check($v <= 8, "held 0.7 s, it keeps stepping down: $v");

// A step slider snaps to its steps: a click at 60 % sets 50; Right steps.
$v = 20.0;
$h = indTester(function (Context $c) use (&$v): void {
    $c->column()->alignItems(Node::START);
      $c->stepSlider($v, 0, 100, 25, 220)->label('Quality');
    $c->end();
}, 300, 100);
$r = indNode($h->rt->accessTree, Role::SLIDER, 'Quality')->bounds;
$kw = $h->theme->space(5);
$h->click($r[0] + $kw / 2 + ($r[2] - $kw) * 0.6, $r[1] + 10);
same(50.0, $v, 'a click at 60 %');
$h->right();
same(75.0, $v, 'Right');

// A range slider: its two knobs, a press moving the nearest, Tab between them, End stopping the low one at the high one.
$low = 20.0; $high = 80.0;
$h = indTester(function (Context $c) use (&$low, &$high): void {
    $c->column()->alignItems(Node::START);
      $c->rangeSlider($low, $high, 0, 100, 10, 220)->label('Price');
    $c->end();
}, 300, 100);
check(indNode($h->rt->accessTree, Role::SLIDER, 'Price minimum')->now == 20 && indNode($h->rt->accessTree, Role::SLIDER, 'Price maximum')->now == 80, 'the knobs');
$r = indNode($h->rt->accessTree, Role::GROUP, 'Price')->bounds;
$h->click($r[0] + $kw / 2 + ($r[2] - $kw) * 0.68, $r[1] + 10);
check($high == 70 && $low == 20, "a click at 68 %: $low to $high");
$h->tab(true);
$h->right();
same(30.0, $low, 'Right on the low knob');
$h->end();
same(70.0, $low, 'End stops the low knob at the high one');

// An avatar shows the initials, as an image named for the person.
$h = indTester(function (Context $c): void {
    $c->row(0, 8); $c->avatar('Ada Lovelace'); $c->avatar('grace'); $c->end();
}, 200, 100);
check($h->byText('AL') !== null && $h->byText('G') !== null, 'the initials');
indNode($h->rt->accessTree, Role::IMAGE, 'Ada Lovelace');

// Initials, and the color: the hue of the name's FNV-1a hash.
foreach (['Ada Lovelace' => 'AL', 'grace' => 'G', '  jean-luc  picard ' => 'JP', 'Grace Brewster Hopper' => 'GH', '李 小龙' => '李小', '--' => '', '' => '', 'élodie 2nd' => 'É2', "o'brien" => 'OB', "Ada\tLovelace\nByron " => 'AB'] as $name => $want) {
    same($want, Context::initials((string)$name), 'initials of ' . json_encode($name));
}
$color = null;
$h = new Headless(function (Context $c) use (&$color): void { $color = $c->avatar('Ada Lovelace')->color; }, 100, 100);
$h->frame();
same(Context::hsl(hexdec(hash('fnv1a32', 'Ada Lovelace')) % 360, 0.45, 0.55), $color, 'the color of the hash\'s hue');
