<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\{Layout, Params, System};
use MyPHP\UI\{AccessNode, Context, Node, Painter, Role, Runtime, TextInputClient, TextInputRange, Theme as UITheme};
use MyPHP\UI\Editor\{Rope, RopeText};

/**
 * The state of an editor (mygo's richtext view): the text it shows, which
 * follows its document, the layouts of its paragraphs, where it is scrolled
 * to, the selection and an input method's composition. The runtime keeps
 * it as the editor's node's local state.
 *
 * @internal
 */
final class View implements TextInputClient
{
    use ViewEdit, ViewInput, ViewPaint, ViewIme, ViewClipboard;

    /** How the next edit joins the steps of undo (mygo's editMode). */
    public const NEW_STEP = 0, TYPING_STEP = 1, SAME_STEP = 2;
    /** How many paragraphs keep their layouts out of view. */
    private const MAX_LAID = 512;
    /** The caret blinks at this interval, for half a minute after the last change. */
    private const BLINK = 0.53;

    public Document $doc;
    public EditorOptions $opts;
    /** The text laid out, of the document's version and selVersion. */
    public Text $shown;
    public int $version = 0;
    public int $selVersion = 0;
    /** @var list<ParaView> */
    public array $paras = [];
    public Heights $hs;
    public int $laid = 0;
    public Look $look;
    /** The text's family and size. */
    public string $family = '';
    public float $size = 14;
    /** The content's width, which the layouts wrap at, and height; the room around the text in the element. */
    public float $width = 0, $viewH = 0;
    /** The look the layouts were made in. */
    public string $laidLook = '';
    public float $padX = 0, $padY = 0;
    /** The element's box [x, y, w, h], as last painted or laid out. @var array{0: float, 1: float, 2: float, 3: float} */
    public array $rect = [0.0, 0.0, 0.0, 0.0];
    public float $scroll = 0;
    public int $anchor = 0;
    public float $anchorOff = 0;
    public bool $reveal = true;
    public int $first = 0, $last = 0;
    /** The selection, in runes: anchor and caret, and the caret's visual edge (Layout::DOWNSTREAM or UPSTREAM). */
    public int $selAnchor = 0, $caret = 0;
    public int $affinity = Layout::DOWNSTREAM;
    public float $desiredX = 0;
    public bool $hasDesired = false;
    /** An input method's composition, shown at the caret. */
    public string $compose = '';
    public int $composeCaret = 0;
    public TextInputRange $composeSelected;
    public bool $composing = false;
    public int $mode = self::NEW_STEP;
    public bool $coalesce = false;
    /** The pointer: a drag selecting by runes, words or paragraphs, from where it started; a scroll bar's thumb held; a link pressed. */
    public bool $dragging = false;
    public int $dragUnit = 1;
    /** @var array{0: int, 1: int} */
    public array $dragStart = [0, 0];
    public string $pressLink = '';
    /** The pointer's shape where it hovers: an arrow over task boxes and the scroll bar, a hand over links, an I-beam over text. */
    public \MyPHP\UI\Cursor $cursor = \MyPHP\UI\Cursor::Text;
    public bool $thumb = false;
    public float $thumbGrab = 0;
    public bool $focused = false;
    /** Nanoseconds, of the runtime's clock. */
    public int $blinkStart = 0;
    public ?Runtime $services = null;
    /** Set by an edit of the user, which the next build reports. */
    public bool $changed = false;
    /** The numbers of the items of numbered lists, for the text of $numbersOf. @var array<int, int> */
    public array $numbers = [];
    public ?Text $numbersOf = null;
    public ?System $sys = null;

    public function __construct(Document $doc)
    {
        $this->doc = $doc;
        $this->opts = new EditorOptions;
        $this->shown = new Text;
        $this->hs = new Heights;
        $this->composeSelected = new TextInputRange(0, 0);
        $this->selAnchor = $doc->anchor; $this->caret = $doc->caret;
        $this->selVersion = $doc->selVersion;
    }

    /** The node of an editor of $doc (RichText::editor and editorBase), $styled with its padding. */
    public static function build(Context $c, Document $doc, ?EditorOptions $options, bool $styled): Node
    {
        $t = $c->theme;
        $rt = $c->runtime;
        $e = $c->column()->focusable()->clip();
        $c->end();
        /** @var View $v */
        $v = $rt?->local($e->id, fn() => new View($doc)) ?? new View($doc);
        if ($v->doc !== $doc) {
            foreach (get_object_vars(new View($doc)) as $k => $val) { $v->$k = $val; } // another document: all anew
        }
        $v->opts = $options ?? new EditorOptions;
        $v->services = $rt;
        $doc->shownIn = \WeakReference::create($v);
        $v->sys = $c->metrics()->textSystem();
        $v->setLook($t, $styled);
        $v->sync();
        $now = $rt?->now ?? hrtime(true);
        $focused = $c->focused($e);
        if ($focused !== $v->focused) {
            $v->focused = $focused;
            $v->blinkStart = $now;
            if (!$focused) { $v->dragging = $v->thumb = false; }
        }
        if ($v->changed) {
            $v->changed = false;
            $e->changed = true;
        }
        $e->role(Role::TEXT_FIELD)->label($v->opts->label !== '' ? $v->opts->label : $c->texts()->get('richText'))->access($v->access(...));
        if (!$v->opts->readOnly) { $e->handleTextInput($v); }
        $e->handleInput($v->input(...));
        $e->cursor($v->cursor);
        $e->draw(function (Painter $p, array $r) use ($v): void { $v->paint($p->displayList(), $p->textPainter(), $r, $p->theme()); });
        $e->contextMenu($v->menu(...));
        if ($styled) { $e->ownRing = true; } // FocusRing(false): the border shows it has the keyboard
        if (($r = $rt?->rectOf($e->id)) !== null) {
            // Lay out at last frame's size, for the input before this frame paints.
            $v->rect = $r;
            $v->layout(max($r[2] - 2 * $v->padX, 1), max($r[3] - 2 * $v->padY, 1));
        }
        if ($focused && !$v->opts->readOnly) {
            // The caret blinks for half a minute after the last change.
            $phase = ($now - $v->blinkStart) / 1e9;
            if ($phase < 30) { $rt?->after(self::BLINK - fmod($phase, self::BLINK)); }
        }
        if ($styled) {
            $e->radius($t->radius)->background($t->surface)->borderWidth(1)->borderColor($focused ? $t->accent : $t->border)->minWidth(0)->minHeight($t->space(30));
        }
        return $e;
    }

    /** Sets how the text looks, from the editor's font and the theme. */
    public function setLook(UITheme $t, bool $styled): void
    {
        $size = $this->opts->fontSize > 0 ? $this->opts->fontSize : ($t->fontSize > 0 ? $t->fontSize : 14.0);
        $this->family = $this->opts->font;
        $this->size = $size;
        $th = Theme::resolve($this->opts->theme, Theme::defaultTheme($t));
        $this->look = new Look($th, $size, $size * $th->indent, [$t->border, 1.0], $this->opts->tagStyle);
        [$this->padX, $this->padY] = $styled ? [$th->padding[0], $th->padding[1]] : [0.0, 0.0];
    }

    /** Gets the editor in step with its document: the paragraphs that changed give up their layouts, and a selection the app set is taken. It returns the change it took that the log described, or null. */
    public function sync(): ?Change
    {
        $d = $this->doc;
        $c = null;
        if ($d->version !== $this->version) {
            $c = $d->since($this->version);
            if ($c !== null && $this->version !== 0 && count($this->paras) === $this->shown->paragraphCount()) {
                $this->replaced($c, $d->text);
            } else {
                $this->shown = $d->text;
                $this->paras = [];
                for ($i = $d->text->paragraphCount(); $i > 0; $i--) { $this->paras[] = new ParaView; }
                $this->laid = 0;
                $this->resetHeights();
                $c = null;
            }
            $this->shown = $d->text; $this->version = $d->version;
            $n = $this->shown->len();
            if ($c !== null) {
                $this->selAnchor = Change::mapPosition($this->selAnchor, $c->start, $c->oldEnd - $c->start, $c->newEnd - $c->start, $this->selAnchor > $this->caret);
                $this->caret = Change::mapPosition($this->caret, $c->start, $c->oldEnd - $c->start, $c->newEnd - $c->start, $this->caret > $this->selAnchor);
            }
            $this->selAnchor = min($this->selAnchor, $n); $this->caret = min($this->caret, $n);
        }
        if ($d->selVersion !== $this->selVersion) {
            $this->selVersion = $d->selVersion;
            $this->selAnchor = $d->anchor; $this->caret = $d->caret;
            $this->affinity = Layout::DOWNSTREAM;
            $this->hasDesired = false;
            $this->compose = ''; $this->composing = false;
            $this->composeCaret = 0; $this->composeSelected = new TextInputRange(0, 0);
            $this->reveal = true;
        }
        return $c;
    }

    /** Takes a change of the text: the paragraphs from the one holding $c->start to the one holding $c->oldEnd of the text shown give way to those of $now, without layouts. */
    private function replaced(Change $c, Text $now): void
    {
        $pa = $this->shown->paraAt($c->start); $pz = $this->shown->paraAt($c->oldEnd);
        $npz = $now->paraAt($c->newEnd);
        for ($p = $pa; $p <= $pz; $p++) {
            if ($this->paras[$p]->layout !== null) { $this->laid--; }
        }
        if ($npz === $pz) {
            // As many paragraphs: they give up their heights in place, as a keystroke's does, rather than summing all the others again.
            for ($p = $pa; $p <= $pz; $p++) {
                if (($h = $this->paras[$p]->h) > 0) { $this->hs->add($p, -$h, 1); }
                $this->paras[$p] = new ParaView;
            }
        } else {
            $added = [];
            for ($i = $npz - $pa + 1; $i > 0; $i--) { $added[] = new ParaView; }
            array_splice($this->paras, $pa, $pz + 1 - $pa, $added);
            $this->resetHeights();
        }
        $this->shown = $now;
        if ($this->anchor > $pz) {
            $this->anchor += $npz - $pz;
        } elseif ($this->anchor > $pa) {
            $this->anchor = $pa; $this->anchorOff = 0;
        }
        $this->numbersOf = null;
    }

    /** Sums the heights of the paragraphs laid out. */
    private function resetHeights(): void
    {
        $this->hs->reset(array_map(fn(ParaView $p) => $p->h, $this->paras));
        $this->estimate();
    }

    /** Sets the height of paragraphs not laid out yet to the average of those that are, or a line of text. */
    private function estimate(): void
    {
        [$m, $unknown] = $this->hs->totals();
        $n = count($this->paras) - $unknown;
        $this->hs->est = $n > 0 ? $m / $n : $this->look->size * 1.3;
    }

    /** Paragraph $p of the text shown. */
    public function para(int $p): RichPara { return $this->shown->para($p); }

    /** The font of a paragraph of style $st: [family, size, weight]. @return array{0: string, 1: float, 2: int} */
    public function paraFont(ParagraphStyle $st): array
    {
        $f = [$this->family, $this->size, 0];
        if ($st->heading > 0) {
            $h = $this->look->heading($st->heading);
            $f[1] *= $h->size;
            $f[2] = $h->weight;
        } elseif ($st->codeBlock) {
            $f[0] = $this->look->theme->codeFont;
            $f[1] *= $this->look->theme->codeSize;
        }
        return $f;
    }

    /** What of the look the layouts of paragraphs are made of. */
    private function lookKey(): string
    {
        return $this->family . "\0" . $this->size . "\0" . $this->look->theme->key() . "\0" . ($this->look->tagStyle !== null ? 't' : '');
    }

    /** The room left and right of a paragraph of style $st: its indentation, its list's marker, a quotation's bar, a block of code's padding. @return array{0: float, 1: float} */
    public function insets(ParagraphStyle $st): array
    {
        $look = $this->look;
        $left = $st->indent * $look->indent; $right = 0.0;
        if ($st->list !== ListKind::NoList) { $left += $look->indent; }
        if ($st->quote) { $left += $look->indent * 0.75; }
        if ($st->codeBlock) {
            $left += $look->size * $look->theme->codeBlockPadding[0];
            $right += $look->size * $look->theme->codeBlockPadding[0];
        }
        return [$left, $right];
    }

    /** Whether a paragraph of style $st is of the body's text, which Theme::$firstLineIndent and $paragraphSpacing set apart: not a heading, an item of a list or code (mygo's body). */
    public static function body(ParagraphStyle $st): bool
    {
        return $st->heading === 0 && $st->list === ListKind::NoList && !$st->codeBlock;
    }

    /**
     * How far the first line of paragraph $p goes in (mygo's firstLine): a
     * body paragraph aligned at the start after another of the same
     * quotation and level takes Theme::$firstLineIndent, as printed prose
     * indents all but the first paragraph after a heading, a list, a
     * centered scene break or an empty line. It looks at the paragraph
     * before only.
     */
    public function firstLine(int $p, ParagraphStyle $st): float
    {
        $th = $this->look->theme;
        if ($th->firstLineIndent <= 0 || $p === 0 || !self::body($st) || $st->align !== \MyPHP\Text\Params::START) {
            return 0.0;
        }
        $prev = $this->para($p - 1);
        $ps = $prev->style;
        if ($prev->n === 0 || !self::body($ps) || $ps->align !== \MyPHP\Text\Params::START || $ps->quote !== $st->quote || $ps->indent !== $st->indent) {
            return 0.0;
        }
        return $this->look->size * $th->firstLineIndent;
    }

    /** The room above and below paragraph $p: a heading's margins, a block of code's padding at its ends, the spacing between body paragraphs, above the second. @return array{0: float, 1: float} */
    public function space(int $p, ParagraphStyle $st): array
    {
        $look = $this->look;
        $above = $below = 0.0;
        if (self::body($st)) {
            if ($look->theme->paragraphSpacing > 0 && $p > 0 && self::body($this->para($p - 1)->style)) { $above = $look->size * $look->theme->paragraphSpacing; }
        } elseif ($st->heading > 0) {
            $h = $look->heading($st->heading);
            if ($p > 0) { $above = $look->size * $h->size * $h->top; }
            $below = $look->size * $h->bottom;
        } elseif ($st->codeBlock) {
            if ($p === 0 || !$this->para($p - 1)->style->codeBlock) { $above = $look->size * $look->theme->codeBlockPadding[1]; }
            if ($p + 1 >= count($this->paras) || !$this->para($p + 1)->style->codeBlock) { $below = $look->size * $look->theme->codeBlockPadding[1]; }
        }
        return [$above, $below];
    }

    /** The text of paragraph $p, without its newline. */
    public function lineOf(int $p): string { return (new RopeText($this->shown->text))->line($p); }

    /** Paragraph $p laid out, with the composition at the caret: it keeps the layout until the paragraph or its style change. */
    public function paraLayout(int $p): ParaView
    {
        $pv = $this->paras[$p];
        $para = $this->para($p);
        $compose = ''; $at = 0;
        if ($this->compose !== '' && $this->shown->paraAt($this->caret) === $p) {
            $compose = $this->compose; $at = $this->caret - $this->shown->paraStart($p);
        }
        $indent = $this->firstLine($p, $para->style);
        if ($pv->layout !== null && $pv->para === $para && $pv->compose === $compose && $pv->at === $at && $pv->indent == $indent) {
            // The space around it follows its neighbours, which may have changed without it: a code block's last line, the first heading, a body paragraph after one that became a heading.
            [$above, $below] = $this->space($p, $para->style);
            if ($above + $below != $pv->space) {
                $h = $pv->h - $pv->space + $above + $below;
                $this->hs->add($p, $h - $pv->h, 0);
                $pv->h = $h; $pv->space = $above + $below;
            }
            return $pv;
        }
        [$left, $right] = $this->insets($para->style);
        $this->look->width = max($this->width - $left - $right, 1.0);
        $line = $this->lineOf($p);
        $spans = [];
        $typing = $compose !== '' ? $this->doc->typingAttributes() : null;
        $chars = mb_str_split($line, 1, 'UTF-8');
        $off = 0;
        $add = function (int $n, Attributes $a) use (&$spans, &$off, $chars, $para): void {
            $end = min($off + $n, count($chars));
            if ($end > $off) { $spans[] = $this->look->span(implode('', array_slice($chars, $off, $end - $off)), $a, $para->style); }
            $off = $end;
        };
        $done = 0;
        $pending = $compose;
        foreach ($para->runs as [$rn, $ra]) {
            $n = min($rn, $para->n - $done);
            if ($pending !== '' && $done <= $at && $at < $done + $n) {
                $add($at - $done, $ra);
                $spans[] = $this->look->span($pending, $typing, $para->style);
                $add($done + $n - $at, $ra);
                $pending = '';
            } else {
                $add($n, $ra);
            }
            $done += $n;
            if ($done >= $para->n) { break; }
        }
        if ($pending !== '') { $spans[] = $this->look->span($pending, $typing, $para->style); }
        [$family, $size, $weight] = $this->paraFont($para->style);
        $l = ParaLayout::shape($this->sys, $spans, $family, $size, $weight, $this->look->width, $para->style->align, $indent);
        [$above, $below] = $this->space($p, $para->style);
        [, $lh] = $l->size();
        $h = $above + $lh + $below;
        if ($pv->layout === null) { $this->laid++; }
        if ($pv->h > 0) {
            $this->hs->add($p, $h - $pv->h, 0);
        } else {
            $this->hs->add($p, $h, -1);
        }
        $pv->layout = $l; $pv->para = $para; $pv->compose = $compose; $pv->at = $at; $pv->h = $h; $pv->space = $above + $below; $pv->indent = $indent;
        $pv->marker = ''; $pv->markerLayout = null;
        return $pv;
    }

    /** Where the layout of paragraph $p starts in the content. @return array{0: float, 1: float} */
    public function origin(int $p): array
    {
        $st = $this->para($p)->style;
        [$left] = $this->insets($st);
        [$above] = $this->space($p, $st);
        return [$left, $this->hs->top($p) + $above];
    }

    /** Where rune $i of paragraph $p is in its layout, which holds the composition at the caret. */
    public function local(int $p, int $i): int
    {
        $l = $i - $this->shown->paraStart($p);
        if ($this->compose !== '' && $i > $this->caret && $this->shown->paraAt($this->caret) === $p) {
            $l += mb_strlen($this->compose, 'UTF-8');
        }
        return $l;
    }

    /** The rune of the text at rune $l of the layout of paragraph $p. */
    public function global(int $p, int $l): int
    {
        $start = $this->shown->paraStart($p);
        if ($this->compose !== '' && $this->shown->paraAt($this->caret) === $p) {
            $n = mb_strlen($this->compose, 'UTF-8'); $c = $this->caret - $start;
            if ($l > $c + $n) {
                $l -= $n;
            } elseif ($l > $c) {
                $l = $c;
            }
        }
        return min($start + $l, $start + $this->para($p)->n);
    }

    /** The caret before rune $i, $extra runes into a composition there, in the content: its x, top and height. @return array{0: float, 1: float, 2: float} */
    public function caretAt(int $i, int $extra, int $affinity): array
    {
        $p = $this->shown->paraAt($i);
        $pv = $this->paraLayout($p);
        $l = min($this->local($p, $i) + $extra, $pv->layout->len());
        [$x, $y, $h] = $pv->layout->caretAt($l, $affinity);
        [$ox, $oy] = $this->origin($p);
        return [$ox + $x, $oy + $y, $h];
    }

    /** The caret position closest to ($x, $y) in the content: its rune and affinity. @return array{0: int, 1: int} */
    public function positionAt(float $x, float $y): array
    {
        [$p, $l, $aff] = $this->layoutPositionAt($x, $y);
        if ($p < 0) { return [$this->shown->len(), Layout::UPSTREAM]; }
        return [$this->global($p, $l), $aff];
    }

    /** The paragraph closest to ($x, $y) in the content and the rune of its layout there, which holds the composition; $p is -1 past the paragraphs. @return array{0: int, 1: int, 2: int} */
    public function layoutPositionAt(float $x, float $y): array
    {
        $n = count($this->paras);
        $y = max($y, 0.0);
        $p = $this->hs->at($y);
        for ($k = 0; $k < $n; $k++) {
            $pv = $this->paraLayout($p);
            $top = $this->hs->top($p);
            if ($y < $top && $p > 0) {
                $p--;
            } elseif ($y >= $top + $pv->h && $p < $n - 1) {
                $p++;
            } else {
                [$ox, $oy] = $this->origin($p);
                [$i, $aff] = $pv->layout->positionAt($x - $ox, $y - $oy);
                return [$p, $i, $aff];
            }
        }
        return [-1, 0, Layout::UPSTREAM];
    }

    /** The edges of the visual line holding rune $i. @return array{0: int, 1: int} */
    public function lineEdges(int $i): array
    {
        $p = $this->shown->paraAt($i);
        $pv = $this->paraLayout($p);
        [, $cy, $ch] = $pv->layout->caretAt(min($this->local($p, $i), $pv->layout->len()), $this->affinity);
        $mid = $cy + $ch / 2;
        [$a] = $pv->layout->positionAt(-1e6, $mid);
        [$z] = $pv->layout->positionAt(1e6, $mid);
        $s = $this->global($p, $a); $e = $this->global($p, $z);
        return $s > $e ? [$e, $s] : [$s, $e];
    }

    /** Scrolls the content to $y, keeping the place by the paragraph there. */
    public function setScroll(float $y): void
    {
        $this->scroll = $y;
        $this->anchor = $this->hs->at($y);
        $this->anchorOff = $y - $this->hs->top($this->anchor);
    }

    /** Lays out the paragraphs in view of a content box $w × $h, and those the caret is in when it is to be revealed. */
    public function layout(float $w, float $h): void
    {
        $key = $this->lookKey();
        if ($w != $this->width || $key !== $this->laidLook) {
            // Another width, font or theme: every paragraph anew.
            $this->laidLook = $key;
            foreach ($this->paras as $i => $_) { $this->paras[$i] = new ParaView; }
            $this->laid = 0;
            $this->width = $w;
            $this->resetHeights();
        }
        $this->viewH = $h;
        $this->estimate();
        $this->place($h);
        if ($this->reveal) {
            $this->reveal = false;
            [, $y, $ch] = $this->caretAt($this->caret, $this->composeCaret, $this->affinity);
            if ($y < $this->scroll) {
                $this->setScroll($y);
            } elseif ($y + $ch > $this->scroll + $h) {
                $p = $this->shown->paraAt($this->caret);
                for ($q = $p - 1, $room = $y + $ch - $this->hs->top($p); $q >= 0 && $room < $h; $q--) {
                    $room += $this->paraLayout($q)->h;
                }
                [, $y, $ch] = $this->caretAt($this->caret, $this->composeCaret, $this->affinity);
                $this->setScroll($y + $ch - $h);
            }
            $this->place($h);
        }
        $total = $this->hs->top(count($this->paras));
        if (($s = max(0.0, min($this->scroll, $total - $h))) != $this->scroll) {
            $this->setScroll($s);
            $this->place($h);
        }
        if ($this->laid > self::MAX_LAID + $this->last - $this->first) { $this->forget(); }
    }

    /** Lays out the paragraphs in view, from the anchor down, and keeps the view where the anchor is. */
    private function place(float $h): void
    {
        $n = count($this->paras);
        $this->anchor = max(0, min($this->anchor, $n - 1));
        $this->paraLayout($this->anchor);
        $this->first = $this->anchor;
        while ($this->first > 0 && $this->hs->top($this->anchor) + $this->anchorOff - $this->hs->top($this->first) < 0) {
            $this->first--;
            $this->paraLayout($this->first);
        }
        $this->setScroll(max(0.0, $this->hs->top($this->anchor) + $this->anchorOff));
        $y = $this->hs->top($this->first);
        for ($p = $this->first; $p < $n; $p++) {
            $y += $this->paraLayout($p)->h;
            if ($y >= $this->scroll + $h) { break; }
        }
        $this->last = min($p, $n - 1);
    }

    /** @internal Document::visibleParagraphs: the boxes of the paragraphs in view, laid out at the editor's size. @return list<ParagraphBox> */
    public function visibleBoxes(): array
    {
        $this->sync();
        $this->layout($this->width, $this->viewH);
        return $this->boxes();
    }

    /** The boxes of the paragraphs in view (mygo's boxes). @return list<ParagraphBox> */
    private function boxes(): array
    {
        $out = [];
        for ($i = $this->first; $i <= $this->last && $i < count($this->paras); $i++) {
            $pv = $this->paraLayout($i);
            [, $y] = $this->origin($i);
            $out[] = new ParagraphBox($i, $this->shown->paraStart($i), $this->padY + $y - $this->scroll, $pv->h - $pv->space);
        }
        return $out;
    }

    /** Asks for another frame when the app read the paragraphs in view and they are no longer where it read them, as painting placed them otherwise: the frame after builds the app's view with them again (mygo's moved). */
    public function moved(): void
    {
        $d = $this->doc;
        [$read, $was] = [$d->read, $d->boxes];
        $d->read = false;
        if (!$read) {
            return;
        }
        if ($this->boxes() != $was && $this->services !== null) {
            $this->services->needsFrame = true; // painted after the frame was built: the window draws another
        }
    }

    /** Drops the layouts of the paragraphs far from view, keeping their heights. */
    private function forget(): void
    {
        $lo = $this->first - intdiv(self::MAX_LAID, 4); $hi = $this->last + intdiv(self::MAX_LAID, 4);
        foreach ($this->paras as $i => $pv) {
            if (($i < $lo || $i > $hi) && $pv->layout !== null) {
                $pv->layout = null; $pv->para = null;
                $this->laid--;
            }
        }
    }

    /** The point of the content at ($x, $y) of the element. @return array{0: float, 1: float} */
    public function contentPoint(float $x, float $y): array { return [$x - $this->padX, $y - $this->padY + $this->scroll]; }

    /** The selection, in order. @return array{0: int, 1: int} */
    public function sel(): array { return [min($this->selAnchor, $this->caret), max($this->selAnchor, $this->caret)]; }

    /** Describes the editor to assistive technology: its text, and the selection. */
    public function access(AccessNode $n): void
    {
        $n->value = $this->shown->string();
        [$n->selStart, $n->selEnd] = $this->sel();
        $n->states |= AccessNode::MULTILINE;
        if ($this->opts->readOnly) { $n->states |= AccessNode::READ_ONLY; }
        if ($this->shown->len() === 0) { $n->placeholder = $this->opts->placeholder; }
    }
}
