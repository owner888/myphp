<?php
// Node::systemMaterial where the window's system has no materials
// (Runtime::$systemMaterials false, Platform\Window::hasSystemMaterials:
// Linux): myphp's own fill the node, Glass for glass, its Clear style for
// clear-glass, Vibrancy for the others, no hole nor material place, told
// once in the log; where the system has them, the hole and the place as
// before.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Glass\{Glass, Style, Vibrancy};
use MyPHP\UI\Context;

$nodes = [];
$view = function (Context $c) use (&$nodes): void {
    $c->column(8, 8);
      foreach (['glass', 'clear-glass', 'hud'] as $m) { $nodes[$m] = $c->column()->size(80, 40)->systemMaterial($m); $c->end(); }
    $c->end();
};
$t = new Headless($view);
$t->frame();
same([['glass', null], null], [$nodes['glass']->systemMaterial, $nodes['glass']->material], 'the system\'s: the node notes it, for the hole the window fills');
$t = new Headless($view);
$t->rt->systemMaterials = false;
$t->frame();
same([null, Glass::class, Style::Regular, Style::Clear, Vibrancy::class], [$nodes['glass']->systemMaterial, $nodes['glass']->material::class, $nodes['glass']->material->style, $nodes['clear-glass']->material->style, $nodes['hud']->material::class],
    'none: myphp\'s own, no hole');
check($t->rt->ownMaterialsTold, 'told in the log');
