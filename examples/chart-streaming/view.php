<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Node, Painter};
use MyPHP\UI\Chart\{Axes, Chart, Linear, Side, Tick};

return function (Context $c): void {
    $this->update($c->now());
    $c->animationFrame();
    $t = $c->theme;
    $x = new Linear(-15, 0);
    $y = new Linear(0, 1);
    $label = fn(Side $side, Tick $tick) => $c->text($tick->label, 11, $t->textMuted)->noWrap();
    $c->column(8, 8, 1, $t->background)->fill();
      Chart::plot($c, new Axes(left: $y->ticks(5), bottom: $x->ticks(5), label: $label), function (Node $area) use ($y, $t): void {
          $area->description = '15 seconds at 1000 samples per second per series';
          $area->label('Three live waveforms')->draw(function (Painter $p, array $r) use ($y, $t): void {
              foreach ($y->ticks(5) as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
              $p->clip($r, function () use ($p, $r): void {
                  foreach (COLORS as $k => $color) {
                      if ($this->mode === 'raw') {
                          $p->strokePath(Chart::line($r, $this->xs, $this->ys[$k]), 2, $color);
                          continue;
                      }
                      // The extremes of each pixel column.
                      [$xs, $ys] = Chart::minMax($this->xs, $this->ys[$k], (int)ceil($r[2] * $p->scale()));
                      if ($this->mode === 'path') {
                          $p->strokePath(Chart::lineInto($this->paths[$k], $r, $xs, $ys), 2, $color);
                          continue;
                      }
                      $points = [];
                      foreach ($xs as $i => $f) { $points[] = [Chart::x($r, $f), Chart::y($r, $ys[$i])]; }
                      $p->strokePolyline($points, 2, $color);
                  }
              });
          });
      })->fill();
    $c->end();
};
