<?php
// Memos (Context::memo): a subtree built once and placed again as it was
// laid out while what it was built from is the same; built again when its
// deps change, the pointer is over it or leaves it, it reads keys that
// come, its states move, it is placed at another size, or it animates;
// moved where it goes when what is before it changes; what its build
// registers (the clicks a toggle takes) given back as it is placed again.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Runtime};

$builds = [0, 0, 0];
$on = [false, false, false];
$head = 20.0;
$deps = ['a', 'b', 'c'];
$t = new Headless(function (Context $c) use (&$builds, &$on, &$head, &$deps): void {
    $c->column(0, 0)->fill();
      $c->column()->height($head)->label('head'); $c->end();
      for ($i = 0; $i < 3; $i++) {
          $c->memo("part-$i", [$deps[$i], $on[$i]], function () use ($c, $i, &$builds, &$on): void {
              $builds[$i]++;
              $c->row(0, 8)->height(40)->fillWidth()->label("part-$i");
                $c->text("Part $i")->grow(1);
                $c->toggle($on[$i], '')->label("toggle-$i");
              $c->end();
          });
      }
    $c->end();
}, 400, 300);
$t->input->mouseInside = false;
$t->frame(1);
$first = $t->byLabel('part-1');
$t->frame(3);
same([1, 1, 1], $builds, 'built once, then placed again');
check($t->byLabel('part-1') === $first, 'the same node, kept');
same([0.0, 60.0, 400.0, 40.0], [$first->x, $first->y, $first->w, $first->h], 'as it was laid out');

$deps[1] = 'B';
$t->frame(1);
same([1, 2, 1], $builds, 'its deps changed: that one built again, the others not');

$head = 50.0; // what is before them grows: they move down, not built again
$t->frame(1);
$part = $t->byLabel('part-2');
same([1, 2, 1], $builds, 'moved, not built again');
same(130.0, $part->y, 'placed where it goes now');
same([0.0, 130.0, 400.0, 40.0], $t->rect($part), 'and the runtime has it there');

[$x, $y] = $t->middle($t->byLabel('toggle-2')); // a click on its toggle: it takes it, its value changes
$t->click($x, $y);
same(true, $on[2], 'a click on a toggle in a memo placed again takes it');
same(true, $builds[2] >= 2, 'the pointer there built it again: ' . $builds[2]);

$t->input->mouseInside = false; $t->frame(1);
$was = $builds;
$t->hover(5, 70); // over part-0
same([$was[0] + 2, $was[1], $was[2]], $builds, 'the pointer over one: that one built again, frame by frame');
$t->hover(5, 270); // under them all: the one it left, once more
same([$was[0] + 3, $was[1], $was[2]], $builds, 'the one the pointer left: built once more, then kept');

$t->w = 300; // another width: all built again
$was = $builds;
$t->frame(1);
same([$was[0] + 1, $was[1] + 1, $was[2] + 1], $builds, 'the window narrower: each built again');
same(300.0, $t->byLabel('part-0')->w, 'at its width');

// A memo that builds two nodes, or none, says so.
$bad = new Headless(function (Context $c): void {
    $c->memo('two', [], function () use ($c): void { $c->text('a'); $c->text('b'); });
});
$threw = null;
try { $bad->frame(1); } catch (\LogicException $e) { $threw = $e->getMessage(); }
check($threw !== null && str_contains($threw, 'one node'), 'two nodes in a memo: ' . $threw);

// One that reads keys is built again when they come; one that asks for frames, every frame.
$keyed = 0; $animated = 0; $hits = 0;
$k = new Headless(function (Context $c) use (&$keyed, &$animated, &$hits): void {
    $c->column();
      $c->memo('keys', [], function () use ($c, &$keyed, &$hits): void {
          $keyed++;
          if ($c->shortcut('k', Key::COMMAND)) { $hits++; }
          $c->text('keys');
      });
      $c->memo('moving', [], function () use ($c, &$animated): void {
          $animated++;
          $c->animationFrame();
          $c->text('moving');
      });
    $c->end();
});
$k->input->mouseInside = false;
$k->frame(3);
same(1, $keyed, 'reading keys: kept while none come');
same(3, $animated, 'asking for frames: built every frame');
$k->shortcut('k', Key::COMMAND);
same(1, $hits, 'the shortcut in a memo kept: built again as its key came, and took it');

// A scrolling container inside one: scrolled, it is built again at its offset.
$s = new Headless(function (Context $c): void {
    $c->memo('scroll', [], function () use ($c): void {
        $c->scroll()->size(200, 100)->label('scroller');
          for ($i = 0; $i < 20; $i++) { $c->text("line $i")->height(20); }
        $c->end();
    });
}, 300, 300);
$s->input->mouseInside = false;
$s->frame(3);
$s->input->mouse(50, 50);
$s->input->push(['scroll', 50, 50, 0, -40]);
$s->frame(1);
$s->input->mouseInside = false; $s->frame(2);
$sc = $s->byLabel('scroller');
check($sc->scroll->y > 0 && $sc->kids[0]->y < 0, 'scrolled inside a memo: laid out at its offset: ' . $sc->scroll->y);

// What a build added to the runtime's state is told from what it changed.
same(['xs' => [true, [3]], 'm' => [false, [7 => 'b']]], Runtime::memoAdds(['xs' => [1, 2], 'm' => [5 => 'a'], 'n' => 1], ['xs' => [1, 2, 3], 'm' => [5 => 'a', 7 => 'b'], 'n' => 1], []), 'entries added: given back');
same(null, Runtime::memoAdds(['m' => [5 => 'a']], ['m' => [5 => 'z']], []), 'an entry changed: not kept');
same(null, Runtime::memoAdds(['n' => 1], ['n' => 2], []), 'a value changed: not kept');

// Recorded and painted again as they were: the frame's hit list and quads are those of the page built anew.
$page = function (bool $memo) use (&$on): \Closure {
    return function (Context $c) use ($memo, &$on): void {
        $c->column(8, 6)->fill();
          for ($i = 0; $i < 3; $i++) {
              $part = function () use ($c, $i, &$on): void {
                  $c->row(4, 8)->fillWidth()->radius(6)->background(0x202428)->label("p$i");
                    $c->text("Part $i, told at some length")->grow(1);
                    $c->toggle($on[$i], '');
                  $c->end();
              };
              $memo ? $c->memo("p$i", [$on[$i]], $part) : $part();
          }
        $c->end();
    };
};
$fresh = new Headless($page(false), 400, 300); $fresh->input->mouseInside = false; $fresh->frame(3);
$kept = new Headless($page(true), 400, 300); $kept->input->mouseInside = false; $kept->frame(3);
$a = $fresh->paint(); $quadsFresh = $a->copyOut(0, $a->n);
$b = $kept->paint(); $quadsKept = $b->copyOut(0, $b->n);
check($quadsFresh !== '' && $quadsKept === $quadsFresh, 'painted again: the quads of the page built anew (' . strlen($quadsKept) / 64 . ' quads)');
same(array_map(fn(string $l) => $fresh->rect($fresh->byLabel($l)), ['p0', 'p1', 'p2']), array_map(fn(string $l) => $kept->rect($kept->byLabel($l)), ['p0', 'p1', 'p2']), 'recorded again: the same rects');
same(count($fresh->tabStops()), count($kept->tabStops()), 'and the same focus order');
$fresh->tab(); $kept->tab(); // a key comes, the focus moves into the first: built again, its toggle ringed
check($kept->focused() !== null && $kept->focused()->kind === $fresh->focused()?->kind, 'Tab into a memo: its toggle focused');
$a = $fresh->paint(); $b = $kept->paint();
same($a->copyOut(0, $a->n), $b->copyOut(0, $b->n), 'and painted as the page built anew, ring and all');
$fresh->input->mouseInside = $kept->input->mouseInside = false;
$fresh->frame(2); $kept->frame(2);
$a = $fresh->paint(); $b = $kept->paint();
same($a->copyOut(0, $a->n), $b->copyOut(0, $b->n), 'frames on, still as the page built anew');
