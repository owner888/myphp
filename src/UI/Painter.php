<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Turns a laid-out tree into display-list quads: fills, labels, clips. */
final class Painter
{
    private const BOX_RADIUS = 18.0;

    public static function paint(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt = null): void
    {
        if ($list->clipped($n->x, $n->y, $n->w, $n->h)) {
            return;
        }
        if ($n->kind === Node::INPUT) {
            self::paintField($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::TEXTAREA) {
            self::paintArea($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::IMAGE) {
            $s = $n->image->slot($text->images);
            $list->image($n->x, $n->y, $n->w, $n->h, $s['u'], $s['v'], $s['w'], $s['h'], $n->alpha, $n->radius);
            return;
        }
        if ($n->focused) { // a focus ring around a button
            $list->rect($n->x - 2, $n->y - 2, $n->w + 4, $n->h + 4, $t->focus, $t->focusAlpha, $t->radius + 2);
        }
        if ($n->color !== null) {
            $radius = match ($n->kind) { Node::BUTTON => $t->radius, Node::BOX => self::BOX_RADIUS, default => 0.0 };
            $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, 1.0, $radius);
        }
        if ($n->selected) {
            $list->rect($n->x, $n->y, $n->w, $n->h, $t->selection, $t->selectionAlpha);
        }
        if ($n->text !== null) {
            $x = $n->x; $y = $n->y;
            if ($n->kind === Node::BUTTON) { // the label sits centered in the button's padding
                [$tw, $th] = $text->measure($n->text, $n->fontSize);
                $x += ($n->w - $tw) / 2; $y += ($n->h - $th) / 2;
            }
            $text->paint($list, $n->text, $n->fontSize, $x, $y, $n->fg);
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        }
        foreach ($n->kids as $k) {
            self::paint($k, $list, $text, $t, $rt);
        }
        if ($n->scroll !== null) {
            $list->popClip();
            // The thumb shows while the pointer is over the container or drags it.
            if ($rt !== null && ($rt->dragging($n->scroll) || $rt->hovered($n->id))) {
                $thumb = Scrollbar::thumb([$n->x, $n->y, $n->w, $n->h], $n->scroll->extent(), $t->scrollbarWidth);
                if ($thumb !== null) {
                    $list->rect($thumb[0], $thumb[1], $thumb[2], $thumb[3], $t->scrollbar, $t->scrollbarAlpha, $t->scrollbarWidth / 2);
                }
            }
        }
    }

    /** A text field: border, selection, text or placeholder, composition underline, caret. */
    private static function paintField(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt): void
    {
        $s = $n->edit;
        $inset = Runtime::FIELD_INSET;
        $list->rect($n->x, $n->y, $n->w, $n->h, $n->focused ? $t->accent : $t->border, 1.0, $t->radius);
        $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color ?? $t->background, 1.0, $t->radius - 1);
        $display = $s->display();
        [$tw, $lh] = $text->measure($display === '' ? 'X' : $display, $n->fontSize);
        $ty = $n->y + ($n->h - $lh) / 2;
        $inner = $n->w - 2 * $inset;
        // Keep the caret in view when the text is wider than the field.
        $caretX = $text->xForIndex($display, $n->fontSize, $s->displayCaret());
        $shift = max(0.0, $caretX - $inner);
        $tx = $n->x + $inset - $shift;
        $list->pushClip($n->x + 2, $n->y, $n->w - 4, $n->h);
        if ($display === '') {
            if ($n->text !== null && $n->text !== '') {
                $text->paint($list, $n->text, $n->fontSize, $tx, $ty, $t->textMuted);
            }
        } else {
            if ($n->focused && $s->hasSelection() && $s->marked === null) {
                [$a, $b] = $s->selection();
                $xa = $text->xForIndex($display, $n->fontSize, $a);
                $xb = $text->xForIndex($display, $n->fontSize, $b);
                $list->rect($tx + $xa, $ty, $xb - $xa, $lh, $t->selection, $t->selectionAlpha);
            }
            $text->paint($list, $display, $n->fontSize, $tx, $ty, $n->fg);
            if ($s->marked !== null) {
                $xa = $text->xForIndex($display, $n->fontSize, $s->cursor);
                $xb = $text->xForIndex($display, $n->fontSize, $s->cursor + strlen($s->marked));
                $list->rect($tx + $xa, $ty + $lh - 1, $xb - $xa, 1.5, $n->fg);
            }
        }
        if ($n->focused && ($rt === null || $rt->caretVisible())) {
            $list->rect($tx + $caretX, $ty, 1.0, $lh, $n->fg);
        }
        $list->popClip();
    }

    /** A text area: border, wrapped lines scrolled to the caret, selection across lines, composition underline, caret. */
    private static function paintArea(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt): void
    {
        $s = $n->edit;
        $inset = Runtime::FIELD_INSET;
        $list->rect($n->x, $n->y, $n->w, $n->h, $n->focused ? $t->accent : $t->border, 1.0, $t->radius);
        $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color ?? $t->background, 1.0, $t->radius - 1);
        $display = $s->display();
        $innerW = $n->w - 2 * $inset; $innerH = $n->h - 2 * $inset;
        $layout = $s->layout($text, $n->fontSize, $innerW);
        $lh = $layout->lineHeight;
        // Keep the caret's line in view; never scroll past the text.
        $s->scrollY = max(0.0, min($s->scrollY, $layout->height() - $innerH));
        if ($n->focused) {
            [, $cy] = $layout->caret($text, $s->displayCaret());
            if ($cy < $s->scrollY) { $s->scrollY = $cy; }
            if ($cy + $lh > $s->scrollY + $innerH) { $s->scrollY = $cy + $lh - $innerH; }
        }
        $tx = $n->x + $inset; $ty = $n->y + $inset - $s->scrollY;
        $list->pushClip($n->x + 2, $n->y + 2, $n->w - 4, $n->h - 4);
        if ($display === '' && $n->text !== null && $n->text !== '') {
            $text->paint($list, $n->text, $n->fontSize, $tx, $ty, $t->textMuted);
        }
        [$selA, $selB] = $n->focused && $s->hasSelection() && $s->marked === null ? $s->selection() : [0, 0];
        $first = max(0, (int)floor($s->scrollY / $lh));
        $last = min(count($layout->lines) - 1, (int)floor(($s->scrollY + $innerH) / $lh));
        for ($i = $first; $i <= $last; $i++) {
            [$a, $b] = $layout->lines[$i];
            $line = $layout->lineText($i);
            $y = $ty + $i * $lh;
            if ($selB > $selA && $selB > $a && $selA < $b) {
                $xa = $selA > $a ? $text->xForIndex($line, $n->fontSize, $selA - $a) : 0.0;
                $xb = $selB < $b ? $text->xForIndex($line, $n->fontSize, min($selB - $a, strlen($line))) : $text->xForIndex($line, $n->fontSize, strlen($line)) + ($selB > $b ? $n->fontSize * 0.3 : 0);
                $list->rect($tx + $xa, $y, $xb - $xa, $lh, $t->selection, $t->selectionAlpha);
            }
            if ($line !== '') {
                $text->paint($list, $line, $n->fontSize, $tx, $y, $n->fg);
            }
        }
        if ($s->marked !== null) {
            [$xa, $ya] = $layout->caret($text, $s->cursor);
            [$xb, $yb] = $layout->caret($text, $s->cursor + strlen($s->marked));
            if ($yb !== $ya) { $xb = $innerW; }
            $list->rect($tx + $xa, $ty + $ya + $lh - 1, $xb - $xa, 1.5, $n->fg);
        }
        if ($n->focused && ($rt === null || $rt->caretVisible())) {
            [$cx, $cy] = $layout->caret($text, $s->displayCaret());
            $list->rect($tx + $cx, $ty + $cy, 1.0, $lh, $n->fg);
        }
        $list->popClip();
    }
}
