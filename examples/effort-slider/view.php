<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Painter};

// The card is the example's own drawing, and its slider the example's own handling of the pointer, as an NSView in a
// representable; it takes the window.
return fn() => Representable(function (Context $c) {
    $this->prepare($c);
    [$w, $h] = $c->size();
    // Centered across, on whole points so that the card's edges are crisp.
    $ox = round(($w - CARD_W) / 2); $oy = round(CARD_Y + ($h - WINDOW_H) / 2);
    $card = $c->column(0, 0, 1, PAGE_COLOR)->fill()->draw(function (Painter $p, array $r) use ($ox, $oy): void {
        if ($this->drawCard($p, $ox, $oy, $p->now())) { $p->animationFrame(); }
    });
      // The title bar, under the traffic lights, drags the window.
      $c->column()->absolute()->left(0)->top(0)->size($w, max($c->titleBar()['height'], 32))->dragWindow();
      $c->end();
      // The slider takes the keys, as assistive technology sees it, and
      // the pointer across the track; its content box spans the thumb's
      // travel. A drag moves the thumb with the pointer rather than from
      // level to level.
      $sb = $c->sliderBase($this->value, 0, GALAXY, 1)->focusRing(false)->absolute()
          ->left($ox + TRACK_X)->top($oy + TRACK_Y - 6)->size(TRACK_W, TRACK_H + 12)->paddingX(STOP_X - TRACK_X);
      $t = $c->now();
      if ($c->changed($sb) && !$this->holding) { $this->setLevel((int)round($this->value), $t); }
      if ($c->dragged($sb) !== null) {
          [$x] = $c->pointerPosition($sb);
          $this->hold(($x - (STOP_X - TRACK_X)) / STOP_GAP, $t);
      } else {
          $this->release($t);
      }
    $c->end();
    return $card;
}, fills: true);
