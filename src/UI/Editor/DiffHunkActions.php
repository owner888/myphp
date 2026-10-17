<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, Node};

/** The actions on a change of a diff, as DiffState::$renderHunkActions builds them (mygo's DiffHunkActions): reverting it, or keeping it. */
final class DiffHunkActions
{
    /** @param int $index the change's, in DiffState::hunks() */
    public function __construct(public int $index, public DiffHunk $hunk, private DiffState $d) {}

    /** Puts the change's old lines back in New; keep makes its new lines Old's, so that it is no longer a change. */
    public function revert(): void { $this->d->revert($this->index); }
    public function keep(): void { $this->d->accept($this->index); }

    /** Builds the viewer's own actions into $bar (mygo's DefaultHunkActions): Revert and Keep buttons. */
    public static function default(Context $c, Node $bar, DiffHunkActions $a): void
    {
        $t = $c->theme;
        $tx = $c->texts();
        $bar->gap = $t->space(1);
        foreach ([['revert', $tx->get('revert'), $a->revert(...)], ['keep', $tx->get('keep'), $a->keep(...)]] as [$key, $label, $fn]) {
            $btn = $c->key($key)->button($label, max(11.0, $t->fontSize - 2))->label($tx->get('hunkAction', ['action' => $label]));
            if ($c->clicked($btn)) { $fn(); }
        }
    }
}
