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
        if ($n->kind === Node::RICH) {
            self::paintRich($n, $list, $text);
            return;
        }
        if ($n->kind === Node::CHECKBOX || $n->kind === Node::RADIO) {
            self::paintToggle($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::SLIDER) {
            self::paintSlider($n, $list, $t);
            return;
        }
        if ($n->kind === Node::PROGRESS) { // a bar of fixed thickness, centered in the node (a row stretches it to the row's height)
            $h = $t->space(1.5); $y = $n->y + ($n->h - $h) / 2; $r = $h / 2;
            $list->rect($n->x, $y, $n->w, $h, $t->border, 1.0, $r);
            if ($n->fraction >= 0) {
                $list->rect($n->x, $y, $n->w * min(1.0, $n->fraction), $h, $t->accent, 1.0, $r);
            } else { // indeterminate: a third of the bar sweeping across, once every 1.5 s
                $phase = fmod(hrtime(true) / 1.5e9, 1.0);
                $bar = $n->w / 3;
                $x = $n->x - $bar + $phase * ($n->w + $bar);
                $list->pushClip($n->x, $y, $n->w, $h);
                $list->rect($x, $y, $bar, $h, $t->accent, 1.0, $r);
                $list->popClip();
            }
            return;
        }
        if ($n->kind === Node::IMAGE) {
            $s = $text->imageSlot($n->image);
            $list->image($n->x, $n->y, $n->w, $n->h, $s['u'], $s['v'], $s['w'], $s['h'], $n->alpha, $n->radius);
            return;
        }
        if ($n->focused && $n->color !== null) { // a ring around a button: a filled rect its own face covers but for the rim
            $list->rect($n->x - 2, $n->y - 2, $n->w + 4, $n->h + 4, $t->focus, $t->focusAlpha, $t->radius + 2);
        }
        if ($n->color !== null) {
            $radius = match ($n->kind) { Node::BUTTON, Node::SELECT => $t->radius, Node::BOX => self::BOX_RADIUS, default => 0.0 };
            if ($n->kind === Node::SELECT) { // a bordered face
                $list->rect($n->x, $n->y, $n->w, $n->h, $t->border, 1.0, $radius);
                $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color, 1.0, $radius - 1);
            } else {
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, 1.0, $radius);
            }
        }
        if ($n->selected) {
            $list->rect($n->x, $n->y, $n->w, $n->h, $t->selection, $t->selectionAlpha);
        }
        if ($n->text !== null) {
            $x = $n->x; $y = $n->y;
            if ($n->kind === Node::BUTTON) { // the label sits centered in the button's padding
                [$tw, $th] = $text->measure($n->text, $n->fontSize);
                $x += ($n->w - $tw) / 2; $y += ($n->h - $th) / 2;
            } elseif ($n->kind === Node::SELECT) { // the choice at the left, a chevron at the right
                [, $th] = $text->measure($n->text, $n->fontSize);
                [$aw, $ah] = $text->measure('⌄', $n->fontSize);
                $x += $t->space(2.5); $y += ($n->h - $th) / 2;
                $text->paint($list, '⌄', $n->fontSize, $n->x + $n->w - $aw - $t->space(2.5), $n->y + ($n->h - $ah) / 2 - 2, $t->textMuted);
            }
            $text->paint($list, $n->text, $n->fontSize, $x, $y, $n->fg, 1.0, $n->style);
            if ($n->underline) {
                $list->rect($x, $y + $text->ascent($n->fontSize, $n->style) + 1, $n->w, 1.0, $n->fg);
            }
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        }
        foreach ($n->kids as $k) {
            self::paint($k, $list, $text, $t, $rt);
        }
        if ($n->focused && $n->color === null) { // a list or other background-less node: four edges over its children
            $list->rect($n->x, $n->y, $n->w, 2, $t->focus, $t->focusAlpha);
            $list->rect($n->x, $n->y + $n->h - 2, $n->w, 2, $t->focus, $t->focusAlpha);
            $list->rect($n->x, $n->y, 2, $n->h, $t->focus, $t->focusAlpha);
            $list->rect($n->x + $n->w - 2, $n->y, 2, $n->h, $t->focus, $t->focusAlpha);
        }
        if ($n->scroll !== null) {
            $list->popClip();
            // The thumbs show while the pointer is over the container or drags one.
            if ($rt !== null && ($rt->dragging($n->scroll) || $rt->hovered($n->id))) {
                $r = [$n->x, $n->y, $n->w, $n->h];
                $thumb = $n->scrollY ? Scrollbar::thumb($r, $n->scroll->extent(), $t->scrollbarWidth) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0], $thumb[1], $thumb[2], $thumb[3], $t->scrollbar, $t->scrollbarAlpha, $t->scrollbarWidth / 2);
                }
                $thumb = $n->scrollX ? Scrollbar::thumb($r, $n->scroll->extentX(), $t->scrollbarWidth, true) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0], $thumb[1], $thumb[2], $thumb[3], $t->scrollbar, $t->scrollbarAlpha, $t->scrollbarWidth / 2);
                }
            }
        }
    }

    /** The tip the pointer rests on, if any: a small surface below and right of the pointer, kept inside the root. */
    public static function tooltip(Node $root, DisplayList $list, Text $text, Theme $t, Runtime $rt): void
    {
        if ($rt->tip === null) {
            return;
        }
        [$tip, $mx, $my] = $rt->tip;
        $size = $t->fontSize - 1;
        [$tw, $th] = $text->measure($tip, $size);
        $pad = $t->space(2);
        $w = $tw + 2 * $pad; $h = $th + 2 * $pad * 0.6;
        $x = min($mx + 12, $root->x + $root->w - $w - 4);
        $y = $my + 18;
        if ($y + $h > $root->y + $root->h) {
            $y = $my - $h - 6;
        }
        $list->shadow($x, $y, $w, $h, 0x000000, $t->dark ? 0.4 : 0.18, $t->radius, 8, 0, 3);
        $list->rect($x, $y, $w, $h, $t->border, 1.0, $t->radius);
        $list->rect($x + 1, $y + 1, $w - 2, $h - 2, $t->surface, 1.0, $t->radius - 1);
        $text->paint($list, $tip, $size, $x + $pad, $y + $pad * 0.6, $t->text);
    }

    /** A checkbox (square, a check mark when on) or radio (circle, a dot when on) with its label; the ring marks the focus. */
    private static function paintToggle(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt): void
    {
        $box = $t->space(4);
        $y = $n->y + ($n->h - $box) / 2;
        $radius = $n->kind === Node::RADIO ? $box / 2 : $t->radius - 2;
        if ($n->focused) {
            $list->rect($n->x - 2, $y - 2, $box + 4, $box + 4, $t->focus, $t->focusAlpha, $radius + 2);
        }
        $hovered = $rt?->hovered($n->id) ?? false;
        if ($n->on) {
            $list->rect($n->x, $y, $box, $box, $hovered ? $t->accentHover : $t->accent, 1.0, $radius);
            if ($n->kind === Node::RADIO) {
                $list->rect($n->x + $box * 0.3, $y + $box * 0.3, $box * 0.4, $box * 0.4, $t->accentText, 1.0, $box * 0.2);
            } else {
                [$cw, $ch] = $text->measure('✓', $n->fontSize, Font::BOLD);
                $text->paint($list, '✓', $n->fontSize, $n->x + ($box - $cw) / 2, $y + ($box - $ch) / 2, $t->accentText, 1.0, Font::BOLD);
            }
        } else {
            $list->rect($n->x, $y, $box, $box, $t->border, 1.0, $radius);
            $list->rect($n->x + 1, $y + 1, $box - 2, $box - 2, $hovered ? $t->surfaceHover : $t->surface, 1.0, $radius - 1);
        }
        [, $lh] = $text->measure($n->text, $n->fontSize);
        $text->paint($list, $n->text, $n->fontSize, $n->x + $box + $t->space(2), $n->y + ($n->h - $lh) / 2, $n->fg);
    }

    /**
     * A slider: the track, the part up to the value in the accent, and the
     * knob, a capsule that sits on a soft shadow and, while held, grows
     * into a lens the track shows through, rimmed and lit inside, as
     * AppKit's does (mygo's paintKnob).
     */
    private static function paintSlider(Node $n, DisplayList $list, Theme $t): void
    {
        $kw = $t->space(5); $kh = $t->space(4);
        $trackH = $t->space(1);
        $cy = $n->y + $n->h / 2;
        $x0 = $n->x + $kw / 2; $x1 = $n->x + $n->w - $kw / 2;
        $kx = $x0 + ($x1 - $x0) * $n->fraction;
        $list->rect($x0, $cy - $trackH / 2, $x1 - $x0, $trackH, $t->border, 1.0, $trackH / 2);
        $list->rect($x0, $cy - $trackH / 2, $kx - $x0, $trackH, $t->accent, 1.0, $trackH / 2);
        $g = $n->held ? 1.35 : 1.0;
        $w = $kw * $g; $h = $kh * $g; $x = $kx - $w / 2; $y = $cy - $h / 2; $r = $h / 2;
        [$face, $rim] = $t->dark ? [0xe0e1e1, 0x000000] : [0xffffff, 0x000000];
        if ($n->held) {
            $list->shadow($x, $y, $w, $h, 0x000000, $t->dark ? 0.3 : 0.14, $r, 14, 0, 8);
            $list->ring($x, $y, $w, $h, $rim, $t->dark ? 0.7 : 0.22, $r, 1.0);                  // the lens's rim,
            $list->ring($x + 1, $y + 1, $w - 2, $h - 2, 0xffffff, 0.25, $r - 1, 1.0);          // lit on the inside
        } else {
            $list->shadow($x, $y, $w, $h, 0x000000, 0.08, $r, 1, 0, 0.5);   // a tight shadow edges the knob,
            $list->shadow($x, $y, $w, $h, 0x000000, 0.10, $r, 7, 0, 1.5);   // a soft one lifts it
            $list->rect($x, $y, $w, $h, $face, 1.0, $r);
        }
        if ($n->focused) {
            $list->ring($x - 2, $y - 2, $w + 4, $h + 4, $t->focus, $t->focusAlpha, $r + 2, 2.0);
        }
    }

    /** Rich text: its wrapped lines, each on one baseline, with the spans' backgrounds, underlines and strikes. */
    private static function paintRich(Node $n, DisplayList $list, Text $text): void
    {
        $y = $n->y;
        foreach ($n->rich->lines as $line) {
            $baseline = $y + $line['ascent'];
            foreach ($line['pieces'] as [$sp, $piece, $px, $w]) {
                $size = $sp->size ?? $n->fontSize;
                $x = $n->x + $px;
                $top = $baseline - $text->ascent($size, $sp->style);
                if ($sp->background !== null) {
                    [, $h] = $text->measure('X', $size, $sp->style);
                    $list->rect($x - 2, $top, $w + 4, $h, $sp->background, 1.0, 3.0);
                }
                $color = $sp->color ?? $n->fg;
                if (trim($piece) !== '') {
                    $text->paint($list, $piece, $size, $x, $top, $color, 1.0, $sp->style);
                }
                if ($sp->underline) {
                    $list->rect($x, $baseline + 1, $w, 1.0, $color);
                }
                if ($sp->strikethrough) {
                    $list->rect($x, $baseline - $size * 0.3, $w, 1.0, $color);
                }
            }
            $y += $line['h'];
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
        // Never scroll past the text; bring the caret into view when it moved (not while the wheel scrolls away from it).
        $range = max(0.0, $layout->height() - $innerH);
        $s->scrollY = max(0.0, min($s->scrollY, $range));
        $caret = [strlen($display), $s->displayCaret()];
        if ($n->focused && $caret !== $s->caretSeen) {
            $s->caretSeen = $caret;
            [, $cy] = $layout->caret($text, $s->displayCaret());
            if ($cy < $s->scrollY) { $s->scrollY = $cy; }
            if ($cy + $lh > $s->scrollY + $innerH) { $s->scrollY = $cy + $lh - $innerH; }
        }
        $s->scrollFraction = $range > 0 ? $s->scrollY / $range : 0.0;
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
