<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Animation, ConicGradient, Context, Cursor, Key, Node, Painter, Path};

/** The empty conversation: the orb, a greeting, and suggestions to ask. */
$welcome = function (Context $c, ChatGlow $a): void {
    $t = $c->theme;
    $c->column(0, 10, 1)->alignItems(Node::CENTER)->justify(Node::CENTER)->padding(40, 24, 0, 24);
      $c->column()->size(150, 150)->draw($a->drawOrb(...))->label('Assistant');
      $c->end();
      $c->text('How can I help?', 28, $t->text)->fontWeight(600)->margin(4, 0, 0, 0);
      $c->text('Ask anything, or start with one of these.', 14, $t->textMuted);
      // Wrapped at the column's width inside its padding.
      $c->row(0, 8)->wrap($c->width - 48)->justify(Node::CENTER)->margin(18, 0, 0, 0);
        foreach (SUGGESTIONS as $s) {
            $chip = $c->key($s)->column()->padding(9, 14)->radius(999)->border(1, $t->border)->label($s)->cursor(Cursor::Pointer);
            if ($c->hovered($chip)) {
                $chip->background($t->surfaceHover);
            }
              $c->text($s, 13, $t->text);
            $c->end();
            if ($c->clicked($chip)) {
                $a->send($s);
            }
        }
      $c->end();
    $c->end();
};

/** The messages, following the end as replies grow. */
$conversation = function (Context $c, ChatGlow $a) use (&$message): void {
    $a->list->key = fn(int $i) => $a->messages[$i]->id;
    $a->list->followEnd = true;
    // Glide up as the reply grows, but jump to the end when much came at
    // once, as when the window shows again after it was hidden.
    $shown = 0;
    foreach ($a->messages as $m) {
        $shown += $m->shown;
    }
    $a->list->followAnimation = $shown - $a->seen > JUMP_WORDS ? null : Animation::smooth();
    $a->seen = $shown;
    $c->list($a->list, count($a->messages), function (int $i) use ($c, $a, $message): void {
        // Each message in the middle, the list as wide as the window, so
        // that it scrolls wherever the pointer is.
        $c->row()->justify(Node::CENTER);
          $c->column(0, 0, 1)->maxWidth(MAX_WIDTH);
            $message($c, $a, $a->messages[$i]);
          $c->end();
        $c->end();
    })->grow(1)->padding(52, 24, 16, 24)->gap(22)->justify(Node::END);
};

/** A message: the user's in a bubble on the right, the assistant's beside its orb, thinking until it writes. */
$message = function (Context $c, ChatGlow $a, Message $m): void {
    $t = $c->theme;
    if ($m->mine) {
        $c->row();
          $c->spacer();
          $c->text($m->text)->selectable()->padding(9, 14)->radius(18)->background($t->surface)->maxWidth(460);
        $c->end();
        return;
    }
    $last = $m === $a->messages[count($a->messages) - 1];
    $c->row(0, 12)->alignItems(Node::START);
      $avatar = $c->column()->size(30, 30)->margin(-3, 0, 0, 0);
      $avatar->draw($last ? $a->drawOrb(...) : fn(Painter $p, array $r) => $a->drawStillOrb($p, $r, $m->id));
      $c->end();
      if ($m->shown === 0 && $last && $a->busy) {
          $c->column()->size(120, REPLY_SIZE * REPLY_LEADING)->label('Thinking')->draw(fn(Painter $p, array $r) => $a->drawThinking($p, $r, $c->theme));
          $c->end();
      } else {
          $c->text(implode('', array_slice($m->words, 0, $m->shown)), REPLY_SIZE, $t->text)->selectable()->lineHeight(REPLY_LEADING)->grow(1)->minWidth(0);
      }
    $c->end();
};

/** The field to write in, in its glowing ring, and the button that sends, or stops the assistant. */
$composer = function (Context $c, ChatGlow $a) use (&$drawSendIcon): void {
    $t = $c->theme;
    $c->row()->justify(Node::CENTER)->padding(0, 24 - RING_PADDING, 22 - RING_PADDING, 24 - RING_PADDING);
      $c->row(0, 0, 1)->maxWidth(MAX_WIDTH + 2 * RING_PADDING)->padding(RING_PADDING)->draw($a->drawRing(...));
        $c->row(0, 8, 1)->alignItems(Node::CENTER)->padding(6, 6, 6, 16)->radius(FIELD_RADIUS)->background($t->surface);
          $in = $c->input($a->input, 'Ask anything', 0)->grow(1)->autoFocus()->background(0, 0.0)->border(0, null)->padding(8, 0)->label('Message');
          $ready = trim($a->input->text) !== '';
          $send = $c->column()->size(34, 34)->radius(17)->label($a->busy ? 'Stop' : 'Send')->cursor(Cursor::Pointer)->background($a->busy || $ready ? $t->text : $t->border);
          $send->draw(fn(Painter $p, array $r) => $drawSendIcon($p, $r, $a->busy, $t->background));
          $c->end();
          if ($a->busy) {
              if ($c->clicked($send)) {
                  $a->stop();
              }
          } elseif (($c->clicked($send) || $c->submitted($in)) && $ready) {
              $a->send($a->input->text);
          }
        $c->end();
      $c->end();
    $c->end();
};

/** The button's icon: an arrow up, or a square while the assistant works. @param array{0: float, 1: float, 2: float, 3: float} $r */
$drawSendIcon = function (Painter $p, array $r, bool $busy, int $color): void {
    [$cx, $cy] = [$r[0] + $r[2] / 2, $r[1] + $r[3] / 2];
    if ($busy) {
        $p->fill([$cx - 5, $cy - 5, 10, 10], $color, 2);
        return;
    }
    $arrow = (new Path())->moveTo($cx, $cy + 7)->lineTo($cx, $cy - 7)->moveTo($cx - 6, $cy - 1)->lineTo($cx, $cy - 7)->lineTo($cx + 6, $cy - 1);
    $p->strokePath($arrow, 2.2, $color);
};

/**
 * The window's edges glowing while the assistant works: the window's
 * outline stroked with a conic gradient of the glow's colors, their stops
 * moving about, a crisp line and lines blurred over it, which the pointer
 * goes through.
 */
$edge = function (Context $c, ChatGlow $a): void {
    if ($a->glow <= 0.001) {
        return;
    }
    // From the right, as SwiftUI's AngularGradient goes, blended in Oklab
    // as SwiftUI blends it.
    $g = new ConicGradient($a->stops->gradient($a->now), angle: 90, oklab: true);
    $c->column()->absolute()->top(0)->left(0)->right(0)->bottom(0)->passThrough()->opacity(ease($a->glow));
      foreach (GLOW_LAYERS as [$width, $blur]) {
          $c->column()->absolute()->top(0)->left(0)->right(0)->bottom(0)->passThrough()->radius(CORNER)
              ->border($width)->borderConicGradient($g)->blur(0.95 * $blur);
          $c->end();
      }
    $c->end();
};

return function (Context $c) use (&$welcome, &$conversation, &$composer, &$edge): void {
    $t = $c->theme;
    $this->still = $c->preferences()->reduceMotion;
    $this->tick($c->now());
    if ($this->moving()) {
        $c->animationFrame();
    }
    if ($c->shortcut(Key::ESCAPE) && $this->busy) {
        $this->stop();
    }

    $c->column(0, 0, 0, $t->background)->fill();
      if ($this->messages === []) {
          $welcome($c, $this);
      } else {
          $conversation($c, $this);
      }
      $composer($c, $this);
      // The title bar, under the window controls, drags the window.
      $c->column()->absolute()->top(0)->left(0)->right(0)->height(max($c->titleBar()['height'], 32))->dragWindow();
      $c->end();
      // The edges' glow, over everything, which the pointer goes through.
      $edge($c, $this);
    $c->end();
};
