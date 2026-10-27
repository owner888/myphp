<?php
// System materials (Node::systemMaterial): the node paints a hole through
// the content inside its border, its padding and all, rounded as its
// corners inside it, and notes where for the window to place the
// material, with its name, appearance and opacity; what it holds draws over it;
// an unknown name throws.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Context;

$node = null; $show = true; $fade = 1.0;
$h = new Headless(function (Context $c) use (&$node, &$show, &$fade): void {
    $c->column(0, 0, 1)->size(400, 300);
      if ($show) {
          $node = $c->row(10, 0)->systemMaterial('hud', true)->absolute()->left(50)->top(40)->size(200, 80)->round(12)->border(0xffffff, 1)->opacity($fade);
            $c->text('over it');
          $c->end();
      }
    $c->end();
}, 400, 300);
$h->frame();
$list = $h->paint();
same(1, count($list->holes), 'a hole');
$p = $h->rt->materialPlaces[0] ?? null;
same([[51.0, 41.0, 198.0, 78.0], 'hud', true, 11.0, 1.0, $node->id], [$p['frame'] ?? null, $p['material'] ?? null, $p['dark'] ?? null, $p['radius'] ?? null, $p['alpha'] ?? null, $p['id'] ?? null], 'inside its border, padding and all; its material, appearance, corners and opacity');
check($h->byText('over it') !== null, 'what it holds is built over it');
$fade = 0.5; $h->frame(); $h->paint();
same(0.5, $h->rt->materialPlaces[0]['alpha'] ?? null, 'half faded: the material at half its opacity');
$show = false; $h->frame(); $list = $h->paint();
same([[], []], [$list->holes, $h->rt->materialPlaces], 'not built: no hole, no material');
$err = null;
try { (new \MyPHP\UI\Node(\MyPHP\UI\Node::BOX, 0, 0, 0, 0, 0, 0, null, null))->systemMaterial('frosted'); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
same('no system material "frosted"', $err, 'a name of no material throws');
