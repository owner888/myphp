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
        if ($n->kind === Node::SWITCH) {
            self::paintSwitch($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::SPINNER) {
            self::paintSpinner($n, $list, $t);
            return;
        }
        if ($n->kind === Node::SLIDER) {
            if ($n->draw !== null) { ($n->draw)($list, $n, $t); } else { self::paintSlider($n, $list, $t); } // a draw of its own (a color picker's channel) paints the whole slider
            return;
        }
        if ($n->kind === Node::PROGRESS) { // a bar as thick as the node was made (a column stretches the node), in the accent or the color given (a meter's level)
            $h = $n->fh; $y = $n->y + ($n->h - $h) / 2; $r = $h / 2;
            $list->rect($n->x, $y, $n->w, $h, $t->border, 1.0, $r);
            if ($n->fraction >= 0) {
                $list->rect($n->x, $y, $n->w * min(1.0, $n->fraction), $h, $n->color ?? $t->accent, 1.0, $r);
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
            $u = $s['u']; $v = $s['v']; $sw = $s['w']; $sh = $s['h'];
            if ($n->cover && $n->w > 0 && $n->h > 0) { // the part of the image in the box's proportions, centered
                $k = min($sw / $n->w, $sh / $n->h); $cw = (int)round($n->w * $k); $ch = (int)round($n->h * $k);
                $u += intdiv($sw - $cw, 2); $v += intdiv($sh - $ch, 2); $sw = $cw; $sh = $ch;
            }
            $list->image($n->x, $n->y, $n->w, $n->h, $u, $v, $sw, $sh, $s['page'], $n->alpha, $n->radius);
            return;
        }
        if ($n->focusVisible && $n->color !== null) { // a ring around a button or other faced node
            self::focusRing($list, $n->x, $n->y, $n->w, $n->h, match ($n->kind) { Node::BUTTON, Node::SELECT => $t->radius, default => $n->radius }, $t);
        }
        if ($n->material instanceof Blur) {
            $b = $n->material;
            [$top, $bottom] = $b->fade ? ($b->fromBottom ? [0.0, 1.0] : [1.0, 0.0]) : [1.0, 1.0];
            $list->blur($n->x, $n->y, $n->w, $n->h, $n->radius, $b->radius * $text->scale(), 0, 0.0, $top, $bottom);
        } elseif ($n->material instanceof ScrollEdge) {
            self::paintScrollEdge($n, $list, $text, $t);
        } elseif ($n->material !== null) {
            $g = $n->material;
            $x = $n->x; $y = $n->y; $w = $n->w; $h = $n->h; $r = $n->radius;
            if ($g->interactive && $rt !== null && $rt->input->mouseDown && $rt->hovered($n->id)) {
                // Pressed: grown as macOS's, by about 1.1 points left and right and 0.45 above and below.
                $x -= 1.1; $w += 2.2; $y -= 0.45; $h += 0.9; $r += $r > 0 ? 0.45 : 0;
            }
            $scale = $text->scale();
            $m = min($w, $h);
            $sigma = $g->style === Glass::CLEAR ? $scale : min(max($m * 0.035, 1), 10) * $scale;
            $list->glass($x, $y, $w, $h, $r, $g->style, $g->tint ?? 0, $g->tint === null ? 0.0 : $g->tintAlpha * 0.88, $sigma);
        } elseif ($n->color !== null) {
            $radius = match ($n->kind) { Node::BUTTON, Node::SELECT => $t->radius, Node::BOX => self::BOX_RADIUS, default => $n->radius };
            if ($n->bordered) { // a bordered face: a plain button, a select, a field's box
                $bw = $n->borderWidth;
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->borderColor ?? $t->border, 1.0, $radius);
                $list->rect($n->x + $bw, $n->y + $bw, $n->w - 2 * $bw, $n->h - 2 * $bw, $n->color, 1.0, $radius - $bw);
            } else {
                if ($n->shadow !== null) {
                    $list->shadow($n->x, $n->y, $n->w, $n->h, 0x000000, $n->shadow[2], $radius, $n->shadow[1], 0, $n->shadow[0]);
                }
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, $n->alpha, $radius);
            }
        }
        if ($n->selected) {
            $list->rect($n->x, $n->y, $n->w, $n->h, $t->selection, $t->selectionAlpha);
        }
        if ($n->text !== null) {
            $x = $n->x; $y = $n->y;
            if ($n->kind === Node::BUTTON || $n->kind === Node::SELECT) { // the label centered in the padding; a select's at the start, its chevron at the end (mygo's Justify(SpaceBetween))
                [$tw, $th] = $text->measure($n->text, $n->fontSize);
                $cw = $n->chevron ? $t->space(2.5) + $t->space(1.5) : 0;
                $x += $n->kind === Node::SELECT ? $t->space(3.5) : ($n->w - $tw - $cw) / 2; $y += ($n->h - $th) / 2;
                if ($n->chevron) {
                    self::chevron($list, $n->kind === Node::SELECT ? $n->x + $n->w - $t->space(3.5) - $t->space(2.5) : $x + $tw + $t->space(1.5), $n->y + ($n->h - $t->space(2.5)) / 2, $t->space(2.5), $t->textMuted);
                }
            }
            $shown = $n->text;
            if ($n->single && $n->kind === Node::TEXT) {
                [$tw] = $text->measure($shown, $n->fontSize, $n->style);
                if ($tw > $n->w + 0.5) { // cut to the room, ending in an ellipsis
                    [$ew] = $text->measure('…', $n->fontSize, $n->style);
                    $shown = substr($shown, 0, $text->indexForX($shown, $n->fontSize, max(0.0, $n->w - $ew))) . '…';
                }
            }
            $text->paint($list, $shown, $n->fontSize, $x, $y, $n->fg, $n->alpha, $n->style);
            if ($n->underline) {
                $list->rect($x, $y + $text->ascent($n->fontSize, $n->style) + 1, $n->w, 1.0, $n->fg);
            }
        }
        if ($n->draw !== null) {
            ($n->draw)($list, $n, $t); // over the node's own face, under its children
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        }
        foreach ($n->kids as $k) {
            self::paint($k, $list, $text, $t, $rt);
        }
        if ($n->focusVisible && $n->color === null) { // a list or other background-less node: the ring just inside its edge, over its children (outside, a list filling the window would lose it)
            $list->ring($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $t->focus, $t->focusAlpha, max(0.0, $n->radius - 1), 2.0);
        }
        if ($n->scroll !== null) {
            $list->popClip();
            // The thumbs show while the pointer is over the container or drags one.
            if ($rt !== null && (($dragging = $rt->dragging($n->scroll)) || $rt->hovered($n->id))) {
                $r = [$n->x, $n->y, $n->w, $n->h];
                $grow = $dragging ? 2.0 : 0.0; // the thumb being dragged thickens, as mygo's
                $thumb = $n->scrollY ? Scrollbar::thumb($r, $n->scroll->extent(), $t->scrollbarWidth) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0] - $grow, $thumb[1], $thumb[2] + $grow, $thumb[3], $t->scrollbar, $t->scrollbarAlpha, ($thumb[2] + $grow) / 2);
                }
                $thumb = $n->scrollX ? Scrollbar::thumb($r, $n->scroll->extentX(), $t->scrollbarWidth, true) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0], $thumb[1] - $grow, $thumb[2], $thumb[3] + $grow, $t->scrollbar, $t->scrollbarAlpha, ($thumb[3] + $grow) / 2);
                }
            }
        }
    }

    /**
     * macOS's scroll edge over content under a bar: soft, the background
     * fading from 85% at the bar's edge to nothing; hard, the content
     * blurred by 6.4 points and saturated under 82% of the background
     * (toned as AppKit's), with a hairline along the other edge.
     */
    private static function paintScrollEdge(Node $n, DisplayList $list, Text $text, Theme $t): void
    {
        $e = $n->material;
        $bg = $e->background ?? $t->background;
        if (!$e->hard) {
            [$a0, $a1] = $e->bottom ? [0.0, 0.85] : [0.85, 0.0];
            $list->gradient($n->x, $n->y, $n->w, $n->h, $bg, $a0, $a1, $n->radius);
            return;
        }
        // The background toned: desaturated to 0.947 and lightened by 0.03, as AppKit's background replay.
        $r = (($bg >> 16) & 255) / 255; $g = (($bg >> 8) & 255) / 255; $b = ($bg & 255) / 255;
        $l = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        $tone = fn(float $c) => (int)round(max(0.0, min(1.0, $l + ($c - $l) * 0.947 + 0.03)) * 255);
        $toned = ($tone($r) << 16) | ($tone($g) << 8) | $tone($b);
        $list->blur($n->x, $n->y, $n->w, $n->h, $n->radius, 6.4 * $text->scale(), $toned, 0.8245, 1.0, 1.0, true);
        // The hairline along the other edge, outside it: black at 10% in light mode, white at 7% in dark.
        $y = $e->bottom ? $n->y - 0.5 : $n->y + $n->h;
        $list->rect($n->x, $y, $n->w, 0.5, $t->dark ? 0xffffff : 0x000000, $t->dark ? 0.07 : 0.1);
    }

    /** The tip the pointer rests on, if any: a small surface below and right of the pointer, kept inside the root. */
    public static function tooltip(Node $root, DisplayList $list, Text $text, Theme $t, Runtime $rt): void
    {
        if ($rt->tip === null) {
            return;
        }
        [$tip, $ax, $ay, $aw, $ah] = $rt->tip; // the anchor's rect
        $size = $t->fontSize - 1;
        [$tw, $th] = $text->measure($tip, $size);
        $px = $t->space(2); $py = $t->space(1.25); $radius = $t->space(1.25); $apart = $t->space(1.5);
        $w = min($tw + 2 * $px, $t->space(80)); $h = $th + 2 * $py;
        $x = max($root->x, min($ax + ($aw - $w) / 2, $root->x + $root->w - $w)); // centered over the anchor, kept in the window
        $y = $ay - $apart - $h; // above it; below when there is no room
        if ($y < $root->y) {
            $y = $ay + $ah + $apart;
        }
        $list->shadow($x, $y, $w, $h, 0x000000, 0.2, $radius, 8, 0, 2);
        $list->rect($x, $y, $w, $h, $t->text, 1.0, $radius);
        $list->pushClip($x, $y, $w, $h);
        $text->paint($list, $tip, $size, $x + $px, $y + $py, $t->background);
        $list->popClip();
    }

    /** Draws the shapes of $svg fitted in the rect (contain, centered), as mygo's Painter.Icon: each path scaled from the viewBox, filled or stroked at its width scaled, from a mask cached by the shape. */
    public static function icon(DisplayList $list, SVG $svg, float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0): void
    {
        $k = min($w / $svg->w, $h / $svg->h);
        $ox = $x + ($w - $svg->w * $k) / 2 - $svg->x * $k; $oy = $y + ($h - $svg->h * $k) / 2 - $svg->y * $k;
        foreach ($svg->shapes as [$path, $width]) {
            $p = new Path;
            foreach ($path->cmds as $c) {
                $p->cmds[] = [$c[0], $ox + $c[1] * $k, $oy + $c[2] * $k, $ox + $c[3] * $k, $oy + $c[4] * $k, $ox + $c[5] * $k, $oy + $c[6] * $k];
            }
            $list->path($p, $rgb, $alpha, $width * $k);
        }
    }

    /** mygo's inputBorder: the accent while focused, the border a quarter toward the text under the pointer, the border otherwise. */
    public static function inputBorder(Node $n, Theme $t, ?Runtime $rt): int
    {
        return $n->focused ? $t->accent : (($rt?->hovered($n->id) ?? false) ? Theme::mix($t->border, $t->text, 0.25) : $t->border);
    }

    /** mygo's chevron: a down-pointing angle in a $d-square box at (x, y), stroked 1.5. */
    public static function chevron(DisplayList $list, float $x, float $y, float $d, int $color): void
    {
        $list->line($x + $d * 0.1, $y + $d * 0.3, $x + $d * 0.5, $y + $d * 0.7, $color, 1.0, 1.5);
        $list->line($x + $d * 0.5, $y + $d * 0.7, $x + $d * 0.9, $y + $d * 0.3, $color, 1.0, 1.5);
    }

    /** mygo's Painter.FocusRing: a 2-point ring of the focus color a point outside the rect. */
    public static function focusRing(DisplayList $list, float $x, float $y, float $w, float $h, float $radius, Theme $t): void
    {
        $list->ring($x - 3, $y - 3, $w + 6, $h + 6, $t->focus, $t->focusAlpha, $radius + 3, 2.0);
    }

    /**
     * A checkbox or radio with its label, as mygo's: a Space(4) box
     * (radius Space(1)) or disc, the background with a border a quarter
     * toward the text — the accent under the pointer — or the accent
     * with a check stroked Space(0.5) in the accent's text (a dot Space(1.5)
     * for a radio); the label Space(2) after.
     */
    private static function paintToggle(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt): void
    {
        $box = $t->space(4);
        $x = $n->x; $y = $n->y + ($n->h - $box) / 2;
        $radius = $n->kind === Node::RADIO ? $t->space(2) : $t->space(1);
        $hovered = $rt?->hovered($n->id) ?? false;
        if ($n->on || $n->mixed) {
            $list->rect($x, $y, $box, $box, $t->accent, 1.0, $radius);
            if ($n->mixed) { // a bar: some of a group checked
                $list->rect($x + $box * 0.25, $y + $box / 2 - 1, $box * 0.5, 2, $t->accentText, 1.0, 1.0);
            } elseif ($n->kind === Node::RADIO) {
                $d = $t->space(1.5);
                $list->rect($x + ($box - $d) / 2, $y + ($box - $d) / 2, $d, $d, $t->accentText, 1.0, $d / 2);
            } else { // mygo's checkPath
                $w = $t->space(0.5);
                $list->line($x + $box * 0.22, $y + $box * 0.52, $x + $box * 0.42, $y + $box * 0.71, $t->accentText, 1.0, $w);
                $list->line($x + $box * 0.42, $y + $box * 0.71, $x + $box * 0.78, $y + $box * 0.31, $t->accentText, 1.0, $w);
            }
        } else {
            $list->rect($x, $y, $box, $box, $hovered ? $t->accent : Theme::mix($t->border, $t->text, 0.25), 1.0, $radius);
            $list->rect($x + 1, $y + 1, $box - 2, $box - 2, $t->background, 1.0, $radius - 1);
        }
        if ($n->focusVisible) {
            self::focusRing($list, $x, $y, $box, $box, $radius, $t);
        }
        [, $lh] = $text->measure($n->text, $n->fontSize);
        $text->paint($list, $n->text, $n->fontSize, $x + $box + $t->space(2), $n->y + ($n->h - $lh) / 2, $n->fg);
    }

    /**
     * A spinner, as mygo's: twelve spokes, a twelfth of a turn on every
     * twelfth of 0.9 s, fading behind the one leading. A spoke is a run
     * of overlapping dots, the list having no rotated quads.
     */
    private static function paintSpinner(Node $n, DisplayList $list, Theme $t): void
    {
        $spokes = 12; $turn = 0.9;
        $lead = (int)(fmod(hrtime(true) / 1e9, $turn) / $turn * $spokes);
        $cx = $n->x + $n->w / 2; $cy = $n->y + $n->h / 2;
        $in = $n->w * 0.22; $out = $n->w * 0.46; $w = max(1.5, $n->w * 0.09);
        $dots = 4;
        for ($i = 0; $i < $spokes; $i++) {
            $a = $i / $spokes * 2 * M_PI - M_PI / 2;
            $age = ($lead - $i + $spokes) % $spokes;
            $alpha = 1 - $age / $spokes * 0.85;
            for ($d = 0; $d < $dots; $d++) {
                $r = $in + ($out - $in) * $d / ($dots - 1);
                $list->rect($cx + cos($a) * $r - $w / 2, $cy + sin($a) * $r - $w / 2, $w, $w, $t->textMuted, $alpha, $w / 2);
            }
        }
    }

    /**
     * A switch, as mygo's: Space(9) by Space(5), radius Space(2.5), the
     * border a sixth toward the text when off and the accent when on; the
     * knob white, inset Space(0.5), on a shadow (0, 1, 3, 0.25); the label
     * Space(2) after.
     */
    private static function paintSwitch(Node $n, DisplayList $list, Text $text, Theme $t, ?Runtime $rt): void
    {
        $tw = $t->space(9); $th = $t->space(5); $in = $t->space(0.5); $d = $th - 2 * $in;
        $y = $n->y + ($n->h - $th) / 2;
        $list->rect($n->x, $y, $tw, $th, $n->on ? $t->accent : Theme::mix($t->border, $t->text, 0.15), 1.0, $th / 2);
        $kx = $n->x + $in + ($n->on ? $tw - $th : 0);
        $list->shadow($kx, $y + $in, $d, $d, 0x000000, 0.25, $d / 2, 3, 0, 1);
        $list->rect($kx, $y + $in, $d, $d, 0xffffff, 1.0, $d / 2);
        if ($n->focusVisible) {
            self::focusRing($list, $n->x, $y, $tw, $th, $th / 2, $t);
        }
        [, $lh] = $text->measure($n->text, $n->fontSize);
        $text->paint($list, $n->text, $n->fontSize, $n->x + $tw + $t->space(2), $n->y + ($n->h - $lh) / 2, $n->fg);
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
        $trackH = $t->space(1.5); // as thick as Progress
        $cy = $n->y + $t->space(2.5);
        $x0 = $n->x + $kw / 2; $x1 = $n->x + $n->w - $kw / 2;
        $kx = $x0 + ($x1 - $x0) * $n->fraction;
        $list->rect($x0, $cy - $trackH / 2, $x1 - $x0, $trackH, $t->border, 1.0, $trackH / 2);
        $list->rect($x0, $cy - $trackH / 2, $kx - $x0, $trackH, $t->accent, 1.0, $trackH / 2);
        self::knob($list, $t, $kx, $cy, $n->held, $n->focusVisible);
    }

    /** A slider's knob at (x, cy), mygo's paintKnob: a Space(5) by Space(4) capsule, white, that grows into a lens of glass while held. */
    public static function knob(DisplayList $list, Theme $t, float $kx, float $cy, bool $held, bool $focus): void
    {
        $kw = $t->space(5); $kh = $t->space(4);
        $g = $held ? 1.35 : 1.0;
        $w = $kw * $g; $h = $kh * $g; $x = $kx - $w / 2; $y = $cy - $h / 2; $r = $h / 2;
        [$face, $rim] = $t->dark ? [0xe0e1e1, 0x000000] : [0xffffff, 0x000000];
        if ($held) {
            $list->shadow($x, $y, $w, $h, 0x000000, $t->dark ? 0.3 : 0.14, $r, 14, 0, 8);
            $list->ring($x, $y, $w, $h, $rim, $t->dark ? 0.7 : 0.22, $r, 1.0);                  // the lens's rim,
            $list->ring($x + 1, $y + 1, $w - 2, $h - 2, 0xffffff, 0.25, $r - 1, 1.0);          // lit on the inside
        } else {
            $list->shadow($x, $y, $w, $h, 0x000000, 0.08, $r, 1, 0, 0.5);   // a tight shadow edges the knob,
            $list->shadow($x, $y, $w, $h, 0x000000, 0.10, $r, 7, 0, 1.5);   // a soft one lifts it
            $list->rect($x, $y, $w, $h, $face, 1.0, $r);
        }
        if ($focus) {
            self::focusRing($list, $x, $y, $w, $h, $r, $t);
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
        $inset = Runtime::FIELD_INSET + $n->insetL;
        if ($n->bordered) { // its own box; a combobox's input sits in a field box drawn around it
            $list->rect($n->x, $n->y, $n->w, $n->h, self::inputBorder($n, $t, $rt), 1.0, $t->radius);
            $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color ?? $t->surface, 1.0, $t->radius - 1);
        }
        $display = $s->display();
        [$tw, $lh] = $text->measure($display === '' ? 'X' : $display, $n->fontSize);
        $ty = $n->y + ($n->h - $lh) / 2;
        $inner = $n->w - $inset - Runtime::FIELD_INSET - $n->insetR;
        // Keep the caret in view when the text is wider than the field.
        $caretX = $text->xForIndex($display, $n->fontSize, $s->displayCaret());
        $shift = max(0.0, $caretX - $inner);
        $tx = $n->x + $inset - $shift;
        $list->pushClip($n->x + 2 + $n->insetL, $n->y, $n->w - 4 - $n->insetL - $n->insetR, $n->h);
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
        $inset = Runtime::FIELD_INSET; $insetY = Runtime::FIELD_INSET_Y;
        $list->rect($n->x, $n->y, $n->w, $n->h, self::inputBorder($n, $t, $rt), 1.0, $t->radius);
        $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color ?? $t->surface, 1.0, $t->radius - 1);
        $display = $s->display();
        $innerW = $n->w - 2 * $inset; $innerH = $n->h - 2 * $insetY;
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
        $tx = $n->x + $inset; $ty = $n->y + $insetY - $s->scrollY;
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
