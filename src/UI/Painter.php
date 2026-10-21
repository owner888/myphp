<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Turns a laid-out tree into display-list quads: fills, labels, clips. */
final class Painter
{

    /**
     * Paints $n and its children through the node's world (its transform
     * after its ancestors', as Runtime::record worked it out) at its
     * opacity times its ancestors', as mygo's Painter.element: a node turned
     * away with backfaceHidden, or transparent, paints nothing.
     */
    public static function paint(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt = null): void
    {
        if ($n->backface && $n->transform->facesAway()) {
            return;
        }
        $world = $list->world(); $opacity = $list->opacity();
        if ($n->opacity < 1 || $n->poseFade > 0) {
            $o = $opacity * $n->opacity * (1 - $n->poseFade);
            if ($o <= 0.001) {
                return;
            }
            $list->setOpacity($o);
        }
        $list->setWorld($n->world);
        self::node($n, $list, $text, $t, $rt);
        $list->setWorld($world);
        $list->setOpacity($opacity);
    }

    private static function node(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
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
            self::paintRich($n, $list, $text, $t, $rt);
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
            if ($n->draw !== null) { ($n->draw)($list, $n, $t, $text); } else { self::paintSlider($n, $list, $t); } // a draw of its own (a color picker's channel) paints the whole slider
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
            if ($n->content === null || $rt === null || !self::contentImage($n, $list, $text, $t, $rt)) {
                self::image($n, $list, $text, $t);
            }
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
            $radius = match ($n->kind) { Node::BUTTON, Node::SELECT => $t->radius, default => $n->radius };
            if ($n->bordered) { // a bordered face: a plain button, a select, a field's box
                $bw = $n->borderWidth;
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->borderColor ?? $t->border, 1.0, $radius);
                $list->rect($n->x + $bw, $n->y + $bw, $n->w - 2 * $bw, $n->h - 2 * $bw, $n->color, 1.0, $radius - $bw);
            } else {
                if ($n->shadow !== null) {
                    $list->shadow($n->x, $n->y, $n->w, $n->h, 0x000000, $n->shadow[2], $radius, $n->shadow[1], 0, $n->shadow[0]);
                }
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, $n->alpha * $n->bgAlpha, $radius);
            }
        }
        [$bt, $br, $bb, $bl] = $n->borders;
        if ($bt > 0 || $br > 0 || $bb > 0 || $bl > 0) { // borders inside the edges (mygo's BorderWidth): around the corners where they are one width, else a side each
            $bc = $n->borderColor ?? $t->border;
            if ($bt === $br && $br === $bb && $bb === $bl) {
                $list->ring($n->x, $n->y, $n->w, $n->h, $bc, $n->alpha, $n->radius, $bt);
            } else {
                if ($bt > 0) { $list->rect($n->x, $n->y, $n->w, $bt, $bc, $n->alpha); }
                if ($bb > 0) { $list->rect($n->x, $n->y + $n->h - $bb, $n->w, $bb, $bc, $n->alpha); }
                if ($bl > 0) { $list->rect($n->x, $n->y + $bt, $bl, $n->h - $bt - $bb, $bc, $n->alpha); }
                if ($br > 0) { $list->rect($n->x + $n->w - $br, $n->y + $bt, $br, $n->h - $bt - $bb, $bc, $n->alpha); }
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
            if ($n->content === null || $rt === null || $n->kind !== Node::TEXT || !self::contentText($n, $list, $text, $shown, $x, $y, $rt)) {
                $text->paint($list, $shown, $n->fontSize, $x, $y, $n->fg, $n->alpha, $n->style);
            }
            if ($n->underline) {
                $list->rect($x, $y + $text->ascent($n->fontSize, $n->style) + 1, $n->w, 1.0, $n->fg);
            }
        }
        if ($n->draw !== null) {
            if ($n->content === null || $rt === null || $n->svg === null || !self::contentIcon($n, $list, $text, $t, $rt)) {
                ($n->draw)($list, $n, $t, $text); // over the node's own face, under its children, with the text painter for what needs the atlas
            }
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        } elseif ($n->clip !== 0) { // an axis not clipped reaches as far as the clip around it does
            $list->pushClip(($n->clip & Node::CLIP_X) !== 0 ? $n->x : -1e6, ($n->clip & Node::CLIP_Y) !== 0 ? $n->y : -1e6, ($n->clip & Node::CLIP_X) !== 0 ? $n->w : 2e6, ($n->clip & Node::CLIP_Y) !== 0 ? $n->h : 2e6);
        }
        foreach ($n->ghosts as $g) { // children leaving go under the others
            self::paint($g, $list, $text, $t, $rt);
        }
        foreach ($n->kids as $k) {
            self::paint($k, $list, $text, $t, $rt);
        }
        if ($n->drawOver !== null) {
            ($n->drawOver)($list, $n, $t); // over the children, inside a scrolling container's clip (mygo's DrawOver)
        }
        if ($n->clip !== 0 && $n->scroll === null) {
            $list->popClip();
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
    private static function paintScrollEdge(Node $n, DisplayList $list, TextPainter $text, Theme $t): void
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

    /** Paints a copy of the node dragging its value under the pointer, above the rest, at three quarters' opacity (mygo's paintDrag). */
    public static function dragCopy(DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): void
    {
        $d = $rt->valueDrag;
        if ($d === null || $d['canceled'] || $d['native'] || ($e = $d['elem']) === null) { // the system shows a native drag
            return;
        }
        [$px, $py] = $e->world->point($e->x + $d['offX'], $e->y + $d['offY']);
        $shift = Affine::translation($rt->input->mouseX - $px, $rt->input->mouseY - $py);
        $saved = [];
        $move = function (Node $n) use (&$move, &$saved, $shift) {
            $saved[] = [$n, $n->world];
            $n->world = $shift->mul($n->world);
            foreach ($n->kids as $k) { $move($k); }
        };
        $move($e);
        $opacity = $list->opacity();
        $list->setOpacity(0.75);
        self::paint($e, $list, $text, $t, $rt);
        $list->setOpacity($opacity);
        foreach ($saved as [$n, $w]) { $n->world = $w; }
    }

    /** The tip the pointer rests on, if any: a small surface below and right of the pointer, kept inside the root. */
    public static function tooltip(Node $root, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): void
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

    /** Draws the shapes of $svg fitted in the rect (contain, centered) in $rgb, turned by $rotate degrees around its center, as mygo's Painter.Icon: from a mask the text system caches in its atlas, so the GPU draws the icon in any color without drawing its shapes again. */
    public static function icon(DisplayList $list, TextPainter $text, SVG $svg, float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0, float $rotate = 0.0): void
    {
        if ($svg->w <= 0 || $svg->h <= 0 || $alpha <= 0) { return; }
        $k = min($w / $svg->w, $h / $svg->h);
        $q = $text->iconQuad($svg, $x + ($w - $svg->w * $k) / 2, $y + ($h - $svg->h * $k) / 2, $svg->w * $k, $svg->h * $k, $rotate);
        if ($q !== null && !$list->clipped($q[0], $q[1], $q[2], $q[3])) { $list->glyph($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, $q[4], $q[5]); }
    }

    /** Draws an image, or an SVG in its own colors, in the node's box as its fit says (mygo's Painter.image: fitIn, drawBitmap, drawSVG), rounded, faded, gray if asked. */
    private static function image(Node $n, DisplayList $list, TextPainter $text, Theme $t): void
    {
        $scale = $text->scale();
        // The picture's own size in points: a bitmap's pixels as the image laid it out, an SVG's size.
        [$iw, $ih] = $n->svg !== null ? [$n->svg->w, $n->svg->h] : [$n->image->w / $scale, $n->image->h / $scale];
        if ($iw <= 0 || $ih <= 0) { return; }
        [$dst, $frac, $fw, $fh] = self::fitIn($n->x, $n->y, $n->w, $n->h, $iw, $ih, $n->fit);
        if ($dst[2] <= 0 || $dst[3] <= 0) { return; }
        if ($n->svg !== null) {
            $s = $text->svgSlot($n->svg, max(1, (int)round($fw * $scale)), max(1, (int)round($fh * $scale)), $n->fg !== 0 ? $n->fg : $t->text);
        } else { // far smaller than its pixels, a level of it, as smooth (mygo's drawBitmap)
            $shrink = min($frac[2] * $n->image->w / ($dst[2] * $scale), $frac[3] * $n->image->h / ($dst[3] * $scale));
            $s = $text->imageSlot($n->image->smaller($shrink));
        }
        $sw = $s['w']; $sh = $s['h']; // the part of the slot that shows: the fractions of the picture within the box
        $u = $s['u'] + (int)round($frac[0] * $sw); $v = $s['v'] + (int)round($frac[1] * $sh);
        $cw = max(1, (int)round($frac[2] * $sw)); $ch = max(1, (int)round($frac[3] * $sh));
        $list->image($dst[0], $dst[1], $dst[2], $dst[3], $u, $v, $cw, $ch, $s['page'], $n->alpha, $n->radius, $n->gray);
    }

    /**
     * Where a picture $w × $h points goes in the box as $fit says, and which
     * part of it shows there, as fractions of its size: [the rect shown,
     * [x, y, w, h] fractions, the picture's full width and height], mygo's fitIn.
     */
    public static function fitIn(float $bx, float $by, float $bw, float $bh, float $w, float $h, int $fit): array
    {
        if ($fit === Node::FILL_BOX) { return [[$bx, $by, $bw, $bh], [0.0, 0.0, 1.0, 1.0], $bw, $bh]; }
        $k = match ($fit) { Node::CONTAIN => min($bw / $w, $bh / $h), Node::COVER => max($bw / $w, $bh / $h), Node::SCALE_DOWN => min($bw / $w, $bh / $h, 1.0), default => 1.0 };
        $dw = $w * $k; $dh = $h * $k;
        $dx = $bx + ($bw - $dw) / 2; $dy = $by + ($bh - $dh) / 2;
        // Only what falls in the box shows.
        $x0 = max($dx, $bx); $y0 = max($dy, $by); $x1 = min($dx + $dw, $bx + $bw); $y1 = min($dy + $dh, $by + $bh);
        if ($x1 <= $x0 || $y1 <= $y0) { return [[$x0, $y0, 0.0, 0.0], [0.0, 0.0, 0.0, 0.0], $dw, $dh]; }
        return [[$x0, $y0, $x1 - $x0, $y1 - $y0], [($x0 - $dx) / $dw, ($y0 - $dy) / $dh, ($x1 - $x0) / $dw, ($y1 - $y0) / $dh], $dw, $dh];
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
    private static function paintToggle(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
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
     * border a sixth toward the text when off and the accent when on,
     * mixed as the knob moves ($fraction, from 0 off to 1 on); the knob
     * white, inset Space(0.5), on a shadow (0, 1, 3, 0.25); the label
     * Space(2) after.
     */
    private static function paintSwitch(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $tw = $t->space(9); $th = $t->space(5); $in = $t->space(0.5); $d = $th - 2 * $in;
        $y = $n->y + ($n->h - $th) / 2;
        $list->rect($n->x, $y, $tw, $th, Theme::mix(Theme::mix($t->border, $t->text, 0.15), $t->accent, $n->fraction), 1.0, $th / 2);
        $kx = $n->x + $in + $n->fraction * ($tw - $th);
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
        if ($n->held > 0) { // the lens lightens what is behind it, under the track
            [$x, $y, $w, $h] = self::knobRect($t, $kx, $cy, $n->held);
            $list->rect($x, $y, $w, $h, 0xffffff, 0.1 * $n->held, $h / 2);
        }
        $list->rect($x0, $cy - $trackH / 2, $x1 - $x0, $trackH, $t->border, 1.0, $trackH / 2);
        $list->rect($x0, $cy - $trackH / 2, $kx - $x0, $trackH, $t->accent, 1.0, $trackH / 2);
        self::knob($list, $t, $kx, $cy, $n->held, $n->focusVisible);
    }

    /**
     * Paints the text of $n changing, $shown at ($x, $y), and reports whether
     * it does: no transition otherwise (mygo's contentText). The old fades
     * out as the new fades in; numeric, on one line and with motion, the
     * characters the old and the new share at their start and end stay,
     * the end sliding to where it goes, and those between roll.
     */
    private static function contentText(Node $n, DisplayList $list, TextPainter $text, string $shown, float $x, float $y, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $x, $y, $n->fg);
        if (!$changing || $r->oldText === null) {
            return false;
        }
        $saved = $list->opacity();
        $old = $r->oldText; $size = $n->fontSize; $style = $n->style;
        if (!$n->content->numeric || $r->still || str_contains($old, "\n") || str_contains($shown, "\n")) {
            $list->setOpacity($saved * (1 - $k));
            $text->paint($list, $old, $size, $r->oldX, $r->oldY, $r->oldColor, $n->alpha, $style);
            $list->setOpacity($saved * $k);
            $text->paint($list, $shown, $size, $x, $y, $n->fg, $n->alpha, $style);
            $list->setOpacity($saved);
            return true;
        }
        // The characters the old and the new text share at their start and at their end stay; those between roll.
        $a = $old; $b = $shown;
        $starts = fn(string $s, int $i) => $i >= strlen($s) || (ord($s[$i]) & 0xc0) !== 0x80;
        $pre = 0;
        while ($pre < strlen($a) && $pre < strlen($b) && $a[$pre] === $b[$pre]) { $pre++; }
        while ($pre > 0 && $pre < strlen($b) && !$starts($b, $pre)) { $pre--; }
        $suf = 0;
        while ($suf < strlen($a) - $pre && $suf < strlen($b) - $pre && $a[strlen($a) - 1 - $suf] === $b[strlen($b) - 1 - $suf]) { $suf++; }
        while ($suf > 0 && !$starts($b, strlen($b) - $suf)) { $suf--; }
        $caret = fn(string $s, int $i) => $text->xForIndex($s, $size, $i, $style);
        $oldPre = $caret($a, $pre); $oldSuf = $caret($a, strlen($a) - $suf);
        $curPre = $caret($b, $pre); $curSuf = $caret($b, strlen($b) - $suf);
        [, $h] = $text->measure($b, $size, $style);
        $dir = $n->content->down ? -1.0 : 1.0;
        $far = 1e5;
        $band = function (float $x0, float $x1, \Closure $fn) use ($list, $y, $far): void {
            if ($x1 <= $x0) { return; }
            $list->pushClip($x0, $y - $far, $x1 - $x0, 2 * $far);
            $fn();
            $list->popClip();
        };
        // What stays: the start, and the end, sliding to where it goes.
        if ($pre > 0) {
            $band($x - $far, $x + $curPre, fn() => $text->paint($list, $b, $size, $x, $y, $n->fg, $n->alpha, $style));
        }
        $dx = ($oldSuf - $curSuf) * (1 - $k);
        if ($suf > 0) {
            $band($x + $curSuf + $dx, $x + $far, fn() => $text->paint($list, $b, $size, $x + $dx, $y, $n->fg, $n->alpha, $style));
        }
        // What changes: the old going, the new coming.
        $list->setOpacity($saved * (1 - $k));
        $band($x + $oldPre, $x + $oldSuf, fn() => $text->paint($list, $a, $size, $x, $y - $dir * $h * 0.6 * $k, $r->oldColor, $n->alpha, $style));
        $list->setOpacity($saved * $k);
        $band($x + $curPre, $x + $curSuf + $dx, fn() => $text->paint($list, $b, $size, $x, $y + $dir * $h * 0.6 * (1 - $k), $n->fg, $n->alpha, $style));
        $list->setOpacity($saved);
        return true;
    }

    /** Paints the icon of $n changing, and reports whether it does: the old shrinking away, the new growing in (mygo's contentIcon); without motion they only fade. */
    private static function contentIcon(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $n->x, $n->y, $n->fg);
        if (!$changing) {
            return false;
        }
        $saved = $list->opacity();
        $cx = $n->x + $n->w / 2; $cy = $n->y + $n->h / 2;
        $scaled = function (float $s, \Closure $fn) use ($list, $r, $cx, $cy): void {
            if (!$r->still && $s != 1) { $list->transform(Transform::scaling($s, $s), $cx, $cy, $fn); } else { $fn(); }
        };
        if ($r->oldDraw !== null) {
            $list->setOpacity($saved * (1 - $k));
            $scaled(1 - 0.5 * $k, fn() => ($r->oldDraw)($list, $n, $t, $text)); // the old draw closure, in the node's box
        }
        $list->setOpacity($saved * $k);
        $scaled(0.5 + 0.5 * $k, fn() => ($n->draw)($list, $n, $t, $text));
        $list->setOpacity($saved);
        return true;
    }

    /** Paints the picture of $n changing, and reports whether it does: the old fading out as the new fades in (mygo's contentImage). */
    private static function contentImage(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $n->x, $n->y, $n->fg);
        if (!$changing) {
            return false;
        }
        $saved = $list->opacity();
        if ($r->oldSvg !== null || $r->oldImage !== null) {
            [$svg, $image] = [$n->svg, $n->image];
            $n->svg = $r->oldSvg; $n->image = $r->oldImage;
            $list->setOpacity($saved * (1 - $k));
            self::image($n, $list, $text, $t);
            $n->svg = $svg; $n->image = $image;
        }
        $list->setOpacity($saved * $k);
        self::image($n, $list, $text, $t);
        $list->setOpacity($saved);
        return true;
    }

    /** A slider's knob centered at (x, cy), held from 0 to 1, mygo's knobRect: Space(5) by Space(4), growing by 0.35 as it is held. @return array{0: float, 1: float, 2: float, 3: float} */
    public static function knobRect(Theme $t, float $kx, float $cy, float $held): array
    {
        $g = 1 + 0.35 * $held;
        $w = $t->space(5) * $g; $h = $t->space(4) * $g;
        return [$kx - $w / 2, $cy - $h / 2, $w, $h];
    }

    /** A slider's knob at (x, cy), held from 0 to 1, mygo's paintKnob: a capsule of white, which grows into a lens of glass as it is held, as AppKit's does. */
    public static function knob(DisplayList $list, Theme $t, float $kx, float $cy, float $held, bool $focus): void
    {
        [$x, $y, $w, $h] = self::knobRect($t, $kx, $cy, $held); $r = $h / 2;
        [$face, $rim, $drop] = $t->dark ? [0xe0e1e1, 0.7, 0.3] : [0xffffff, 0.22, 0.14];
        if ($held > 0) {
            $list->shadow($x, $y, $w, $h, 0x000000, $drop * $held, $r, 14, 0, 8);
        }
        if ($held < 1) {
            $list->shadow($x, $y, $w, $h, 0x000000, 0.08 * (1 - $held), $r, 1, 0, 0.5);   // a tight shadow edges the knob,
            $list->shadow($x, $y, $w, $h, 0x000000, 0.10 * (1 - $held), $r, 7, 0, 1.5);   // a soft one lifts it
            $list->rect($x, $y, $w, $h, $face, 1 - $held, $r);
        }
        if ($held > 0) {
            $list->ring($x, $y, $w, $h, 0x000000, $rim * $held, $r, 1.0);                  // the lens's rim,
            $list->ring($x + 1, $y + 1, $w - 2, $h - 2, 0xffffff, 0.25 * $held, $r - 1, 1.0); // lit on the inside
        }
        if ($focus) {
            self::focusRing($list, $x, $y, $w, $h, $r, $t);
        }
    }

    /**
     * Rich text: its layout from its top left, with the background,
     * underline or strikethrough of its text style, and the colors,
     * backgrounds and lines of its spans (mygo's textLayout with a
     * spanPaint); then the focus rings of the text inside it, around their
     * words, whose text it draws (paintInline).
     */
    private static function paintRich(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $l = $n->textLayout();
        $ts = $n->textStyle();
        $x = $n->x; $y = $n->y;
        $color = $ts->color ?? $n->fg;
        $alpha = ($ts->alpha ?? 1.0) * $n->alpha;
        $spans = $n->spans;
        $ends = []; $end = 0;
        foreach ($spans as $sp) { $end += mb_strlen($sp->text, 'UTF-8'); $ends[] = $end; }
        $at = static function (int $r) use ($ends): int { // the span holding rune $r, or -1
            $lo = 0; $hi = count($ends);
            while ($lo < $hi) {
                $m = intdiv($lo + $hi, 2);
                if ($ends[$m] <= $r) { $lo = $m + 1; } else { $hi = $m; }
            }
            return $lo === count($ends) ? -1 : $lo;
        };
        // The runs of the glyphs of a line in one span: the span's index, the glyphs [i, j), and their left and right.
        $runs = static function (\MyPHP\Text\Line $line) use ($at): array {
            $out = []; $gs = $line->glyphs; $c = count($gs);
            for ($i = 0; $i < $c;) {
                $k = $at($gs[$i]->cluster); $x0 = INF; $x1 = -INF;
                for ($j = $i; $j < $c && $at($gs[$j]->cluster) === $k; $j++) { $x0 = min($x0, $gs[$j]->x); $x1 = max($x1, $gs[$j]->x + $gs[$j]->advance); }
                $out[] = [$k, $i, $j, $x0, $x1];
                $i = $j;
            }
            return $out;
        };
        foreach ($l->lines as $line) { // the backgrounds, under the glyphs
            if ($ts->background !== null && $line->width > 0) {
                $list->rect($x + $line->x, $y + $line->y, $line->width, $line->height, $ts->background, $alpha);
            }
            foreach ($runs($line) as [$k, $i, , $x0, $x1]) {
                if ($k < 0 || $spans[$k]->background === null) {
                    continue;
                }
                $sp = $spans[$k]; $ba = ($sp->backgroundAlpha ?? $sp->alpha ?? 1.0) * $n->alpha;
                if ($sp->radius > 0) { // hugging the glyphs, a little wider
                    $size = $line->glyphs[$i]->size;
                    $px = min($size * 0.22, $sp->radius);
                    $list->rect($x + $x0 - $px, $y + $line->baseline - $size * 0.98, $x1 - $x0 + 2 * $px, $size * 1.32, $sp->background, $ba, $sp->radius);
                } else {
                    $list->rect($x + $x0, $y + $line->y, $x1 - $x0, $line->height, $sp->background, $ba);
                }
            }
        }
        if (($sel = $rt?->textSelectionOf($n->id)) !== null) { // over the backgrounds, under the glyphs and lines
            foreach ($l->selection($sel[0], $sel[1]) as [$sx, $sy, $sw, $sh]) {
                $list->rect($x + $sx, $y + $sy, $sw, $sh, $ts->selection ?? $t->selection, $ts->selection !== null ? ($ts->selectionAlpha ?? 1.0) : $t->selectionAlpha);
            }
        }
        $text->paintLayout($list, $l, $x, $y, static function (int $r) use ($at, $spans, $color, $alpha, $n): array {
            $k = $at($r);
            if ($k < 0 || $spans[$k]->color === null && $spans[$k]->alpha === null) {
                return [$color, $alpha];
            }
            return [$spans[$k]->color ?? $color, ($spans[$k]->alpha ?? 1.0) * $n->alpha];
        });
        $sys = $text->textSystem(); $scale = $text->scale();
        $base = [$ts->underline ?? false, $ts->wavy ?? false, $ts->strike ?? false, $ts->decoColor, $ts->decoThick ?? 0.0];
        foreach ($l->lines as $li => $line) {
            foreach ($runs($line) as [$k, $i, $j]) {
                if ($k < 0) {
                    continue;
                }
                $sp = $spans[$k];
                $d = [$sp->underline || $sp->wavyUnderline, $sp->wavyUnderline, $sp->strikethrough, $sp->decorationColor ?? $base[3], $sp->decorationThickness ?? $base[4]];
                if ($d[0] || $d[2]) {
                    self::decorationRun($list, $sys, $l, $li, $i, $j, $x, $y, $scale, $d, $sp->color ?? $color, ($sp->alpha ?? 1.0) * $n->alpha);
                }
            }
            if ($base[0] || $base[2]) {
                self::decorationRun($list, $sys, $l, $li, 0, count($line->glyphs), $x, $y, $scale, $base, $color, $alpha);
            }
        }
        self::paintInline($n, $list, $t);
    }

    /**
     * The underline and strikethrough of $d (underline, wavy, strike,
     * color, thickness) along glyphs [i, j) of line $li of $l from (x, y),
     * where the fonts place them, in $d's color or $rgb (mygo's
     * decorationRun); a wavy underline follows the underline, at least a
     * pixel thick.
     * @param array{0: bool, 1: bool, 2: bool, 3: ?int, 4: float} $d
     */
    private static function decorationRun(DisplayList $list, \MyPHP\Text\System $sys, \MyPHP\Text\Layout $l, int $li, int $i, int $j, float $x, float $y, float $scale, array $d, int $rgb, float $alpha): void
    {
        $rgb = $d[3] ?? $rgb;
        foreach ([\MyPHP\Text\System::UNDERLINE, \MyPHP\Text\System::STRIKETHROUGH] as $kind) {
            if ($kind === \MyPHP\Text\System::UNDERLINE ? !$d[0] : !$d[2]) {
                continue;
            }
            foreach ($sys->decorate($l, $li, $i, $j, $x, $y, $scale, $kind, $d[4]) as [$x0, $x1, $top, $bottom]) {
                if ($x1 <= $x0 || $bottom <= $top) {
                    continue;
                }
                if ($kind === \MyPHP\Text\System::UNDERLINE && $d[1]) {
                    self::wave($list, $x0, $x1, ($top + $bottom) / 2, max($bottom - $top, 1 / $scale), $rgb, $alpha);
                    continue;
                }
                $list->rect($x0, $top, $x1 - $x0, $bottom - $top, $rgb, $alpha);
            }
        }
    }

    /** A wave from x0 to x1 around y, $thick points wide, one and a half times as high, as spell checkers underline words (mygo's wave). */
    private static function wave(DisplayList $list, float $x0, float $x1, float $y, float $thick, int $rgb, float $alpha): void
    {
        $amp = $thick * 1.5 / 2;
        $length = $thick * 6;
        $y += $amp;
        $n = max((int)ceil(($x1 - $x0) / ($length / 8)), 1); // eight points a wave, the last at x1
        $path = (new Path())->moveTo($x0, $y);
        for ($i = 1; $i <= $n; $i++) {
            $px = $x0 + ($x1 - $x0) * $i / $n;
            $path->lineTo($px, $y - $amp * sin(($px - $x0) / $length * 2 * M_PI));
        }
        $list->path($path, $rgb, $alpha, $thick);
    }

    /** The focus rings of the text inside a paragraph, around their words, and of the text inside that (mygo's paintInline). */
    private static function paintInline(Node $n, DisplayList $list, Theme $t): void
    {
        foreach ($n->kids as $k) {
            if ($k->focusVisible) {
                foreach ($k->frags as [$fx, $fy, $fw, $fh]) {
                    self::focusRing($list, $fx, $fy, $fw, $fh, 2.0, $t);
                }
            }
            self::paintInline($k, $list, $t);
        }
    }

    /** A text field: border, selection, text or placeholder, composition underline, caret. */
    private static function paintField(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
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
        if ($n->focused && !$s->readOnly && ($rt === null || $rt->caretVisible())) { // read-only: selected and copied, without a caret
            $list->rect($tx + $caretX, $ty, 1.0, $lh, $n->fg);
        }
        $list->popClip();
    }

    /** A text area: border, wrapped lines scrolled to the caret, selection across lines, composition underline, caret. */
    private static function paintArea(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
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
        if ($n->focused && !$s->readOnly && ($rt === null || $rt->caretVisible())) {
            [$cx, $cy] = $layout->caret($text, $s->displayCaret());
            $list->rect($tx + $cx, $ty + $cy, 1.0, $lh, $n->fg);
        }
        $list->popClip();
    }
}
