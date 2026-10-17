<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, DisplayList, Key, Node, Path, Theme as UITheme};

/**
 * A separator of a diff, as DiffState::$renderSeparator builds it (mygo's
 * DiffSeparator): unchanged lines collapsed between changes, which it
 * expands, or the header of a patch's hunk. The viewer places it, a
 * column as wide as the viewer and 32 points high, open as the render
 * function is called.
 */
final class DiffSeparator
{
    /** How many unchanged lines it collapses, 0 for a header. */
    public int $lines = 0;
    /** How many lines expandUp and expandDown show, the DiffState's expansionLines. */
    public int $step = 0;
    /** It is before the first change, or after the last, which expand one way only: up toward the change, or down from it. */
    public bool $first = false, $last = false;
    /** The header of a patch's hunk (DiffState::setPatch), as "@@ -12,7 +12,8 @@ func main", for a separator of no lines. */
    public string $header = '';
    /** The old line its gap starts at. */
    public int $a = 0;

    public function __construct(private DiffState $d, private Theme $theme, private float $fontSize, private int $fontStyle) {}

    /** Show $step more of its lines, those right above the change below it (up), those right below the change above it (down), or all of them. */
    public function expandUp(): void { $this->d->expand($this->a, -1, false); }
    public function expandDown(): void { $this->d->expand($this->a, 1, false); }
    public function expandAll(): void { $this->d->expand($this->a, 1, true); }

    /** The viewer's font size and style bits, and its colors. */
    public function fontSize(): float { return $this->fontSize; }
    public function fontStyle(): int { return $this->fontStyle; }
    public function theme(): Theme { return $this->theme; }

    /**
     * Builds the viewer's own separator into $sep, as Pierre's (mygo's
     * DefaultSeparator): a bar with chevrons expanding the lines up and
     * down, or all of them when they are few, and "N unmodified lines",
     * which expands them all; or a hunk's header. Shift expands all the
     * lines from any button.
     */
    public static function default(Context $c, Node $sep, DiffSeparator $s): void
    {
        $t = $c->theme;
        $tx = $c->texts();
        $ct = $s->theme();
        $sep->pad = 4;
        $bg = Theme::mixLab($ct->c['background'], $ct->c['text'], 0.06);
        $bar = $c->row(0, $t->space(0.5), 1, $bg[0])->align(Node::CENTER)->padding(0, $t->space(1.5))->round(6)->alignSelf(Node::STRETCH);
        $bar->alpha = $bg[1];
          if ($s->lines === 0) {
              $c->text($s->header, $s->fontSize() - 1, $t->textMuted, $s->fontStyle())->single();
          } else {
              $chevron = function (int $dir, string $label, \Closure $fn) use ($c, $t, $ct, $s) {
                  $b = $c->key($label)->row()->size(20, 20)->round(4)->label($label)->tooltip($label)->cursor('pointer')->focusable();
                  if ($c->hovered($b)) { $b->color = $ct->c['text'][0]; $b->alpha = $ct->c['text'][1] * 0.08; }
                  $b->drawOver(function (DisplayList $list, Node $r, UITheme $th) use ($dir) {
                      $cx = $r->x + $r->w / 2; $cy = $r->y + $r->h / 2; $d = 3.5;
                      $path = match ($dir) {
                          1 => (new Path)->moveTo($cx - $d, $cy - $d / 2)->lineTo($cx, $cy + $d / 2)->lineTo($cx + $d, $cy - $d / 2),
                          -1 => (new Path)->moveTo($cx - $d, $cy + $d / 2)->lineTo($cx, $cy - $d / 2)->lineTo($cx + $d, $cy + $d / 2),
                          default => (new Path)->moveTo($cx - $d, $cy - 2)->lineTo($cx, $cy - 2 - $d)->lineTo($cx + $d, $cy - 2)
                              ->moveTo($cx - $d, $cy + 2)->lineTo($cx, $cy + 2 + $d)->lineTo($cx + $d, $cy + 2),
                      };
                      $list->path($path, $th->textMuted, 1.0, 1.5);
                  });
                  $c->end();
                  if ($c->clicked($b)) {
                      if (($c->runtime?->clickMods ?? 0) & Key::SHIFT) { $fn = $s->expandAll(...); }
                      $fn();
                  }
              };
              if ($s->first) {
                  $chevron(-1, $tx->get('expandUp'), $s->expandUp(...));
              } elseif ($s->last) {
                  $chevron(1, $tx->get('expandDown'), $s->expandDown(...));
              } elseif ($s->lines <= $s->step) {
                  $chevron(0, $tx->get('expandAll'), $s->expandAll(...));
              } else {
                  $chevron(1, $tx->get('expandDown'), $s->expandDown(...));
                  $chevron(-1, $tx->get('expandUp'), $s->expandUp(...));
              }
              $label = $tx->get('unmodifiedLines', ['n' => $s->lines]);
              $b = $c->key('all')->row()->padding($t->space(0.5), $t->space(1))->round(4)->cursor('pointer')->focusable()->label($tx->get('expand', ['what' => $label]));
                $c->text($label, max($t->fontSize - 1, 11), $t->textMuted);
              $c->end();
              if ($c->clicked($b)) { $s->expandAll(); }
          }
        $c->end();
    }
}
