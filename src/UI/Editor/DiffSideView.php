<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, DisplayList, InputEvent, Key, Node, TextPainter};

/** Shows a side of a diff in an editor (mygo's diffSide): its colors, words, numbers and blocks. */
final class DiffSideView implements Decor
{
    /** @var list<array{0: int, 1: int}> the lines it hides */
    public array $hide = [];
    /** @var list<Block> */
    public array $blks = [];
    public bool $unified = false;

    public function __construct(public DiffState $d, public bool $old) {}

    /** @return list<array{0: int, 1: int}> */
    public function hidden(): array { return $this->hide; }

    /** @return list<Block> */
    public function blocks(): array { return $this->blks; }

    /** The hunk holding $line of the side, or -1. */
    public function hunkOf(int $line): int
    {
        $hs = $this->d->model->hunks;
        if ($this->old) {
            $i = Finder::lowerBound(count($hs), fn(int $k) => $hs[$k]->a1 > $line);
            return $i < count($hs) && $hs[$i]->a0 <= $line ? $i : -1;
        }
        $i = Finder::lowerBound(count($hs), fn(int $k) => $hs[$k]->b1 > $line);
        return $i < count($hs) && $hs[$i]->b0 <= $line ? $i : -1;
    }

    private function colors(Editor $ed): array { return $ed->theme->diff(Theme::isDark($ed->theme->c['background'])); }

    private function editor(): Editor { return $this->d->eds[$this->old ? 0 : 1]; }

    public function lineStyle(int $line): LineLook
    {
        $d = $this->d;
        $st = new LineLook;
        $ed = $this->editor();
        if ($this->hunkOf($line) >= 0) {
            $dc = $this->colors($ed);
            if ($this->old) {
                $st->bg = $dc['deletedLine']; $st->gutter = $dc['deletedGutter']; $st->number = $dc['deleted'];
                $st->bar = $dc['deleted']; $st->stripes = true; $st->sign = '-';
            } else {
                $st->bg = $dc['addedLine']; $st->gutter = $dc['addedGutter']; $st->number = $dc['added'];
                $st->bar = $dc['added']; $st->sign = '+';
            }
            if ($d->noBackground) { $st->bg = [0, 0.0]; $st->gutter = [0, 0.0]; }
            if ($d->indicators !== DiffIndicators::DiffBars) { $st->bar = [0, 0.0]; }
        }
        $side = $this->old ? DiffSide::DiffOld : DiffSide::DiffNew;
        $sel = $d->selectedLines();
        if ($sel !== null && $sel->side === $side && $line >= $sel->start && $line <= $sel->end) {
            $m = $ed->theme->c['modified'];
            $bg = $st->bg[1] == 0 ? $ed->theme->c['background'] : $st->bg;
            $st->bg = Theme::mixLab($bg, $m, 0.18);
            $g = $st->gutter[1] == 0 ? $ed->theme->c['background'] : $st->gutter;
            $st->gutter = Theme::mixLab($g, $m, 0.3);
            $st->number = $m;
        }
        return $st;
    }

    public function words(int $line): array
    {
        $d = $this->d;
        if ($d->inline === DiffInline::DiffInlineNone) { return []; }
        $i = $this->hunkOf($line);
        if ($i < 0) { return []; }
        $w = $d->model->wordsOf($i, $d->inline);
        return ($this->old ? $w->oldWords : $w->newWords)[$line] ?? [];
    }

    public function number(int $line): int
    {
        if (($pt = $this->d->partial) !== null) { return $pt->fileLine($this->old, $line) + 1; }
        return $line + 1;
    }

    /** Paints a block: a filler of rows a side lacks, hatched, or the deleted lines of a unified view. Separators leave their room to the nodes over it (DiffState::buildSeparators). */
    public function paintBlock(DisplayList $list, TextPainter $text, Editor $ed, Block $b, array $r): void
    {
        if ($b->kind === Block::SPACER) {
            if ($b->from === 0) { return; }
            // A filler: hatched at -45°, every 8 points, as Pierre's.
            [$rx, $ry, $rw, $rh] = $r;
            $tx = $rx + $ed->gutterW; $tw = $rw - $ed->gutterW;
            $c = $ed->theme->c['text'];
            $list->pushClip($tx, $ry, $tw, $rh);
            for ($x = $tx - $rh; $x < $tx + $tw; $x += 8) { $list->line($x, $ry + $rh, $x + $rh, $ry, $c[0], $c[1] * 0.07, 1); }
            $list->popClip();
        } elseif ($b->kind === Block::DELETED) {
            $this->paintDeleted($list, $ed, $b, $r);
        }
    }

    /** Paints the lines a unified view shows deleted: the old text's, tinted, with their numbers in the gutter. */
    private function paintDeleted(DisplayList $list, Editor $ed, Block $b, array $r): void
    {
        $d = $this->d;
        $ov = $d->oldView;
        $ov->setTheme($ed->theme);
        $ov->advanceSyntax($b->to, 0);
        $dc = $this->colors($ed);
        $lh = $ed->view->lineHeight;
        [$rx, $ry, $rw, $rh] = $r;
        $ox = $rx + $ed->textX - $ed->scrollX;
        $tx = $rx + $ed->gutterW; $tw = $rw - $ed->gutterW;
        $words = $d->model->wordsOf($b->hunk, $d->inline);
        $vis = $list->visible();
        // Each line's top, and the lines in the clip.
        $lines = [];
        $y = $ry;
        for ($line = $b->from; $line < $b->to && $y <= $vis[1] + $vis[3]; $line++) {
            $l = $ov->line($line);
            $h = count($l->rows) * $lh;
            if ($y + $h >= $vis[1]) { $lines[] = [$line, $y, $h, $l]; }
            $y += $h + $d->annotationHeight(DiffSide::DiffOld, $line);
        }
        foreach ($lines as [$line, $py, $ph]) {
            if (!$d->noBackground) {
                $list->rect($tx, $py, $tw, $ph, $dc['deletedLine'][0], $dc['deletedLine'][1]);
                $list->rect($rx, $py, $ed->gutterW, $ph, $dc['deletedGutter'][0], $dc['deletedGutter'][1]);
            }
            if (!$d->noLineNumbers) {
                $n = $d->partial !== null ? $d->partial->fileLine(true, $line) + 1 : $line + 1;
                [$nl, $nw] = $ed->lone((string)$n);
                $col = $dc['deleted'];
                $ed->view->paintRow($list, $nl, $nl->rows[0], $rx + $ed->gutterW - 4 - $ed->view->charWidth * 0.5 - $nw, $py, [[], []], fn() => $col, $vis[0], $vis[2]);
            }
            if ($d->indicators === DiffIndicators::DiffBars) { $ed->paintBar($list, $rx, $py, 4, $ph, new LineLook(bar: $dc['deleted'], stripes: true)); }
        }
        $list->pushClip($tx, $ry, $tw, $rh);
        foreach ($lines as [$line, $py, , $l]) {
            $cc = $ov->colorsOf($l);
            if ($d->indicators === DiffIndicators::DiffClassic) { $this->paintSign($list, $ed, '-', $rx + $ed->textX - $ed->view->charWidth * 1.5, $py, $dc['deleted']); }
            foreach ($l->rows as $ri => $row) {
                $ry2 = $py + $ri * $lh;
                if ($d->inline !== DiffInline::DiffInlineNone) {
                    foreach ($words->oldWords[$line] ?? [] as [$wa, $wb]) {
                        foreach ($ov->rangeRects($l, $row, $l->toDisp($wa, false), $l->toDisp($wb, false)) as [$qx, $qw]) {
                            $list->rect($ox + $qx, $ry2, $qw, $lh, $dc['deletedWord'][0], $dc['deletedWord'][1], 3);
                        }
                    }
                }
                $ov->paintRow($list, $l, $row, $ox, $ry2, $cc, null, $vis[0], $vis[2]);
            }
        }
        $list->popClip();
    }

    /** Paints a classic + or - before a line. */
    public function paintSign(DisplayList $list, Editor $ed, string $sign, float $x, float $y, array $c): void
    {
        [$l] = $ed->lone($sign);
        $vis = $list->visible();
        $ed->view->paintRow($list, $l, $l->rows[0], $x, $y, [[], []], fn() => $c, $vis[0], $vis[2]);
    }

    /** Takes presses on the numbers of lines, which select them, and on the deleted lines of a unified view. Separators and buttons are nodes of their own (DiffState::buildSeparators, buildBlocks). */
    public function blockInput(Editor $ed, ?Block $b, InputEvent $ev, array $r): bool
    {
        $d = $this->d;
        $side = $this->old ? DiffSide::DiffOld : DiffSide::DiffNew;
        if ($b !== null) {
            if ($b->kind !== Block::DELETED) { return false; }
            // Lines of the old text: their numbers select them.
            if ($ev->x < $ed->gutterW && $d->lineSelection && $ev->kind === InputEvent::POINTER_DOWN) {
                // The line by the heights of its rows and annotations.
                $lh = $ed->view->lineHeight;
                $y = $r[1]; $line = $b->to - 1;
                for ($l = $b->from; $l < $b->to; $l++) {
                    $y += $d->oldView->heights->wraps->rows($l) * $lh + $d->annotationHeight(DiffSide::DiffOld, $l);
                    if ($ev->y < $y) { $line = $l; break; }
                }
                $d->selectLine(DiffSide::DiffOld, $line, ($ev->mods & Key::SHIFT) !== 0);
            }
            return true;
        }
        // A press on the gutter of a line, in $r.
        if ($ev->kind !== InputEvent::POINTER_DOWN) { return false; }
        [, $cy] = $ed->toContent($ev->x, $ev->y);
        $line = $ed->view->heights->lineAt($cy);
        if ($d->lineSelection) {
            $d->selectLine($side, $line, ($ev->mods & Key::SHIFT) !== 0);
            return true;
        }
        return false;
    }

    /** Builds what blocks hold of nodes in view: annotations, the actions of hunks and the gutter's button. */
    public function buildBlocks(Context $c, Editor $ed): void
    {
        $d = $this->d;
        $v = $ed->view;
        $h = $v->heights;
        if ($ed->r[2] <= 0) { return; }
        [$y0, $y1] = $ed->o->fitContent ? [0.0, $h->total()] : [$ed->scrollY, $ed->scrollY + $ed->viewH];
        $first = $h->lineAt($y0); $last = $h->lineAt($y1);
        $side = $this->old ? DiffSide::DiffOld : DiffSide::DiffNew;
        // Annotations under their lines, after one another on a line.
        if ($d->renderAnnotation !== null && $d->annotations !== []) {
            $stacked = []; // by where they go: 0 a line, 1 a deleted line
            foreach ($d->annotations as $a) {
                $line = $a->line;
                if ($this->unified && $a->side === DiffSide::DiffOld) { $line = $d->model->newLineOf($a->line) ?? -1; }
                if ($a->side === $side || $this->unified && $a->side === DiffSide::DiffOld && $line >= 0) {
                    if ($line < $first || $line > $last) { continue; }
                    $g = $h->geometry($line);
                    if ($g->hidden) { continue; }
                    $top = $g->below;
                    // After the blocks below it that come first.
                    [, $below] = $h->blocksOf($line);
                    foreach ($below as $b) {
                        if ($b->kind === Block::ANNOTATION) { break; }
                        $top += $b->height;
                    }
                    $at = "0 $line";
                } elseif ($this->unified && $a->side === DiffSide::DiffOld) {
                    // In the block of deleted lines it is in.
                    $top = null;
                    for ($ln = $first; $ln <= $last && $top === null; $ln++) {
                        [$above, $below] = $h->blocksOf($ln);
                        $g = $h->geometry($ln);
                        foreach ([$above, $below] as $blks) {
                            $bt = $blks !== [] && $blks[0]->below ? $g->below : $g->top;
                            foreach ($blks as $b) {
                                if ($b->kind === Block::DELETED && $a->line >= $b->from && $a->line < $b->to) {
                                    $off = $bt;
                                    for ($l = $b->from; $l <= $a->line; $l++) {
                                        $off += $d->oldView->heights->wraps->rows($l) * $v->lineHeight;
                                        if ($l < $a->line) { $off += $d->annotationHeight(DiffSide::DiffOld, $l); }
                                    }
                                    $top = $off;
                                    break;
                                }
                                $bt += $b->height;
                            }
                            if ($top !== null) { break; }
                        }
                    }
                    if ($top === null) { continue; }
                    $at = "1 {$a->line}";
                } else {
                    continue;
                }
                $n = $stacked[$at] ?? 0.0;
                $top += $n;
                $stacked[$at] = $n + $a->height;
                $key = json_encode($a->key);
                $box = $c->key("annotation {$a->side->value} {$a->line} $key $n")->column()->at($ed->gutterW, $top - $ed->scrollY)->size($ed->r[2] - $ed->gutterW, $a->height)->handleInput(Editor::shield(...));
                ($d->renderAnnotation)($c, $a);
                $c->end();
            }
        }
        // Revert and Keep on the hunk under the pointer, or the caret's.
        if ($d->hunkActions && !$this->old) {
            $active = $d->activeHunk();
            foreach ($d->model->hunks as $i => $hk) {
                if ($i !== $active) { continue; }
                $line = $hk->b0;
                if ($line > $last + 1 || $hk->b1 < $first && $hk->b0 < $first) { continue; }
                if ($this->unified && $hk->a1 > $hk->a0) {
                    if ($line >= $v->text->lineCount()) {
                        $line = $v->text->lineCount() - 1;
                        $top = $h->geometry($line)->below;
                    } else {
                        $top = $h->geometry($line)->top;
                        [$above] = $h->blocksOf($line);
                        foreach ($above as $b) {
                            if ($b->kind === Block::DELETED) { break; }
                            $top += $b->height;
                        }
                    }
                } else {
                    $line = min($line, $v->text->lineCount() - 1);
                    $top = $h->geometry($line)->rows;
                }
                if ($top < $y0 - $v->lineHeight || $top > $y1) { continue; }
                // At the right, clear of the scroll bar: as wide as it was last frame.
                $bar = $c->key("hunk actions $i")->row()->cursor(\MyPHP\UI\Cursor::Default)->keepFocus()->handleInput(Editor::shield(...));
                $w = $c->runtime?->rectOf($bar->id)[2] ?? 0.0;
                $bar->at($ed->r[2] - $ed->trackWidth() - 6 - $w, $top - $ed->scrollY + 2);
                ($d->renderHunkActions ?? DiffHunkActions::default(...))($c, $bar, new DiffHunkActions($i, new DiffHunk($hk->a0, $hk->a1, $hk->b0, $hk->b1), $d));
                $c->end();
            }
        }
        // The gutter's button, on the line under the pointer.
        if ($d->onGutterButton !== null && $ed->pointerIn && $ed->hoverLine >= 0 && $ed->hoverLine < $v->text->lineCount() && $ed->pointer[0] < $ed->gutterW + 40) {
            $g = $h->geometry($ed->hoverLine);
            if (!$g->hidden) {
                $range = new DiffLineRange($side, $ed->hoverLine, $ed->hoverLine);
                $c->key('gutter button')->column()->at(2, $g->rows - $ed->scrollY + 2)->handleInput(Editor::shield(...));
                  $btn = $c->row()->size(16, $v->lineHeight - 4)->cursor(\MyPHP\UI\Cursor::Pointer)->keepFocus();
                  ($d->renderGutterButton ?? DiffState::defaultGutterButton(...))($c, $btn, $range);
                  $c->end();
                  if ($c->clicked($btn)) { ($d->onGutterButton)($range); }
                $c->end();
            }
        }
    }
}
