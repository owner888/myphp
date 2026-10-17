<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, Key, Node, TextState};

/**
 * An editor's find bar, as State::$renderFindBar builds it (mygo's
 * FindBar): the query and its options, which the matches follow as they
 * change, and what the bar does. The editor places the bar, a row open
 * as the render function is called, to style and fill.
 */
final class FindBar
{
    public function __construct(private Context $c, private Editor $ed) {}

    /** The fields of the query and the replacement, to bind inputs to, with queryInput and replacementInput. */
    public function query(): TextState { return $this->ed->find->queryField; }
    public function replacement(): TextState { return $this->ed->find->replaceField; }

    /**
     * The options of the search, to bind toggles to: matching case, whole
     * words only, the query as a regular expression (PCRE), and only
     * within the selections there were as the option was turned on.
     */
    public function &caseSensitive(): bool { return $this->ed->find->caseSens; }
    public function &wholeWord(): bool { return $this->ed->find->wholeWord; }
    public function &regexp(): bool { return $this->ed->find->regex; }
    public function &inSelection(): bool { return $this->ed->find->inSelection; }

    /** Whether the bar replaces too, as Cmd+Alt+F opens it; setReplacing shows or hides replacing, which canReplace reports a read-only editor never does. */
    public function replacing(): bool { return $this->ed->find->replacing && !$this->ed->o->readOnly; }
    public function setReplacing(bool $on): void { $this->ed->find->replacing = $on; }
    public function canReplace(): bool { return !$this->ed->o->readOnly; }

    /** How many matches there are, and the index of the one selected, -1 without matches. @return array{0: int, 1: int} */
    public function matches(): array
    {
        $fd = $this->ed->find;
        $fd->search($this->ed);
        return $fd->matches === [] ? [0, -1] : [count($fd->matches), $fd->current];
    }

    /** What was found, as "3 of 12", "No results", or "Invalid" for a regular expression that is not one (err); nothing without a query. */
    public function status(): string { return $this->ed->find->status($this->c->texts()); }

    /** The error of a query that is not a valid regular expression. */
    public function err(): ?string { return $this->ed->find->err; }

    /** Select the next and previous match after the caret, round the ends; selectAll selects every match, with a caret each, and closes the bar. */
    public function next(): void { $this->ed->find->step($this->ed, 1); }
    public function previous(): void { $this->ed->find->step($this->ed, -1); }
    public function selectAll(): void { $this->ed->find->selectAll($this->ed); }

    /** Replaces the match selected and selects the next; replaceAll replaces every match, as one step of undo. A regular expression's replacement expands $1 and ${name}. */
    public function replace(): void { $this->sync(); $this->ed->find->replaceOne($this->ed); }
    public function replaceAll(): void { $this->sync(); $this->ed->find->replaceAll($this->ed); }

    /** Closes the bar, and gives the keyboard back to the text. */
    public function close(): void
    {
        $this->ed->find->open = false;
        $this->ed->refocus = true;
    }

    /** The texts of the fields, into what the search goes by. */
    private function sync(): void
    {
        $fd = $this->ed->find;
        $fd->query = $fd->queryField->text;
        $fd->replace = $fd->replaceField->text;
    }

    /**
     * Makes $in the field of the query: it takes the focus with the query
     * selected as the bar opens, and Enter goes to the next match,
     * Shift+Enter to the previous, Alt+Enter selects them all, and Escape
     * closes the bar. It returns $in.
     */
    public function queryInput(Node $in): Node
    {
        $fd = $this->ed->find;
        $rt = $this->c->runtime;
        if ($fd->focusQuery) {
            $fd->focusQuery = false;
            $rt?->focusOn($in->id);
            $in->focused = true;
            $fd->queryField->selectAll();
        }
        $this->sync();
        $mods = $rt?->submitMods() ?? 0;
        if ($this->c->submitted($in)) {
            if ($mods & Key::SHIFT) {
                $this->previous();
            } elseif ($mods & Key::OPTION) {
                $this->selectAll();
            } else {
                $this->next();
            }
        } elseif ($rt?->focus === $in->id && $this->c->shortcut(Key::ESCAPE)) {
            $this->close();
        }
        return $in;
    }

    /** Makes $in the field of the replacement: Enter replaces the match selected, Cmd+Enter all of them, and Escape closes the bar. It returns $in. */
    public function replacementInput(Node $in): Node
    {
        $rt = $this->c->runtime;
        $this->sync();
        if ($this->c->submitted($in)) {
            if (($rt?->submitMods() ?? 0) & Key::COMMAND) { $this->replaceAll(); } else { $this->replace(); }
        } elseif ($rt?->focus === $in->id && $this->c->shortcut(Key::ESCAPE)) {
            $this->close();
        }
        return $in;
    }

    /**
     * Builds the editor's own find bar into $bar, as Zed's (mygo's
     * DefaultFindBar): the query with its options, the count of matches
     * and the buttons going through them, and the replacement below it
     * while replacing.
     */
    public static function default(Context $c, Node $bar, FindBar $f): void
    {
        $t = $c->theme;
        $tx = $c->texts();
        $bar->dir = Node::COLUMN;
        $bar->gap = $t->space(1); $bar->pad = $t->space(1.5);
        $bar->color = $t->surface; $bar->round($t->radius)->border($t->border)->shadow(2, 8, 0.18);
        $field = fn(TextState $value, string $placeholder) => $c->input($value, $placeholder, $t->space(50), $t->fontSize - 1);
        $button = function (string $label, string $tip) use ($c, $t): Node {
            $b = $c->button($label, $t->fontSize - 1)->tooltip($tip)->keepFocus();
            $b->bordered = false; $b->color = $c->hovered($b) ? $t->surfaceHover : null;
            return $b;
        };
        $c->row(0, $t->space(1))->align(Node::CENTER);
          $f->queryInput($field($f->query(), $tx->get('find')));
          $toggle = function (bool &$on, string $label, string $tip) use ($c, $t) {
              $b = $c->toggle($on, $label, $t->fontSize - 1)->tooltip($tip)->keepFocus();
              $b->bordered = false;
              if ($on) { $b->color = $t->accent; $b->fg = $t->accentText; } elseif (!$c->hovered($b)) { $b->color = null; }
          };
          $toggle($f->caseSensitive(), 'Aa', $tx->get('matchCase'));
          $toggle($f->wholeWord(), 'ab', $tx->get('matchWholeWord'));
          $toggle($f->regexp(), '.*', $tx->get('useRegularExpression'));
          $toggle($f->inSelection(), '[ ]', $tx->get('findInSelection'));
          $c->text($f->status(), $t->fontSize - 2, $t->textMuted)->min($t->space(16), 0);
          if ($c->clicked($button('↑', $tx->get('previousMatch')))) { $f->previous(); }
          if ($c->clicked($button('↓', $tx->get('nextMatch')))) { $f->next(); }
          if ($f->canReplace() && $c->clicked($button('⇄', $tx->get('replace')))) { $f->setReplacing(!$f->replacing()); }
          if ($c->clicked($button('✕', $tx->get('close')))) { $f->close(); }
        $c->end();
        if ($f->replacing()) {
            $c->row(0, $t->space(1))->align(Node::CENTER);
              $f->replacementInput($field($f->replacement(), $tx->get('replace')));
              if ($c->clicked($c->button($tx->get('replace'), $t->fontSize - 1)->keepFocus())) { $f->replace(); }
              if ($c->clicked($c->button($tx->get('replaceAll'), $t->fontSize - 1)->keepFocus())) { $f->replaceAll(); }
            $c->end();
        }
    }
}
