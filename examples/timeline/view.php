<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Easing, Font, Node, Placement, TimelineOptions, Tween};

return function (Context $c): void {
    $t = $c->theme;
    // The pointer held the scrubber: the playhead stays where it put it, and goes on from there when it is let go.
    $o = new TimelineOptions(run: $this->run, paused: $this->paused, reverse: $this->reverse, progress: $this->held ? $this->scrub : null);
    $c->column(24, 18, 1)->alignItems(Node::CENTER)->justify(Node::CENTER);
      // One playhead for the whole intro, kept by the card.
      $card = $c->key('card')->column(22, 10, 0, $t->surface)->size(300, 0)->radius(16);
      $tl = $c->timeline($card, 'intro', $o);
      $slide = $tl->tween(new Tween(-420, 0, 0.45));
      $grow = $tl->tween(new Tween(0.9, 1, 0.45), Placement::withPrevious(0));
      $fade = $tl->tween(new Tween(0, 1, 0.3, Easing::linear(...)), Placement::atTime(0.15));
      $tl->label('rows'); // where the card lands, at 450 ms
      $rows = [];
      foreach (['Plan', 'Build', 'Ship'] as $i => $name) {
          $rows[$name] = $tl->tween(new Tween(0, 1, 0.24, Easing::linear(...)), Placement::atLabel('rows', $i * 0.09));
      }
      $bounce = $tl->tween(new Tween(10, 0, 0.36, Easing::bounce(Easing::easeOut(...))), Placement::atTime(0.45));
      $card->translate($slide, $bounce)->scale($grow, $grow);
        $c->text('A timeline', 20, $t->text, Font::BOLD)->opacity($fade)->translate(0, (1 - $fade) * 12);
        foreach ($rows as $name => $v) {
            $c->row(0, 8)->opacity($v)->translate(0, (1 - $v) * 14);
              $c->muted($name);
            $c->end();
        }
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->key('restart')->primaryButton('Restart'))) { $this->run++; }
        if ($c->clicked($c->key('pause')->button('Pause')->disabled($this->paused))) { $this->paused = true; }
        if ($c->clicked($c->key('play')->button('Play')->disabled(!$this->paused))) { $this->paused = false; }
        if ($c->clicked($c->key('reverse')->button('Reverse'))) { $this->reverse = !$this->reverse; }
      $c->end();
      $c->row(0, 10);
        $sl = $c->slider($this->scrub, 0, 1, 0)->grow(1);
        $this->held = $c->dragging($sl);
        if (!$this->held) { $this->scrub = $tl->progress(); }
        $c->text(sprintf('%d / %d ms', round($tl->time() * 1000), round($tl->duration() * 1000)), 12, $t->textMuted)->size(96, 0);
      $c->end();
    $c->end();
};
