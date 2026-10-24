<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Highlights over the content the node under the pointer, in the tree or
 * picking, else the node chosen while the tree has the focus, as Chrome
 * does (mygo's inspector_overlay.go): its content in blue, padding in
 * green, border in yellow and margins in orange, with a tip telling what
 * it is, its size, colors, font and spacing, and what assistive
 * technology sees.
 */
final class InspectorOverlay
{
    /** Chrome's colors for the boxes of a node highlighted: [rgb, alpha]. */
    private const CONTENT = [0x6fa8dc, 0.66], PADDING = [0x93c47d, 0.55], BORDER = [0xffe599, 0.66], MARGIN = [0xf6b26b, 0.66];

    public static function paint(DisplayList $list, TextPainter $text, Runtime $rt, Inspector $in, float $h): void
    {
        $e = $in->hoverElem;
        if ($e === null && $in->listId !== null && $rt->focused($in->listId)) { $e = $in->selElem; }
        if ($e === null || $e->leaving !== 0) { return; }
        $world = $list->world(); $opacity = $list->opacity();
        $list->pushClip(0, 0, $in->appW, $h);
        $list->setOpacity(1.0);
        $m = array_map(fn(float $v) => is_finite($v) ? $v : 0.0, $e->margins);
        $b = $e->bordered ? array_fill(0, 4, $e->borderWidth) : $e->borders;
        $border = [$e->x, $e->y, $e->w, $e->h];
        $margin = [$e->x - $m[3], $e->y - $m[0], $e->w + $m[1] + $m[3], $e->h + $m[0] + $m[2]];
        $padding = [$e->x + $b[3], $e->y + $b[0], $e->w - $b[1] - $b[3], $e->h - $b[0] - $b[2]];
        $content = [$e->x + $e->padL(), $e->y + $e->padT(), $e->w - $e->padL() - $e->padR(), $e->h - $e->padT() - $e->padB()];
        $list->setWorld($e->world); // through its transform, as it shows
        self::ring($list, $margin, $border, self::MARGIN);
        self::ring($list, $border, $padding, self::BORDER);
        self::ring($list, $padding, $content, self::PADDING);
        if ($content[2] > 0 && $content[3] > 0) { $list->rect(...[...$content, self::CONTENT[0], self::CONTENT[1]]); }
        $list->setWorld($world);
        self::tip($list, $text, $rt, $in, $e, $e->world->bounds(...$margin), $h);
        $list->popClip();
        $list->setOpacity($opacity);
    }

    /** Fills the room between $outer and $inner, which it holds. */
    private static function ring(DisplayList $list, array $outer, array $inner, array $c): void
    {
        [$ox, $oy, $ow, $oh] = $outer; [$ix, $iy, $iw, $ih] = $inner;
        if ($ow <= 0 || $oh <= 0) { return; }
        if ($iw <= 0 || $ih <= 0) {
            $list->rect($ox, $oy, $ow, $oh, $c[0], $c[1]);
            return;
        }
        if ($iy > $oy) { $list->rect($ox, $oy, $ow, $iy - $oy, $c[0], $c[1]); }
        if ($oy + $oh > $iy + $ih) { $list->rect($ox, $iy + $ih, $ow, $oy + $oh - $iy - $ih, $c[0], $c[1]); }
        if ($ix > $ox) { $list->rect($ox, $iy, $ix - $ox, $ih, $c[0], $c[1]); }
        if ($ox + $ow > $ix + $iw) { $list->rect($ix + $iw, $iy, $ox + $ow - $ix - $iw, $ih, $c[0], $c[1]); }
    }

    /** The tip of $e, whose margin box shows at $box: above it, else below, as Chrome's; light over light and dark content alike. */
    private static function tip(DisplayList $list, TextPainter $text, Runtime $rt, Inspector $in, Node $e, array $box, float $h): void
    {
        $pad = 8; $size = 11; $gap = 4;
        $ink = 0x202124; $muted = 0x5f6368;
        $ts = InspectorDetails::resolved($e, $in->selElem === $e ? $in->selChain : []);
        $rows = []; // [label, value, swatch color or null]
        if ($e->kind === Node::TEXT || $e->kind === Node::RICH || $e->svg !== null) {
            $color = $ts->color ?? $in->base?->text ?? 0;
            $rows[] = ['Color', Inspector::color($color), $color];
            $rows[] = ['Font', Inspector::px($ts->size ?? $in->base?->fontSize ?? 13) . ' ' . ($ts->family ?? 'system-ui'), null];
        }
        if ($e->color !== null && $e->material === null && $e->kind !== Node::TEXT && $e->kind !== Node::RICH) { $rows[] = ['Background', Inspector::color($e->color), $e->color]; }
        if (($v = InspectorDetails::edges($e->sides())) !== null) { $rows[] = ['Padding', $v, null]; }
        if (($v = InspectorDetails::edges($e->margins)) !== null) { $rows[] = ['Margin', $v, null]; }
        [$role] = Inspector::accessibleRole($rt, $e);
        $focusable = Runtime::takesFocus($e) && !$e->disabled;
        $name = $e->label !== '' ? $e->label : Inspector::clip(Runtime::innerText($e), 40);
        $acc = [['Name', $name, null], ['Role', $role, null], ['Keyboard-focusable', $focusable ? '✓' : '⊘', null]];

        // Measure, then paint: the header, the rows, a line, the accessibility.
        $title = Inspector::name($e, $e->id === ($in->nodes[0]['id'] ?? null) ? 0 : 1);
        $key = isset($in->keys[$e->id]) ? '#' . Inspector::clip((string)$in->keys[$e->id], 24) : '';
        $dims = Inspector::num($e->w) . ' × ' . Inspector::num($e->h);
        [$tw, $th] = $text->measure($title, $size, Font::BOLD);
        [$kw] = $key === '' ? [0.0] : $text->measure($key, $size);
        [$dw] = $text->measure($dims, $size);
        $labelW = 0.0; $valueW = 0.0;
        foreach ([...$rows, ...$acc] as [$l, $v, $sw]) {
            $labelW = max($labelW, $text->measure($l, $size)[0]);
            $valueW = max($valueW, $text->measure($v, $size)[0] + ($sw !== null ? 14 : 0));
        }
        $lineH = $th + $gap;
        $width = min(max($tw + $kw + 16 + $dw, $labelW + 16 + $valueW, 160) + 2 * $pad, max($in->appW - 8, 100));
        $height = $pad + $th + $gap + count($rows) * $lineH + 9 + $th + $gap + count($acc) * $lineH + $pad - $gap;
        // Above the node, else below it, else inside its top.
        $arrow = 7;
        $x = max(4, min($box[0], $in->appW - $width - 4));
        $y = $box[1] - $height - $arrow;
        $below = $y < 4;
        if ($below) {
            $y = $box[1] + $box[3] + $arrow;
            if ($y + $height > $h - 4) { $y = max(4, $box[1] + 4); }
        }
        $list->shadow($x, $y, $width, $height, 0x000000, 0.3, 4, 8, 0, 2);
        $list->rect($x, $y, $width, $height, 0xd0d0d0, 1.0, 4);
        $list->rect($x + 1, $y + 1, $width - 2, $height - 2, 0xffffff, 1.0, 3);
        $ax = max($x + 10, min($box[0] + 12, $x + $width - 10)); // the arrow, pointing at the node
        $p = new Path();
        if ($below) {
            $p->moveTo($ax - $arrow, $y + 0.5); $p->lineTo($ax, $y - $arrow + 0.5); $p->lineTo($ax + $arrow, $y + 0.5);
        } else {
            $p->moveTo($ax - $arrow, $y + $height - 0.5); $p->lineTo($ax, $y + $height + $arrow - 0.5); $p->lineTo($ax + $arrow, $y + $height - 0.5);
        }
        $p->close();
        $list->path($p, 0xffffff);

        $cx = $x + $pad; $cy = $y + $pad;
        $text->paint($list, $title, $size, $cx, $cy, 0x881280, 1.0, Font::BOLD);
        if ($key !== '') { $text->paint($list, $key, $size, $cx + $tw, $cy, 0x1a1aa6); }
        $text->paint($list, $dims, $size, $x + $width - $pad - $dw, $cy, $muted);
        $cy += $lineH;
        $row = function (array $r, int $color) use ($list, $text, $size, $cx, &$cy, $x, $width, $pad, $valueW, $th, $lineH, $muted): void {
            $text->paint($list, $r[0], $size, $cx, $cy, $muted);
            $vx = $x + $width - $pad - $valueW;
            if ($r[2] !== null) {
                $list->rect($vx, $cy + ($th - 10) / 2, 10, 10, 0xc0c0c0);
                $list->rect($vx + 1, $cy + ($th - 10) / 2 + 1, 8, 8, $r[2]);
                $vx += 14;
            }
            $text->paint($list, $r[1], $size, $vx, $cy, $color);
            $cy += $lineH;
        };
        foreach ($rows as $r) { $row($r, $ink); }
        $list->rect($x + $pad, $cy + 3, $width - 2 * $pad, 1, 0xe0e0e0);
        $cy += 9;
        $text->paint($list, 'ACCESSIBILITY', $size, $cx, $cy, $muted, 1.0, Font::BOLD);
        $cy += $lineH;
        foreach ($acc as $i => $r) { $row($r, $i === 2 ? ($focusable ? 0x188038 : $muted) : $ink); }
    }
}
