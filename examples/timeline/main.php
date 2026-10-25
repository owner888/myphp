<?php
// What a Timeline is for (mygo's examples/timeline): several tweens on one
// playhead, arranged and controlled together. The card slides in as it
// grows, its title follows it, the rows come one after another, and the
// card bounces a little as it lands; Play, Pause, Reverse, Restart and the
// scrubber move the whole intro together. The timeline asks the window for
// frames only while it moves.
// Run: ./run.sh timeline
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Easing, Font, Node, Placement, TimelineOptions, Tween};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const WINDOW_W = 520;
const WINDOW_H = 480;

/** What the intro's controls set, and what its last frame showed. */
final class Intro
{
    /** Plays the intro again when it changes. */
    public int $run = 0;
    public bool $paused = false, $reverse = false;
    /** The scrubber, whether the pointer held it last frame, and the playhead it asks for while it does. */
    public float $scrub = 0.0;
    public bool $held = false;
}

function view(Context $c, Intro $s): void
{
    $t = $c->theme;
    // The pointer held the scrubber: the playhead stays where it put it, and goes on from there when it is let go.
    $o = new TimelineOptions(run: $s->run, paused: $s->paused, reverse: $s->reverse, progress: $s->held ? $s->scrub : null);
    $c->column(24, 18, 1)->align(Node::CENTER)->justify(Node::CENTER);
      // One playhead for the whole intro, kept by the card.
      $card = $c->key('card')->column(22, 10, 0, $t->surface)->size(300, 0)->round(16);
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
        if ($c->clicked($c->key('restart')->primaryButton('Restart'))) { $s->run++; }
        if ($c->clicked($c->key('pause')->button('Pause')->disabled($s->paused))) { $s->paused = true; }
        if ($c->clicked($c->key('play')->button('Play')->disabled(!$s->paused))) { $s->paused = false; }
        if ($c->clicked($c->key('reverse')->button('Reverse'))) { $s->reverse = !$s->reverse; }
      $c->end();
      $c->row(0, 10);
        $sl = $c->slider($s->scrub, 0, 1, 0)->grow(1);
        $s->held = $c->dragging($sl);
        if (!$s->held) { $s->scrub = $tl->progress(); }
        $c->text(sprintf('%d / %d ms', round($tl->time() * 1000), round($tl->duration() * 1000)), 12, $t->textMuted)->size(96, 0);
      $c->end();
    $c->end();
}

$s = new Intro();
App::setName('Timeline');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Timeline', width: WINDOW_W, height: WINDOW_H, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
