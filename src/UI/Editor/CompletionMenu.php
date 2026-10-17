<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, Font, Node, Role, ScrollState, Span};

/**
 * An editor's list of completions, as State::$renderCompletions builds it
 * (mygo's CompletionMenu): the completions matching the word typed, best
 * first, and the one chosen, which the arrows move and Enter or Tab
 * inserts.
 */
final class CompletionMenu
{
    /** How many completions the default list shows. */
    public const MAX_ROWS = 200;

    /** What the list was asked while it was built, done once it is, so that the rows after the one clicked still build: the item chosen, -1 for none, and whether it closes. */
    public int $chosen = -1;
    public bool $closing = false;

    public function __construct(private Context $c, private Editor $ed) {}

    /** The completions shown, best first. @return list<Completion> */
    public function items(): array
    {
        $cc = $this->ed->complete;
        return array_map(fn(int $i) => $cc->items[$i], $cc->filtered);
    }

    /** The index in items() of the completion chosen. */
    public function selected(): int { return $this->ed->complete->selected; }

    /** The word typed, which the completions match. */
    public function prefix(): string { return $this->ed->complete->prefix; }

    /**
     * Makes the row of completion $i of items(), a row open to fill and
     * end: selected while chosen, scrolled into view in $scroll as the
     * arrows choose it, at its height $h, and inserting it when clicked.
     */
    public function item(int $i, ScrollState $scroll, float $h): Node
    {
        $cc = $this->ed->complete;
        $row = $this->c->key("item$i")->row()->role(Role::LIST_ITEM)->label($cc->items[$cc->filtered[$i]]->label)->checked($i === $cc->selected);
        $row->selected = false;
        if ($i === $cc->selected && $cc->scrollTo) {
            $top = $i * $h;
            if ($top < $scroll->y) { $scroll->scrollTo($top, false); } elseif ($top + $h > $scroll->y + $scroll->viewport) { $scroll->scrollTo($top + $h - $scroll->viewport, false); }
        }
        if ($this->c->clicked($row)) { $this->choose($i); }
        return $row;
    }

    /** Inserts completion $i of items() in place of the word typed, and closes the list; close closes it. Both happen once the list is built. */
    public function choose(int $i): void
    {
        if ($i >= 0 && $i < count($this->ed->complete->filtered)) { $this->chosen = $i; }
    }

    public function close(): void { $this->closing = true; }

    /** The editor's font size and style bits, the height of its lines, and its colors, to show code as the editor does. */
    public function fontSize(): float { return $this->ed->view->size; }
    public function fontStyle(): int { return $this->ed->view->style; }
    public function lineHeight(): float { return $this->ed->view->lineHeight; }
    public function theme(): Theme { return $this->ed->theme; }

    /**
     * Builds the editor's own list of completions into $panel, as Zed's
     * (mygo's DefaultCompletions): each with its kind, label and detail,
     * nine rows high, and the documentation of the one chosen below them.
     */
    public static function default(Context $c, Node $panel, CompletionMenu $m): void
    {
        $t = $c->theme;
        $ct = $m->theme();
        $size = $m->fontSize();
        $lh = $m->lineHeight();
        $panel->dir = Node::COLUMN; $panel->pad = $t->space(1);
        $panel->color = $t->surface; $panel->round($t->radius)->border($t->border)->shadow(4, 16, 0.2)->min($t->space(60), 0);
        $items = array_slice($m->items(), 0, self::MAX_ROWS);
        $rowH = $lh + 2;
        $list = $c->key('rows')->column();
        $scroll = $c->runtime?->local($list->id, fn() => new ScrollState) ?? new ScrollState;
        $list->scroll($scroll)->size(0, min(count($items), 9) * $rowH);
          foreach ($items as $i => $it) {
              $row = $m->item($i, $scroll, $rowH)->align(Node::CENTER)->padding(0, $t->space(1.5))->round($t->radius - 2)->size(0, $rowH);
              $row->gap = $t->space(2);
              if ($i === $m->selected()) { $row->color = $t->accent; $row->alpha = 0.18; }
                $c->text(Completion::icon($it->kind), $size - 1, $ct->tokenColor(Completion::token($it->kind))[0], Font::MONO)->size($t->space(3), 0);
                $c->text($it->label, $size, $ct->c['text'][0], $m->fontStyle())->single();
                if ($it->detail !== '') { $c->text($it->detail, $size - 1, $t->textMuted)->single(); }
              $c->end();
          }
        $c->end();
        $s = $m->selected();
        if ($s < count($items) && $items[$s]->documentation !== '') {
            $c->box(0, 1, $t->border);
            $c->rich([new Span($items[$s]->documentation)], $t->fontSize - 1, $t->textMuted, $t->space(140));
        }
    }
}
