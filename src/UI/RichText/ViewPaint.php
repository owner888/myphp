<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Layout;
use MyPHP\UI\{DisplayList, Node, Painter, Path, Span, TextPainter};

/**
 * Painting an editor (mygo's richtext paint.go): its paragraphs'
 * decorations, the selection, their text, the markers of lists, the
 * composition, the caret and the scroll bar.
 *
 * @internal part of View
 */
trait ViewPaint
{
    /** Lays out the paragraphs in view of the box $r, and paints them. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function paint(DisplayList $list, TextPainter $text, array $r): void
    {
        $this->rect = $r;
        $w = max($r[2] - 2 * $this->padX, 1.0); $h = max($r[3] - 2 * $this->padY, 1.0);
        $this->layout($w, $h);
        $ox = $r[0] + $this->padX; $oy = $r[1] + $this->padY;
        $th = $this->look->theme;
        if ($this->shown->len() === 0 && $this->compose === '' && $this->opts->placeholder !== '') {
            [$family, $size, $weight] = $this->paraFont($this->para(0)->style);
            $pl = ParaLayout::shape($this->sys, [new Span($this->opts->placeholder)], $family, $size, $weight, $w, \MyPHP\Text\Params::START);
            $pl->paint($list, $text, $ox, $oy, $th->muted[0], $th->muted[1]);
        }
        [$a, $z] = $this->sel();
        $focused = $this->focused;
        for ($i = $this->first; $i <= $this->last && $i < count($this->paras); $i++) {
            $pv = $this->paraLayout($i);
            $st = $this->para($i)->style;
            $top = $oy + $this->hs->top($i) - $this->scroll;
            [$px, $py] = $this->origin($i);
            $x = $ox + $px; $y = $oy + $py - $this->scroll;
            $this->paintBlock($list, $i, $st, $ox, $top, $pv->h, $w);
            $ps = $this->shown->paraStart($i);
            $pe = $ps + $this->para($i)->n;
            if ($a !== $z && $a <= $pe && $z >= $ps && ($focused || $this->opts->readOnly)) {
                $from = $this->local($i, max($a, $ps)); $to = $this->local($i, min($z, $pe));
                [$rgb, $alpha] = $th->selection;
                foreach ($pv->layout->selectionRects($from, $to) as [$sx, $sy, $sw, $sh]) {
                    $list->rect($x + $sx, $y + $sy, $sw, $sh, $rgb, $alpha);
                }
                if ($z > $pe && $i < count($this->paras) - 1) {
                    // The newline selected, as a space after the line.
                    [$cx, $cy, $ch] = $pv->layout->caretAt($pv->layout->len(), Layout::UPSTREAM);
                    $list->rect($x + $cx, $y + $cy, $this->look->size * 0.35, $ch, $rgb, $alpha);
                }
            }
            $pv->layout->paint($list, $text, $x, $y, $th->text[0], $th->text[1]);
            $this->paintAttachments($list, $text, $i, $pv, $x, $y);
            $this->paintMarker($list, $i, $st, $pv, $x, $y, $text);
            if ($this->compose !== '' && $this->shown->paraAt($this->caret) === $i) {
                $c = $this->local($i, $this->caret);
                $n = mb_strlen($this->compose, 'UTF-8');
                foreach ($pv->layout->selectionRects($c, min($c + $n, $pv->layout->len())) as [$sx, $sy, $sw, $sh]) {
                    $list->rect($x + $sx, $y + $sy + $sh - 2, $sw, 1, $th->text[0], $th->text[1]);
                }
            }
        }
        if ($focused && !$this->opts->readOnly && $a === $z) {
            $phase = ($this->now() - $this->blinkStart) / 1e9;
            if ($phase < 0 || ((int)floor($phase / self::BLINK)) % 2 === 0) {
                [$cx, $cy, $ch] = $this->caretAt($this->caret, $this->composeCaret, $this->affinity);
                $list->rect($ox + $cx, $oy + $cy - $this->scroll, max(1 / $text->scale(), 1.0), $ch, $th->caret[0], $th->caret[1]);
            }
        }
        if ($this->hs->top(count($this->paras)) > $h) {
            [$top, $size] = $this->thumbBox();
            $list->rect($r[0] + $r[2] - 8, $r[1] + $top, 5, $size, $th->text[0], $th->text[1] * 0.3, 2.5);
        }
    }

    /** Paints what a paragraph of style $st shows behind its text: the background of a block of code, the bar of a quotation. */
    private function paintBlock(DisplayList $list, int $i, ParagraphStyle $st, float $x, float $top, float $height, float $width): void
    {
        $look = $this->look; $th = $look->theme;
        $indent = $st->indent * $look->indent;
        if ($st->codeBlock) {
            // One block for the paragraphs of code next to each other, rounded at its ends.
            $radius = $look->size * $th->codeBlockRadius;
            $box = [$x + $indent, $top, $width - $indent, $height];
            $first = $i === 0 || !$this->para($i - 1)->style->codeBlock;
            $last = $i + 1 >= count($this->paras) || !$this->para($i + 1)->style->codeBlock;
            $fill = $box;
            if (!$first) { $fill[1] -= 2 * $radius; $fill[3] += 2 * $radius; }
            if (!$last) { $fill[3] += 2 * $radius; }
            $list->pushClip(...$box);
            $list->rect($fill[0], $fill[1], $fill[2], $fill[3], $th->codeBlockBackground[0], $th->codeBlockBackground[1], $radius);
            $list->popClip();
        }
        if ($st->quote) {
            $w = $th->quoteBarWidth;
            $list->rect($x + $indent, $top, $w, $height, $th->quoteBar[0], $th->quoteBar[1], $w / 2);
        }
    }

    /** Paints the marker of an item of a list: its bullet, its number, or its check box. */
    private function paintMarker(DisplayList $list, int $i, ParagraphStyle $st, ParaView $pv, float $x, float $y, TextPainter $text): void
    {
        if ($st->list === ListKind::NoList) { return; }
        $look = $this->look; $th = $look->theme;
        [, $ly, $lh] = $pv->layout->caretAt(0);
        [$family, $size, $weight] = $this->paraFont($st);
        if ($st->list === ListKind::Tasks) {
            $box = $size * 0.95;
            $bx = $x - $look->indent + ($look->indent - $box) / 2 - $look->indent * 0.1;
            $by = $y + $ly + ($lh - $box) / 2;
            if ($st->checked) {
                $list->rect($bx, $by, $box, $box, $th->checkbox[0], $th->checkbox[1], $box * 0.25);
                $path = (new Path())->moveTo($bx + $box * 0.25, $by + $box * 0.52)->lineTo($bx + $box * 0.43, $by + $box * 0.7)->lineTo($bx + $box * 0.76, $by + $box * 0.32);
                $list->path($path, $th->checkmark[0], $th->checkmark[1], max(1.5, $box * 0.12));
            } else {
                $list->ring($bx, $by, $box, $box, $th->muted[0], $th->muted[1], $box * 0.25, 1.25);
            }
            return;
        }
        $mark = $this->marker($i, $st);
        if ($pv->markerLayout === null || $pv->marker !== $mark) {
            $pv->marker = $mark;
            $pv->markerLayout = ParaLayout::shape($this->sys, [new Span($mark)], $family, $size, $weight, 0, \MyPHP\Text\Params::START);
        }
        [$mw] = $pv->markerLayout->size();
        $mx = $st->list === ListKind::Bullets ? $x - $look->indent * 0.5 - $mw / 2 : $x - $mw - $look->indent * 0.25;
        $pv->markerLayout->paint($list, $text, $mx, $y + $ly, $th->marker[0], $th->marker[1]);
    }

    /** The number of the item of a numbered list that paragraph $p is: one more than the item of the same level before it, the deeper ones between them skipped, from 1 for the first. */
    private function listNumber(int $p): int
    {
        if ($this->numbersOf === null || !$this->numbersOf->same($this->shown)) {
            $this->numbers = [];
            $this->numbersOf = $this->shown;
        }
        if (isset($this->numbers[$p])) { return $this->numbers[$p]; }
        $st = $this->para($p)->style;
        $n = 1;
        for ($q = $p - 1; $q >= 0; $q--) {
            $qs = $this->para($q)->style;
            if ($qs->list === ListKind::NoList || $qs->indent < $st->indent) { break; }
            if ($qs->indent > $st->indent) { continue; }
            if ($qs->list === ListKind::Numbers) { $n = $this->listNumber($q) + 1; }
            break;
        }
        return $this->numbers[$p] = $n;
    }

    /** What marks item $p of a list: a bullet for its level, or its number, in digits, letters or Roman numerals by level. */
    private function marker(int $p, ParagraphStyle $st): string
    {
        if ($st->list === ListKind::Bullets) { return ['•', '◦', '▪'][$st->indent % 3]; }
        if ($st->list !== ListKind::Numbers) { return ''; }
        $n = $this->listNumber($p);
        return match ($st->indent % 3) {
            1 => self::alphaNumber($n),
            2 => self::romanNumber($n),
            default => (string)$n,
        } . '.';
    }

    /** $n as letters: a, b, … z, aa. */
    private static function alphaNumber(int $n): string
    {
        $b = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) { $b = chr(ord('a') + ($n - 1) % 26) . $b; }
        return $b;
    }

    /** $n in lowercase Roman numerals. */
    private static function romanNumber(int $n): string
    {
        if ($n <= 0 || $n >= 4000) { return (string)$n; }
        $b = '';
        foreach ([1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i'] as $val => $sym) {
            for (; $n >= $val; $n -= $val) { $b .= $sym; }
        }
        return $b;
    }

    /** Draws the attachments of paragraph $i in the boxes its layout, at ($x, $y), keeps for them: an image, the app's drawing, or a box with the attachment's name. */
    private function paintAttachments(DisplayList $list, TextPainter $text, int $i, ParaView $pv, float $x, float $y): void
    {
        $objects = $pv->layout->objects();
        if ($objects === []) { return; }
        $ps = $this->shown->paraStart($i);
        $th = $this->look->theme;
        $composing = $this->compose !== '' && $this->shown->paraAt($this->caret) === $i;
        foreach ($objects as [$l, $o]) {
            if ($composing && $l >= $this->caret - $ps && $l < $this->caret - $ps + mb_strlen($this->compose, 'UTF-8')) { continue; }
            $at = $this->shown->attrsAt($this->global($i, $l))->attachment;
            $r = [$x + $o[0], $y + $o[1], $o[2], $o[3]];
            if ($at === null) { continue; }
            if ($at->draw !== null) {
                ($at->draw)($list, $r);
            } elseif ($at->image !== null) {
                Painter::picture($list, $text, $at->image, $r[0], $r[1], $r[2], $r[3], Node::CONTAIN, $th->text[0]);
            } else {
                // Not loaded: a box with its name.
                $list->rect($r[0], $r[1], $r[2], $r[3], $th->codeBlockBackground[0], $th->codeBlockBackground[1], 4);
                $list->ring($r[0], $r[1], $r[2], $r[3], $this->look->border[0], $this->look->border[1], 4, 1);
                if ($at->name !== '') {
                    $nl = ParaLayout::shape($this->sys, [new Span($at->name)], '', $this->look->size * 0.85, 0, max($r[2] - 8, 1.0), \MyPHP\Text\Params::START);
                    [, $lh] = $nl->size();
                    $list->pushClip(...$r);
                    $nl->paint($list, $text, $r[0] + 4, $r[1] + ($r[3] - $lh) / 2, $th->muted[0], $th->muted[1]);
                    $list->popClip();
                }
            }
        }
    }
}
