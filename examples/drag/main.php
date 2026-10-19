<?php
// Draggable nodes: a card the pointer drags anywhere, which stays where it
// is let go; a puck thrown along a track to the nearest of three stops, at
// the pointer's speed; a sheet pulled up or down to its heights, rubber-
// banded past them, lifting as it is dragged; words dragged into a basket,
// which shows when one is over it (Escape gives a drag up).
// Run: ./run.sh drag
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Animation, Content, Context, DisplayList, DraggableOptions, Font, Motion, Node, Pose};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = (object)['card' => [0.0, 0.0], 'puck' => [0.0, 0.0], 'sheet' => [0.0, 180.0], 'words' => ['apple', 'pear', 'plum'], 'basket' => []];

function view(Context $c, object $s): void
{
    $t = $c->theme;
    $c->column(20, 16, 1);
      $c->text('Drag', 20, style: Font::BOLD);
      $c->row(0, 0)->size(0, 90);
        $card = $c->draggable($c->column(12, 4, 0, $t->surface)->round(10)->border($t->border)->size(160, 70), $s->card);
          $c->text($c->dragging($card) ? 'Dragging…' : 'Drag me', 15, style: Font::BOLD);
          $c->muted(sprintf('%.0f, %.0f', ...$s->card), 12);
        $c->end();
        $card->animatePose($c->dragging($card) ? new Pose(scale: 1.04) : new Pose(), Animation::snappy());
      $c->end();
      $c->muted('Throw the puck: let go, it glides to the stop nearest to where the throw would carry it — the faster, the farther.', 12);
      $c->column(0, 0, 0, $t->surface)->round(24)->size(460, 48)->draw(function (DisplayList $l, Node $n) use ($t) {
          foreach ([0, 206, 412] as $stop) { // the stops the puck rests at, under it
              $l->rect($n->x + $stop + 18, $n->y + 18, 12, 12, $t->textMuted, 0.5, 6);
          }
      });
        $c->draggable($c->box(48, 48, $t->accent)->round(24), $s->puck, new DraggableOptions(Node::HORIZONTAL, bounds: [0, 0, 412, 0], rigid: true, snap: Motion::nearest([0, 0], [206, 0], [412, 0])));
      $c->end();
      $c->row(0, 16);
        foreach ($s->words as $w) {
            $c->drag($c->row(10, 0, 0, $t->surface)->round(8)->border($t->border), $w);
              $c->text($w);
            $c->end();
        }
        $basket = $c->column(10, 4, 0, $t->surface)->round(8)->size(180, 90);
          $c->text('Basket', 13, style: Font::BOLD);
          $c->muted($s->basket === [] ? 'Drop words here' : implode(', ', $s->basket), 12);
        $c->end();
        if ($c->dragOver($basket, 'string')) { $basket->color = $t->surfaceHover; $basket->border($t->accent, 2); }
        if ($c->drop($basket, 'string', $word)) {
            $s->basket[] = $word;
            $s->words = array_values(array_diff($s->words, [$word]));
        }
      $c->end();
    $c->end();
    // A sheet at the bottom, below the rest even pulled all the way up: it rests at three heights, past them on a rubber band.
    $sheet = $c->draggable($c->column(16, 8, 0, $t->surface)->at(0, $c->height - 240)->size($c->width, 240)->round(16)->shadow(-4, 24, 0.2), $s->sheet,
        new DraggableOptions(Node::VERTICAL, bounds: [0, 0, 0, 180], snap: Motion::nearest([0, 0], [0, 90], [0, 180])));
      $c->box(40, 5, $t->border)->round(3)->alignSelf(Node::CENTER);
      $c->text('A sheet', 16, style: Font::BOLD);
      $c->muted($c->dragging($sheet) ? 'Let go to rest at the nearest height.' : 'Pull it up or down: it rests at three heights.', 12);
    $c->end();
}

App::setName('Drag');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Drag', width: 640, height: 720,
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
