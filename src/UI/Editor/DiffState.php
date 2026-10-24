<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use Closure;
use MyPHP\App;
use MyPHP\UI\{Context, DisplayList, Font, Node, Role, TextPainter, Theme as UITheme};

/**
 * A diff viewer's state, kept by the app across frames (mygo's
 * DiffState): the texts it compares, its options, and what it keeps of
 * its own, as the lines expanded and its scroll position. The defaults
 * are those of Pierre's diffs. view($c) builds the viewer.
 */
final class DiffState
{
    /** How the viewer lays out its two texts: the old on the left and the new on the right, their lines aligned; or the new text, with the lines deleted from the old above where they were. */
    public const SPLIT = 0, UNIFIED = 1;
    /** How it highlights what changed within lines: words, joining those one character apart, as "a b c" changed reads as one; each word; from the first word changed to the last; characters; nothing. */
    public const INLINE_WORD_ALT = 0, INLINE_WORD = 1, INLINE_WORD_LINE = 2, INLINE_CHAR = 3, INLINE_NONE = 4;
    /** How it marks changed lines: a bar at the gutter's edge, striped for deletions; + and - before the lines; their background alone. */
    public const BARS = 0, CLASSIC = 1, NO_INDICATORS = 2;
    /** The sides of a diff. */
    public const OLD = 0, NEW = 1;
    /** The height of a separator. */
    public const SEPARATOR_HEIGHT = 32.0;

    /** The texts compared; empty buffers are made when null. New is the one edited when $editable, and can be the buffer of an editor's too: an edit in either shows in both. */
    public ?Buffer $old = null, $new = null;
    public int $layout = self::SPLIT;
    /** Lets the new text be edited where it shows, with all an editor does; the diff follows as it is typed. */
    public bool $editable = false;
    public int $inline = self::INLINE_WORD_ALT;
    public int $indicators = self::BARS;
    /**
     * How many unchanged lines show around changes, 3 when 0, none when
     * negative; the others collapse into separators that expand them,
     * $expansionLines (100 when 0) a click, all of them with Shift.
     * $expandUnchanged shows them all.
     */
    public int $contextLines = 0, $expansionLines = 0;
    public bool $expandUnchanged = false;
    /** Leave changed lines without their tint, and the gutter without numbers. */
    public bool $noBackground = false, $noLineNumbers = false;
    /** Wraps long lines rather than scrolling them sideways. */
    public bool $wrap = false;
    /** Makes the viewer as high as its lines, as a file of a list of them, rather than scrolling them itself. */
    public bool $fitContent = false;
    /** As State's. */
    public float $fontSize = 0.0;
    public int $fontStyle = Font::MONO;
    public float $lineHeight = 0.0;
    public int $tabSize = 0;
    public ?Theme $theme = null;
    /** @var list<Binding> key bindings added to the editors of the sides */
    public array $bindings = [];
    /** Shows Revert and Keep on the change under the pointer, or the caret's, which put the old lines back in New, or make the new lines Old's, as an agent's changes are reviewed. */
    public bool $hunkActions = false;
    /** Lets the numbers of lines be clicked and dragged to select lines, which onLineSelection reports. */
    public bool $lineSelection = false;
    /** @var ?Closure(DiffLineRange): void */
    public ?Closure $onLineSelection = null;
    /** Shows a + on the gutter of the line under the pointer, as to comment on it, and is called with the line when it is clicked. @var ?Closure(DiffLineRange): void */
    public ?Closure $onGutterButton = null;
    /** @var list<DiffAnnotation> room under lines for what $renderAnnotation builds, as comments of a review; the other side of a split view leaves the room empty */
    public array $annotations = [];
    /** @var ?Closure(Context, DiffAnnotation): void */
    public ?Closure $renderAnnotation = null;
    /**
     * Build the viewer's separators, the actions on a change and the
     * gutter's button in place of its own (DiffSeparator::default,
     * DiffHunkActions::default and DiffState::defaultGutterButton, which
     * they can call to wrap them): each is given the node the viewer
     * places, open, and what the part shows and does.
     */
    public ?Closure $renderSeparator = null, $renderHunkActions = null, $renderGutterButton = null;
    /** Build the bars of the sides' editors, as State's. */
    public ?Closure $renderFindBar = null, $renderGoToLine = null;

    public DiffModel $model;
    /** @var array{0: Editor, 1: Editor} the old side's and the new side's */
    public array $eds;
    /** @var array{0: DiffSide, 1: DiffSide} */
    public array $sides;
    /** The old text, for its lines in a unified view. */
    public View $oldView;
    private SharedScroll $scroll;
    /** @var list<array{0: int, 1: int}> the unchanged lines expanded, in the old text, which edits of the new text leave where they are; $revealedVer is the old text's version they are of */
    private array $revealed = [];
    private int $revealedVer = 0;
    private ?Buffer $revealedOf = null;
    private int $expandV = 0;
    private ?DiffLineRange $selected = null;
    /** Set while the old side of a split view has the keyboard, and stays after it goes, until the new side has it. */
    private bool $oldActive = false;
    /** What the blocks and hidden lines were made of. */
    private ?array $prepared = null;
    /** Counts the blocks prepare made; $built is the geometry the separators and buttons were built for, which a frame changing it builds again. */
    private int $blocksV = 0;
    private ?array $built = null;
    /** @var list<DiffGap> */
    public array $gapList = [];
    public ?DiffPartial $partial = null;

    public function __construct(?Buffer $old = null, ?Buffer $new = null)
    {
        $this->old = $old; $this->new = $new;
        $this->model = new DiffModel;
        $this->eds = [new Editor, new Editor];
        $this->sides = [new DiffSide($this, true), new DiffSide($this, false)];
        $this->oldView = new View;
        $this->scroll = new SharedScroll;
    }

    private function context(): int { return $this->contextLines < 0 ? 0 : ($this->contextLines === 0 ? 3 : $this->contextLines); }

    private function expansion(): int { return $this->expansionLines <= 0 ? 100 : $this->expansionLines; }

    public function ensure(): void
    {
        $this->old ??= new Buffer('');
        $this->new ??= new Buffer('');
        if (($pt = $this->partial) !== null && ($pt->old !== $this->old || $pt->new !== $this->new)) {
            // Texts of the app's in place of a patch's.
            $this->partial = null;
            $this->prepared = null;
        }
        $this->model->set($this->old, $this->new);
        $this->model->update();
        // The lines revealed move with edits of the old text, as Keep makes, and go with it.
        if ($this->revealedOf !== $this->old) {
            $this->revealed = []; $this->revealedOf = $this->old; $this->revealedVer = $this->old->version();
            $this->expandV++;
        }
        if (($ver = $this->old->version()) !== $this->revealedVer) {
            $to = $this->old->changesTo($this->revealedVer);
            if ($to !== null && $this->revealed !== []) {
                foreach ($to[0] as $cs) {
                    foreach ($this->revealed as $i => [$a, $b]) { $this->revealed[$i] = [Change::mapLine($a, $cs), Change::mapLine($b, $cs)]; }
                }
            } elseif ($to === null) {
                $this->revealed = [];
            }
            $this->revealedVer = $ver;
            $this->expandV++;
        }
    }

    /** The changes between the texts. @return list<DiffHunk> */
    public function hunks(): array
    {
        $this->ensure();
        return array_map(fn(Hunk $h) => new DiffHunk($h->a0, $h->a1, $h->b0, $h->b1), $this->model->hunks);
    }

    /** How many lines the changes add and delete. @return array{0: int, 1: int} */
    public function stats(): array
    {
        $this->ensure();
        return $this->model->stats();
    }

    /** Puts the old lines of hunk $i back in New. */
    public function revert(int $i): void
    {
        $this->ensure();
        $this->model->revert($i);
    }

    /** Makes the new lines of hunk $i Old's, so it is no longer a change. */
    public function accept(int $i): void
    {
        $this->ensure();
        $this->model->accept($i);
    }

    /** Shows every unchanged line; collapseAll collapses them again into separators. */
    public function expandAll(): void
    {
        $this->ensure();
        $this->expandUnchanged = true;
    }

    public function collapseAll(): void
    {
        $this->expandUnchanged = false;
        $this->revealed = [];
        $this->expandV++;
    }

    /** The lines selected by their numbers, or null. */
    public function selectedLines(): ?DiffLineRange { return $this->selected; }

    /** Selects lines, as clicking their numbers does; null clears the selection. */
    public function setSelectedLines(?DiffLineRange $r): void
    {
        if ($r !== null && $r->end < $r->start) { $r = new DiffLineRange($r->side, $r->end, $r->start); }
        $this->selected = $r;
    }

    /** Puts the caret of the active side (activeSide) at the start of hunk $i and scrolls it to the middle of the view. */
    public function goToHunk(int $i): void
    {
        $this->ensure();
        if ($i < 0 || $i >= count($this->model->hunks)) { return; }
        $h = $this->model->hunks[$i];
        $side = $this->activeSide();
        $ed = $this->editorOf($side);
        $line = $side === self::OLD ? $h->a0 : $h->b0;
        $r = $ed->view->text;
        $off = $r->lineStart(min($line, $r->lineCount() - 1));
        $ed->setSels([$ed->newSel($off, $off)], Editor::REVEAL_CENTER);
    }

    /** Runs a command in the editor of the active side (activeSide), as its key would, and reports whether it did something: Command::FIND opens its find bar, as an app's menu item does. */
    public function run(int $cmd): bool
    {
        $ed = $this->editorOf($this->activeSide());
        return $ed->retry(fn() => $ed->run($cmd));
    }

    /** Opens the find bar of the active side's editor (activeSide) with $query, and selects its first match after the caret. */
    public function find(string $query): void
    {
        $ed = $this->editorOf($this->activeSide());
        $f = $ed->find;
        $f->open = true; $f->query = $query; $f->stale = true;
        $f->step($ed, 1);
    }

    /** The side that has the keyboard, or had it last: the new side before either had it, and in a unified view. cursor, cursorPos, currentHunk, goToHunk, run and find act in its editor. */
    public function activeSide(): int
    {
        return $this->oldActive && $this->layout === self::SPLIT ? self::OLD : self::NEW;
    }

    /** The offset of the newest caret of the active side, in its text. */
    public function cursor(): int { return $this->editorOf($this->activeSide())->primary()->head; }

    /** The line and column of the newest caret of the active side, in its text. */
    public function cursorPos(): Pos
    {
        $ed = $this->editorOf($this->activeSide());
        return Buffer::posOf($ed->view->text, $ed->primary()->head);
    }

    /**
     * The hunk the caret of the active side is in, with inside true, as
     * for revert; else the last hunk before the caret, as "2 of 5" counts
     * changes and Next Change steps from (goToHunk(i+1)), or -1 before the
     * first. On a side the hunk has no lines of, as the new side of a
     * deletion, the caret is in it on the line where its lines were.
     * @return array{0: int, 1: bool}
     */
    public function currentHunk(): array
    {
        $side = $this->activeSide();
        $ed = $this->editorOf($side);
        $line = $ed->view->text->lineOf($ed->primary()->head);
        if (($j = $this->model->hunkAt($line, $side === self::OLD)) >= 0) { return [$j, true]; }
        $i = -1;
        foreach ($this->model->hunks as $j => $h) {
            if (($side === self::OLD ? $h->a0 : $h->b0) > $line) { break; }
            $i = $j;
        }
        return [$i, false];
    }

    /** The editor of a side, made ready to act in before the viewer is first built. */
    public function editorOf(int $side): Editor
    {
        $this->ensure();
        $ed = $this->eds[$side];
        $buf = $side === self::OLD ? $this->old : $this->new;
        if ($ed->view->buf !== $buf || $ed->sels === null) {
            $ed->tp ??= Editor::$text ?? App::text();
            $ed->init($buf, $this->sideOptions($side === self::OLD), UITheme::light(), $ed->tp);
        }
        $ed->sync();
        return $ed;
    }

    /** The options of the editor of the old side, or the new. */
    private function sideOptions(bool $old): Options
    {
        $o = new Options;
        // Both gutters as wide, so that both sides wrap alike.
        $o->minDigits = max(3, strlen((string)max($this->old->lineCount(), $this->new->lineCount())));
        $o->readOnly = $old || !$this->editable;
        $o->fontSize = $this->fontSize; $o->fontStyle = $this->fontStyle; $o->lineHeight = $this->lineHeight; $o->tabSize = $this->tabSize;
        $o->wrap = $this->wrap; $o->lineNumbers = $this->noLineNumbers ? State::LINE_NUMBERS_OFF : State::LINE_NUMBERS_ON;
        $o->whitespace = State::WHITESPACE_NONE; $o->autoClose = true;
        $o->theme = $this->theme; $o->bindings = $this->bindings; $o->fitContent = $this->fitContent;
        $o->renderFindBar = $this->renderFindBar; $o->renderGoToLine = $this->renderGoToLine;
        // What a code editor has that the sides have not.
        $o->indentGuides = false; $o->scrollPastEnd = false; $o->folding = false; $o->activeLine = false;
        $o->diffSide = $old ? Options::DIFF_OLD : Options::DIFF_NEW; // which names it
        return $o;
    }

    /** Builds the diff viewer (mygo's DiffView): a container, to size as the app's layout needs (grow, size). */
    public function view(Context $c): Node
    {
        $this->ensure();
        $t = $c->theme;
        $ct = Theme::resolve($this->theme, $t);
        $this->sides[0]->unified = false;
        $this->sides[1]->unified = $this->layout === self::UNIFIED;
        if ($this->layout === self::UNIFIED) {
            $this->oldView->theme = $ct;
            $this->oldView->setBuffer($this->old);
            $this->oldView->sync(null);
            $o = $this->sideOptions(false);
            $o->decor = $this->sides[1];
            if ($this->indicators === self::CLASSIC) { $o->signs = true; }
            $root = $c->row();
            $root->draw(function (DisplayList $list, Node $n, UITheme $th, TextPainter $text) {
                $ed = $this->eds[1];
                $ed->tp = $text;
                $ed->layout($n->w, $n->h, $text->scale());
                $this->oldView->style = $ed->view->style;
                $this->oldView->setStyle($text, $ed->view->size, $ed->o->lineHeight, $text->scale(), $ed->tabSize());
                $this->oldView->setWrap($ed->view->wrap, $ed->view->wrapWidth);
                $this->prepare();
                $ed->layout($n->w, $n->h, $text->scale());
                $this->rebuildIfMoved();
            });
              $ed = $this->eds[1]->build($c, $this->new, $o);
              $ed->grow = 1; $ed->alignSelf(Node::STRETCH);
              $this->buildSeparators($c, $ct, $root);
            $c->end();
            if ($this->fitContent) { $root->fh = $this->eds[1]->view->heights->total(); }
            return $root;
        }
        // Side by side: two editors sharing their scroll.
        $this->scroll->eds = [$this->eds[0], $this->eds[1]];
        // The old text on the left, as code reads left to right.
        $row = $c->row();
        $row->draw(function (DisplayList $list, Node $n, UITheme $th, TextPainter $text) {
            $w = ($n->w - 1) / 2;
            foreach ($this->eds as $ed) { $ed->tp = $text; $ed->layout($w, $n->h, $text->scale()); }
            $this->prepare();
            foreach ($this->eds as $ed) { $ed->layout($w, $n->h, $text->scale()); }
            if (!$this->fitContent) {
                $this->scroll->set($this->scroll->y);
                // The new side reveals its carets for both.
                $ed = $this->eds[1];
                if ($ed->reveal !== Editor::REVEAL_NONE) {
                    $ed->doReveal();
                    $this->scroll->set($ed->scrollY);
                }
                $ed0 = $this->eds[0];
                if ($ed0->reveal !== Editor::REVEAL_NONE) {
                    $ed0->doReveal();
                    $this->scroll->set($ed0->scrollY);
                }
            }
            $this->rebuildIfMoved();
        });
          foreach ([$this->old, $this->new] as $i => $b) {
              $o = $this->sideOptions($i === 0);
              $o->decor = $this->sides[$i];
              $o->sharedScroll = $this->fitContent ? null : $this->scroll;
              $o->noScrollbar = $i === 0;
              if ($this->indicators === self::CLASSIC) { $o->signs = true; }
              $ed = $this->eds[$i]->build($c->key($i === 0 ? 'old' : 'new'), $b, $o); // each side's nodes in a scope of their own: their find bars' keys are one
              $ed->grow = 1; $ed->basis(0)->alignSelf(Node::STRETCH);
              if ($i === 0) {
                  $c->box(1, 0, UITheme::mix($ct->c['background'][0], $t->border, 0.6))->alignSelf(Node::STRETCH);
              }
              if ($this->eds[$i]->focused) { $this->oldActive = $i === 0; }
          }
          $this->buildSeparators($c, $ct, $row);
        $c->end();
        if ($this->fitContent) { $row->fh = max($this->eds[0]->view->heights->total(), $this->eds[1]->view->heights->total()); }
        return $row;
    }

    /** Reveals lines of the gap starting at old line $a: at its top, below the change before it ($dir 1), at its bottom (-1), or all. */
    public function expand(int $a, int $dir, bool $all): void
    {
        foreach ($this->gapList as $g) {
            if ($g->a !== $a || $g->hidden === 0) { continue; }
            $from = $g->hideA; $to = $g->hideA + $g->hidden - 1;
            $n = $this->expansion();
            if (!$all) {
                if ($dir > 0) { $to = min($to, $from + $n - 1); } else { $from = max($from, $to - $n + 1); }
            }
            $this->revealed = Editor::mergeSpans([...$this->revealed, [$from, $to]]);
            $this->expandV++;
            return;
        }
    }

    /** The last line of the lines revealed holding old line $a, or -1. */
    private function revealedAt(int $a): int
    {
        foreach ($this->revealed as [$from, $to]) { if ($a >= $from && $a <= $to) { return $to; } }
        return -1;
    }

    /** The first line of the lines revealed holding old line $a, or -1. */
    private function revealedBefore(int $a): int
    {
        foreach ($this->revealed as [$from, $to]) { if ($a >= $from && $a <= $to) { return $from; } }
        return -1;
    }

    /** Selects a line by its number, or extends the selection to it from its nearer end. */
    public function selectLine(int $side, int $line, bool $extend): void
    {
        $s = $this->selected;
        if ($extend && $s !== null && $s->side === $side) {
            $this->selected = $line < $s->start ? new DiffLineRange($side, $line, $s->end) : new DiffLineRange($side, $s->start, $line);
        } elseif ($s !== null && $s->side === $side && $s->start === $line && $s->end === $line) {
            $this->selected = null;
        } else {
            $this->selected = new DiffLineRange($side, $line, $line);
        }
        if ($this->onLineSelection !== null && $this->selected !== null) { ($this->onLineSelection)($this->selected); }
    }

    /** The hunk under the pointer on either side, or else the one holding the new side's newest caret, or -1. */
    public function activeHunk(): int
    {
        foreach ($this->eds as $i => $ed) {
            if (!$ed->pointerIn || $ed->hoverLine < 0) { continue; }
            $side = $this->sides[$i];
            if ($i === 1 && $side->unified) {
                if (($j = $this->model->hunkAtNew($ed->hoverLine)) >= 0) { return $j; }
                continue;
            }
            if (($j = $side->hunkOf($ed->hoverLine)) >= 0) { return $j; }
            if ($i === 1 && ($j = $this->model->hunkAtNew($ed->hoverLine)) >= 0) { return $j; }
        }
        $ed = $this->eds[1];
        if ($ed->focused && $ed->sels !== null && $ed->sels !== []) {
            return $this->model->hunkAtNew($ed->view->text->lineOf($ed->primary()->head));
        }
        return -1;
    }

    /** What places the nodes a diff view builds over its lines, as its separators, which the view builds from the last frame's layout: the blocks, the wraps of the lines, the height and the scroll. */
    private function geom(): array
    {
        $a = $this->layout === self::UNIFIED ? $this->oldView : $this->eds[0]->view;
        $b = $this->eds[1];
        return [$this->blocksV, $a->heights->wraps->version, $b->view->heights->wraps->version, $b->view->heights->total(), $b->scrollY];
    }

    /** Asks for the view to be built again when laying it out for painting moved what its nodes were built for, as expanding lines from a separator's button does. */
    private function rebuildIfMoved(): void
    {
        if ($this->geom() !== $this->built) { $this->eds[1]->services?->animationFrame(); }
    }

    // difflayout.go

    /** Makes the hidden lines and the blocks of each side, again only when what they come of changed. */
    private function prepare(): void
    {
        $this->model->update();
        $unified = $this->layout === self::UNIFIED;
        $viewA = $unified ? $this->oldView : $this->eds[0]->view;
        $viewB = $this->eds[1]->view;
        $key = [$this->model->version, $this->expandV, $this->layout, $this->context(), self::annotationsKey($this->annotations),
            $viewA->heights->wraps->version, $viewB->heights->wraps->version, $viewB->lineHeight, $this->wrap,
            $this->expandUnchanged, $this->inline, $viewA->text->lineCount(), $viewB->text->lineCount()];
        if ($this->prepared === $key) { return; }
        // Measure the wraps of the lines of the hunks, which spacers align.
        if ($this->wrap) {
            foreach ($this->model->hunks as $h) {
                for ($i = $h->a0; $i < $h->a1; $i++) { $viewA->wrapLineNow($i); }
                for ($i = $h->b0; $i < $h->b1; $i++) { $viewB->wrapLineNow($i); }
            }
            $key[5] = $viewA->heights->wraps->version; $key[6] = $viewB->heights->wraps->version;
        }
        $this->prepared = $key;
        $nA = $viewA->text->lineCount(); $nB = $viewB->text->lineCount();
        $gaps = $this->gaps($nA, $nB);
        $this->gapList = $gaps;
        $lh = $viewB->lineHeight;
        // The hidden lines and blocks of each side, 0 the old and 1 the new; late are blocks below a line that go after its annotations, as the separator of the lines after the last change.
        $hide = [[], []];
        $blk = [[], []];
        $late = [[], []];
        $views = [$viewA, $viewB];
        $ns = [$nA, $nB];
        foreach ($gaps as $gi => $g) {
            if ($g->hidden <= 0) { continue; }
            foreach ([$g->hideA, $g->hideB] as $s => $from) {
                $hide[$s][] = [$from, $from + $g->hidden - 1];
                $b = new Block(0, false, self::SEPARATOR_HEIGHT, Block::SEPARATOR, $from, $from + $g->hidden, $g->a, $gi);
                if ($from + $g->hidden < $ns[$s]) {
                    $b->line = $from + $g->hidden;
                    $blk[$s][] = $b;
                } else {
                    $b->line = max(0, $from - 1); $b->below = true;
                    $late[$s][] = $b;
                }
            }
        }
        // A patch's hunks under their headers: above their first line, or, for a hunk with no lines left on a side, below its last line, after what the hunk before puts there (late).
        if (($pt = $this->partial) !== null) {
            foreach ($pt->headers as $k => $h) {
                foreach ([$pt->oldStarts[$k], $pt->newStarts[$k]] as $s => $start) {
                    $b = new Block($start, false, self::SEPARATOR_HEIGHT, Block::HEADER, 0, 0, $h, $k);
                    if ($start >= $ns[$s]) {
                        $b->line = max(0, $ns[$s] - 1); $b->below = true;
                        $late[$s][] = $b;
                    } else {
                        $blk[$s][] = $b;
                    }
                }
            }
        }
        $anns = [[], []];
        foreach ($this->annotations as $a) { $anns[$a->side][$a->line] = ($anns[$a->side][$a->line] ?? 0.0) + $a->height; }
        $rowsOf = fn(View $v, int $line): float => $v->heights->wraps->rows($line) * $lh;
        if ($unified) {
            foreach ($this->model->hunks as $hi => $h) {
                if ($h->a1 <= $h->a0) { continue; }
                $height = 0.0;
                for ($i = $h->a0; $i < $h->a1; $i++) { $height += $rowsOf($viewA, $i) + ($anns[self::OLD][$i] ?? 0.0); }
                $b = new Block(0, false, $height, Block::DELETED, $h->a0, $h->a1, null, $hi);
                if ($h->b0 < $nB) { $b->line = $h->b0; } else { $b->line = $nB - 1; $b->below = true; }
                $blk[1][] = $b;
            }
            // The old text's annotations on unchanged lines go under the same lines of the new text, as only those show.
            foreach ($anns[self::OLD] as $line => $height) {
                if (($nl = $this->model->newLineOf($line)) !== null) { $anns[self::NEW][$nl] = ($anns[self::NEW][$nl] ?? 0.0) + $height; }
            }
            foreach ($anns[self::NEW] as $line => $height) { $blk[1][] = new Block($line, true, $height, Block::ANNOTATION, 0, 0, $line); }
            $this->setSide(1, $hide[1], self::sticky([...$blk[1], ...$late[1]]));
            return;
        }
        // Side by side: walk the rows of each hunk, the shorter side's lines pushed down by the offset pairing them best, each row as high as the higher side's line and annotations there, fillers where a side has no line.
        $emitAnn = function (int $s, int $line, float $extra) use (&$blk) {
            if ($extra > 0 && $line >= 0) { $blk[$s][] = new Block($line, true, $extra, Block::SPACER); }
        };
        $unchanged = function (int $a0, int $b0, int $n) use (&$blk, $anns, $emitAnn) {
            // Annotations on unchanged lines leave room on the other side.
            for ($i = 0; $i < $n; $i++) {
                $ha = $anns[0][$a0 + $i] ?? 0.0; $hb = $anns[1][$b0 + $i] ?? 0.0;
                if ($ha == 0 && $hb == 0) { continue; }
                $hmax = max($ha, $hb);
                if ($ha > 0) { $blk[0][] = new Block($a0 + $i, true, $ha, Block::ANNOTATION, 0, 0, $a0 + $i); }
                if ($hb > 0) { $blk[1][] = new Block($b0 + $i, true, $hb, Block::ANNOTATION, 0, 0, $b0 + $i); }
                $emitAnn(0, $a0 + $i, $hmax - $ha);
                $emitAnn(1, $b0 + $i, $hmax - $hb);
            }
        };
        $prevA = 0; $prevB = 0;
        foreach ($this->model->hunks as $hi => $h) {
            $unchanged($prevA, $prevB, $h->a0 - $prevA);
            $prevA = $h->a1; $prevB = $h->b1;
            $cnt = [$h->a1 - $h->a0, $h->b1 - $h->b0];
            $starts = [$h->a0, $h->b0];
            $off = $cnt[0] > 0 && $cnt[1] > 0 && $cnt[0] !== $cnt[1] ? $this->model->wordsOf($hi, $this->inline)->offset : 0;
            $rows = max($cnt[0], $cnt[1]);
            $lineOf = function (int $s, int $r) use ($cnt, $starts, $rows, $off): int {
                $k = $cnt[$s] < $rows ? $r - $off : $r;
                return $k < 0 || $k >= $cnt[$s] ? -1 : $starts[$s] + $k;
            };
            $filler = [0.0, 0.0];
            $lastLine = [-1, -1];
            for ($r = 0; $r < $rows; $r++) {
                $hs = [0.0, 0.0]; $ls = [-1, -1];
                for ($s = 0; $s < 2; $s++) {
                    $ls[$s] = $lineOf($s, $r);
                    if ($ls[$s] >= 0) { $hs[$s] = $rowsOf($views[$s], $ls[$s]) + ($anns[$s][$ls[$s]] ?? 0.0); }
                }
                $hmax = max($hs[0], $hs[1]);
                for ($s = 0; $s < 2; $s++) {
                    if ($ls[$s] < 0) { $filler[$s] += $hmax; continue; }
                    if ($filler[$s] > 0) {
                        $blk[$s][] = new Block($ls[$s], false, $filler[$s], Block::SPACER, 1, 0, null, $hi);
                        $filler[$s] = 0.0;
                    }
                    if (($a = $anns[$s][$ls[$s]] ?? 0.0) > 0) { $blk[$s][] = new Block($ls[$s], true, $a, Block::ANNOTATION, 0, 0, $ls[$s]); }
                    $emitAnn($s, $ls[$s], $hmax - $hs[$s]);
                    $lastLine[$s] = $ls[$s];
                }
            }
            for ($s = 0; $s < 2; $s++) {
                if ($filler[$s] <= 0) { continue; }
                $b = new Block(0, false, $filler[$s], Block::SPACER, 1, 0, null, $hi);
                $pos = $starts[$s];
                if ($lastLine[$s] >= 0) {
                    $b->line = $lastLine[$s]; $b->below = true;
                } elseif ($pos < $ns[$s] && !($s === 0 && $cnt[0] === 0 && $pos === $ns[$s] - 1 && $viewA->text->len() === 0)) {
                    $b->line = $pos;
                } else {
                    $b->line = max(0, $ns[$s] - 1); $b->below = true;
                }
                $blk[$s][] = $b;
            }
        }
        $unchanged($prevA, $prevB, min($nA - $prevA, $nB - $prevB));
        $this->setSide(0, $hide[0], self::sticky([...$blk[0], ...$late[0]]));
        $this->setSide(1, $hide[1], self::sticky([...$blk[1], ...$late[1]]));
    }

    /** Keeps the blocks that stand for lines, as separators, deleted lines and fillers, where their line is hidden, as with no context lines; annotations go with the line they are under. @param list<Block> $blks @return list<Block> */
    private static function sticky(array $blks): array
    {
        foreach ($blks as $b) { if ($b->kind !== Block::ANNOTATION) { $b->sticky = true; } }
        return $blks;
    }

    /** Gives side $i its hidden lines and blocks, keeping those it has when they are the same, as typing within a line leaves them: the view, whose separators and buttons stand on them, is not built again. */
    private function setSide(int $i, array $hide, array $blks): void
    {
        $s = $this->sides[$i];
        $same = $s->hide === $hide && count($s->blks) === count($blks);
        if ($same) { foreach ($blks as $k => $b) { if (!$b->equals($s->blks[$k])) { $same = false; break; } } }
        if ($same) { return; }
        $s->hide = $hide; $s->blks = $blks;
        $this->blocksV++;
    }

    /** The runs of unchanged lines and which of them collapse. @return list<DiffGap> */
    private function gaps(int $nOld, int $nNew): array
    {
        $hunks = $this->model->hunks;
        if ($hunks === [] || $this->partial !== null) { return []; }
        $ctx = $this->context();
        $out = [];
        $pa = 0; $pb = 0;
        for ($i = 0; $i <= count($hunks); $i++) {
            [$na, $nb] = $i < count($hunks) ? [$hunks[$i]->a0, $hunks[$i]->b0] : [$nOld, $nNew];
            $n = $nb - $pb;
            if ($na - $pa !== $n) { $n = min($n, $na - $pa); }
            $g = new DiffGap($pa, $pb, $n, $i === 0, $i === count($hunks));
            if (!$this->expandUnchanged && $n > 0) {
                $top = $g->before ? 0 : $ctx; $bottom = $g->after ? 0 : $ctx;
                // And the lines expanded at either edge.
                if ($top < $n && ($end = $this->revealedAt($pa + $top)) >= 0) { $top = min($n, $end + 1 - $pa); }
                if ($bottom < $n && ($first = $this->revealedBefore($pa + $n - 1 - $bottom)) >= 0) { $bottom = min($n, $pa + $n - $first); }
                $hidden = $n - $top - $bottom;
                // One line alone shows rather than collapsing.
                if ($hidden > 1) { $g->hidden = $hidden; $g->hideA = $pa + $top; $g->hideB = $pb + $top; }
            }
            $out[] = $g;
            if ($i < count($hunks)) { $pa = $hunks[$i]->a1; $pb = $hunks[$i]->b1; }
        }
        return $out;
    }

    /** How high the annotations under $line of $side are. */
    public function annotationHeight(int $side, int $line): float
    {
        $h = 0.0;
        foreach ($this->annotations as $a) { if ($a->side === $side && $a->line === $line) { $h += $a->height; } }
        return $h;
    }

    /** What of the annotations places the blocks: their sides, lines and heights. @param list<DiffAnnotation> $anns */
    private static function annotationsKey(array $anns): string
    {
        $k = '';
        foreach ($anns as $a) { $k .= "{$a->side}:{$a->line}:{$a->height};"; }
        return $k;
    }

    // diffparts.go

    /** Places the separators in view across the viewer, both sides of a split one, over the room the lines leave them, and builds them. */
    private function buildSeparators(Context $c, Theme $ct, Node $parent): void
    {
        $ed = $this->eds[1];
        $this->built = $this->geom();
        $render = $this->renderSeparator ?? DiffSeparator::default(...);
        // Across the viewer, as wide as the last frame laid it out, less the scroll bar.
        $width = max(0.0, ($c->runtime?->rectOf($parent->id)[2] ?? 0.0) - Editor::SCROLLBAR_WIDTH);
        $ed->blocksInView(function (Block $b, float $top) use ($c, $ct, $ed, $render, $width) {
            $s = new DiffSeparator($this, $ct, $ed->view->size, $ed->view->style);
            $s->step = $this->expansion();
            if ($b->kind === Block::SEPARATOR) {
                $g = $this->gapList[$b->hunk];
                $s->lines = $b->to - $b->from; $s->first = $g->before; $s->last = $g->after; $s->a = $g->a;
                $key = "separator {$g->a}";
            } elseif ($b->kind === Block::HEADER) {
                $s->header = (string)$b->id;
                $key = "header {$b->hunk}";
            } else {
                return;
            }
            $label = $s->lines > 0 ? $c->texts()->get('unmodifiedLines', ['n' => $s->lines]) : $s->header;
            $sep = $c->key($key)->column()->at(0, $top - $ed->scrollY)->size($width, $b->height)->role(Role::GROUP)->label($label)
                ->cursor('arrow')->keepFocus()->handleInput(Editor::shield(...));
            $render($c, $sep, $s);
            $c->end();
        });
    }

    /** Builds the viewer's own gutter button into $btn (mygo's DefaultGutterButton): a + on the accent color. */
    public static function defaultGutterButton(Context $c, Node $btn, DiffLineRange $r): void
    {
        $t = $c->theme;
        $btn->round(4)->label($c->texts()->get('line', ['n' => $r->start + 1]));
        $btn->color = $t->accent;
        $btn->drawOver(function (DisplayList $list, Node $b, UITheme $th) {
            $cx = $b->x + $b->w / 2; $cy = $b->y + $b->h / 2;
            $list->line($cx - 4, $cy, $cx + 4, $cy, $th->accentText, 1.0, 1.5);
            $list->line($cx, $cy - 4, $cx, $cy + 4, $th->accentText, 1.0, 1.5);
        });
    }

    // patch.go

    /**
     * Shows a file's patch without the files (mygo's SetPatch): its hunks'
     * lines, numbered as in the files, each hunk under its header, as
     * Pierre's diffs show partial patches. Old and New become texts of
     * those lines, not editable, and unchanged lines do not collapse.
     */
    public function setPatch(FilePatch $p): void
    {
        $old = []; $nw = [];
        $pt = new DiffPartial;
        // The changes are the patch's runs of - and + lines, as it says them, not what comparing the texts would find.
        $hunks = [];
        foreach ($p->hunks as $h) {
            $run = -1;
            $pt->oldStarts[] = count($old); $pt->newStarts[] = count($nw);
            $pt->oldFile[] = max(0, $h->oldStart - 1); $pt->newFile[] = max(0, $h->newStart - 1);
            $header = sprintf('@@ -%d,%d +%d,%d @@', $h->oldStart, $h->oldLines, $h->newStart, $h->newLines);
            if ($h->section !== '') { $header .= ' ' . $h->section; }
            $pt->headers[] = $header;
            foreach ($h->lines as $l) {
                if ($l === '' || $l[0] === '\\') { continue; }
                if ($l[0] !== '-' && $l[0] !== '+') {
                    $run = -1;
                } elseif ($run < 0) {
                    $hunks[] = new Hunk(count($old), count($old), count($nw), count($nw));
                    $run = count($hunks) - 1;
                }
                if ($run >= 0) {
                    if ($l[0] === '-') { $hunks[$run]->a1++; } else { $hunks[$run]->b1++; }
                }
                $text = substr($l, 1);
                if (str_ends_with($text, "\r")) { $text = substr($text, 0, -1); }
                if ($l[0] !== '+') { $old[] = $text; }
                if ($l[0] !== '-') { $nw[] = $text; }
            }
        }
        $this->old = new Buffer(implode("\n", $old));
        $this->new = new Buffer(implode("\n", $nw));
        if (($l = Languages::for($p->newName !== '' ? $p->newName : $p->oldName)) !== null) {
            $this->old->setLanguage($l);
            $this->new->setLanguage($l);
        }
        $pt->old = $this->old; $pt->new = $this->new;
        $this->partial = $pt;
        $this->editable = false;
        $this->prepared = null;
        $m = $this->model;
        $m->set($this->old, $this->new);
        $m->oldText = $this->old->rope(); $m->oldVer = $this->old->version();
        $m->newText = $this->new->rope(); $m->newVer = $this->new->version();
        $m->hunks = $hunks; $m->words = [];
        $m->version++;
    }
}
