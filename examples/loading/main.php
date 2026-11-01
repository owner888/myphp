<?php
// Loading shows the 29 loading indicators of the loading plugin, after
// loading.dev, in native UI, with their options: size, color, easing, the
// ends of their strokes, and a pause (mygo's examples/loading).
// Run: ./run.sh loading
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Loading\{Cap, Easing, Loading, Options};
use MyPHP\UI\{Content, Context};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** What the window shows: the options chosen. */
final class Gallery
{
    public int $size = 1, $color = 0, $easing = 0, $cap = 0;
    public bool $paused = false;
}

const SIZES = [16, 24, 32, 48];

function view(Context $c, Gallery $g): void
{
    $t = $c->theme;
    $o = new Options(
        size: SIZES[$g->size],
        color: [$t->text, $t->accent, $t->textMuted][$g->color],
        easing: [Easing::Linear, Easing::EaseInOut, Easing::Stacked][$g->easing],
        cap: [Cap::RoundCap, Cap::FlatCap][$g->cap],
        paused: $g->paused,
    );
    $c->column()->fill();
      $c->row(16, 12)->wrap(0)->alignItems(\MyPHP\UI\Node::CENTER);
        $c->segmented(['16', '24', '32', '48'], $g->size)->label('Size');
        $c->segmented(['Text', 'Accent', 'Muted'], $g->color)->label('Color');
        $c->segmented(['Linear', 'Ease in out', 'Stacked'], $g->easing)->label('Easing');
        $c->segmented(['Round', 'Flat'], $g->cap)->label('Cap');
        $c->checkbox('Paused', $g->paused);
      $c->end();
      $c->scroll()->grow(1);
        $c->row(0, 12)->wrap(0)->padding(0, 16, 16, 16);
          foreach (Loading::all() as $s) {
              $c->column(0, 14)->width(132)->height(116)->center()->radius($t->radius)->background($t->surface)->border(1, $t->border);
                $c->column()->height(48)->center();
                  ($s->new)($c, $o)->label($s->name);
                $c->end();
                $c->text($s->name, 12, $t->textMuted);
              $c->end();
          }
        $c->end();
      $c->end();
    $c->end();
}

$s = new Gallery();
App::setName('Loading');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Loading', width: 780, height: 640, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
