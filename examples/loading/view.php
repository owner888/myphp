<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Loading\{Cap, Easing, Loading, Options};
use MyPHP\UI\Context;

return function (Context $c): void {
    $t = $c->theme;
    $o = new Options(
        size: SIZES[$this->size],
        color: [$t->text, $t->accent, $t->textMuted][$this->color],
        easing: [Easing::Linear, Easing::EaseInOut, Easing::Stacked][$this->easing],
        cap: [Cap::RoundCap, Cap::FlatCap][$this->cap],
        paused: $this->paused,
    );
    $c->column()->fill();
      $c->row(16, 12)->wrap(0)->alignItems(\MyPHP\UI\Node::CENTER);
        $c->segmented(['16', '24', '32', '48'], $this->size)->label('Size');
        $c->segmented(['Text', 'Accent', 'Muted'], $this->color)->label('Color');
        $c->segmented(['Linear', 'Ease in out', 'Stacked'], $this->easing)->label('Easing');
        $c->segmented(['Round', 'Flat'], $this->cap)->label('Cap');
        $c->checkbox('Paused', $this->paused);
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
};
