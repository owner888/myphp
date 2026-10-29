<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Media\AssRun;
use MyPHP\Native\VectorMask;

/**
 * An ASS drawing (\p) where its run is, as libass draws one: a glyph of
 * its box's size (the corners its points span), its contours at their own
 * coordinates from the box's top left, its foot on the baseline of the
 * text beside it (\pbo below it); filled in its primary color, its
 * outline its contours grown by the border, its shadow the outline (or
 * the fill, of no border) moved, blurred as \blur and \be say (the
 * outline, else the fill), the fill cut out of the outline and the shadow
 * as libass cuts it (rules()); BorderStyle 3 a box behind it instead.
 * Its masks are made by Core Graphics (Native\VectorMask) at the
 * display's pixels (fewer for one larger than an image may be), kept for
 * the frames it shows, and tinted as drawn.
 *
 * @internal VideoPlayer
 */
final class VideoPlayerAssDrawing
{
    /** The largest side of a mask, in pixels: an image atlas page's, less a little. */
    private const MAX = 2040;
    /** @var array<string, Image> the masks of the drawings shown last, by their shape, size, kind and blur */
    private static array $masks = [];

    /**
     * What libass draws of runs $runs of one style, of a line at $lineAlpha
     * (its fade), by its filters' flags (render_and_combine_glyphs): whether
     * the glyphs are cut out of the outline (FILL_IN_BORDER not set: a fill
     * not wholly opaque), out of the shadow (of an outline, FILL_IN_SHADOW
     * not set: a fill wholly clear, not sung), and whether a shadow shows
     * (of no outline, only of a fill that shows).
     * @param list<AssRun> $runs @return array{0: bool, 1: bool, 2: bool}
     */
    public static function rules(array $runs, float $lineAlpha): array
    {
        $s = $runs[0];
        $box = $s->borderStyle === 3;
        $opaque = $lineAlpha >= 1; $shows = false; $sung = false;
        foreach ($runs as $r) {
            $opaque = $opaque && $r->alpha >= 1 && $r->secondaryAlpha >= 1;
            $shows = $shows || $r->alpha > 0;
            $sung = $sung || $r->sweep !== null;
        }
        $fillInBorder = ($s->border > 0 && $opaque) || $box;
        $fillInShadow = $s->shadow > 0 && ($shows || $sung || $box);
        return [$s->border > 0 && !$fillInBorder, $s->border > 0 && $s->shadow > 0 && !$fillInShadow, $s->border > 0 || $fillInShadow];
    }

    /**
     * Run $r's drawing on a plane of $sx by $sy points a PlayRes unit, its
     * border and shadow $px points a unit, of a line at $lineAlpha, blurred
     * by $sigma points, on a baseline $descent points above the row's foot;
     * laid out but not drawn unless $drawn; sheared by $shear (\fax, \fay)
     * from its top left, as a glyph of its own.
     * @param ?array{0: float, 1: float} $shear
     */
    public static function build(Context $c, AssRun $r, float $sx, float $sy, float $px, float $lineAlpha, float $sigma, float $descent, bool $drawn = true, ?array $shear = null): void
    {
        $b = $r->drawingBounds();
        if ($b === null) {
            return;
        }
        [$x1, $y1, $x2, $y2] = $b;
        $node = $c->column()->size(($x2 - $x1) * $sx, ($y2 - $y1) * $sy)->passThrough();
        $below = $r->baselineOffset * $sy - $descent; // \pbo: its foot that far below the baseline
        $below <= 0 ? $node->margin(0, 0, -$below, 0) : $node->translate(0, $below);
        if ($shear !== null) { $node->transformOrigin(0, 0)->skew(rad2deg(atan($shear[0])), rad2deg(atan($shear[1]))); }
        if (!$drawn) {
            $c->end();
            return;
        }
        $box = $r->borderStyle === 3;
        $border = $r->border * $px; $shadow = $r->shadow * $px;
        $grows = $border > 0 && !$box;
        [$cutOutline, $cutShadow, $shadows] = self::rules([$r], $lineAlpha);
        $pad = ($grows ? $border : 0) + 3 * $sigma + 1 / max(1.0, $c->scale);
        [$fx, $fy] = [$x1 * $sx - $pad, $y1 * $sy - $pad]; // the masks' top left from the box's, points
        [$fw, $fh] = [($x2 - $x1) * $sx + 2 * $pad, ($y2 - $y1) * $sy + 2 * $pad];
        $k = min($c->scale, self::MAX / max($fw, $fh, 1e-6)); // pixels a point
        [$pw, $ph] = [max(1, (int)ceil($fw * $k)), max(1, (int)ceil($fh * $k))];
        $mask = function (string $kind) use ($r, $sx, $sy, $fx, $fy, $k, $pw, $ph, $border, $sigma, $grows, $box): Image { // 'fill', 'grown' or 'cut' (grown, the fill out of it)
            $key = md5(serialize([$kind, $r->drawing, $sx, $sy, $k, $border, $sigma, $grows, $box]));
            if (!isset(self::$masks[$key])) {
                $cmds = array_map(fn(array $cm): array => [$cm[0], ...self::pixels(array_slice($cm, 1), $sx, $sy, $fx, $fy, $k)], $r->drawing);
                self::$masks[$key] = Image::fromPixels($pw, $ph, $kind === 'fill'
                    ? VectorMask::rgba($cmds, 0, 0, $pw, $ph, 0, $grows ? 0 : $sigma * $k) // the fill blurred where there is no outline to blur
                    : VectorMask::rgba($cmds, 0, 0, $pw, $ph, $border * $k, $sigma * $k, $kind === 'cut'));
                if (count(self::$masks) > 24) { unset(self::$masks[array_key_first(self::$masks)]); }
            } else { // kept as the last used
                $m = self::$masks[$key]; unset(self::$masks[$key]); self::$masks[$key] = $m;
            }
            return self::$masks[$key];
        };
        $fill = $mask('fill');
        $outline = $grows ? $mask($cutOutline ? 'cut' : 'grown') : null;
        $shade = !$box && $shadow > 0 && $shadows ? ($grows ? $mask($cutShadow ? 'cut' : 'grown') : $fill) : null;
        [$bw, $bh] = [($x2 - $x1) * $sx, ($y2 - $y1) * $sy];
        $node->draw(static function (DisplayList $list, Node $n, Theme $t, TextPainter $text) use ($r, $fill, $outline, $shade, $fx, $fy, $fw, $fh, $shadow, $box, $border, $sigma, $x1, $y1, $sx, $sy, $bw, $bh): void {
            $paint = static function (Image $img, float $dx, float $dy, int $rgb, float $alpha) use ($list, $n, $text, $fx, $fy, $fw, $fh): void {
                if ($alpha <= 0) { return; }
                $slot = $text->imageSlot($img);
                $list->image($n->x + $fx + $dx, $n->y + $fy + $dy, $fw, $fh, $slot['u'], $slot['v'], $slot['w'], $slot['h'], $slot['page'], $alpha, 0.0, false, false, $rgb);
            };
            if ($box) { // BorderStyle 3: a box of the outline's color behind the drawing, its shadow the box moved, blurred with it
                $rect = static function (float $d, int $rgb, float $alpha) use ($list, $n, $border, $sigma, $x1, $y1, $sx, $sy, $bw, $bh): void {
                    [$x, $y, $w, $h] = [$n->x + $x1 * $sx - $border + $d, $n->y + $y1 * $sy - $border + $d, $bw + 2 * $border, $bh + 2 * $border];
                    $sigma > 0 ? $list->shadow($x, $y, $w, $h, $rgb, $alpha, 0.0, 1.64 * $sigma) : $list->rect($x, $y, $w, $h, $rgb, $alpha);
                };
                if ($shadow > 0) { $rect($shadow, $r->shadowColor, $r->shadowAlpha); }
                $rect(0.0, $r->outlineColor, $r->outlineAlpha);
            }
            if ($shade !== null) { $paint($shade, $shadow, $shadow, $r->shadowColor, $r->shadowAlpha); }
            if ($outline !== null) { $paint($outline, 0, 0, $r->outlineColor, $r->outlineAlpha); }
            $paint($fill, 0, 0, $r->color, $r->alpha);
        });
        $c->end();
    }

    /**
     * The coverage of a clip's drawing (\clip, \iclip) on a plane of $sx by
     * $sy points a PlayRes unit, as a mask image (white) and the box it lies
     * over, in PlayRes units, [x1, y1, x2, y2], a pixel past its points;
     * null for one that covers nothing.
     * @param list<list<string|float>> $cmds
     * @return ?array{0: Image, 1: array{0: float, 1: float, 2: float, 3: float}}
     */
    public static function clip(Context $c, array $cmds, float $sx, float $sy): ?array
    {
        $b = AssRun::bounds($cmds);
        if ($b === null) {
            return null;
        }
        $pad = 1 / max(1.0, $c->scale);
        [$fx, $fy] = [$b[0] * $sx - $pad, $b[1] * $sy - $pad];
        [$fw, $fh] = [($b[2] - $b[0]) * $sx + 2 * $pad, ($b[3] - $b[1]) * $sy + 2 * $pad];
        $k = min($c->scale, self::MAX / max($fw, $fh, 1e-6));
        [$pw, $ph] = [max(1, (int)ceil($fw * $k)), max(1, (int)ceil($fh * $k))];
        $key = md5(serialize(['clip', $cmds, $sx, $sy, $k]));
        if (!isset(self::$masks[$key])) {
            $pixels = array_map(fn(array $cm): array => [$cm[0], ...self::pixels(array_slice($cm, 1), $sx, $sy, $fx, $fy, $k)], $cmds);
            self::$masks[$key] = Image::fromPixels($pw, $ph, VectorMask::rgba($pixels, 0, 0, $pw, $ph, 0, 0));
            if (count(self::$masks) > 24) { unset(self::$masks[array_key_first(self::$masks)]); }
        }
        return [self::$masks[$key], [$fx / $sx, $fy / $sy, ($fx + $pw / $k) / $sx, ($fy + $ph / $k) / $sy]];
    }

    /** Points of PlayRes ($x, $y pairs) as pixels of a mask whose top left is ($fx, $fy) points from the box's, $k pixels a point. @param list<float> $xy @return list<float> */
    private static function pixels(array $xy, float $sx, float $sy, float $fx, float $fy, float $k): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($xy); $i += 2) {
            $out[] = ($xy[$i] * $sx - $fx) * $k;
            $out[] = ($xy[$i + 1] * $sy - $fy) * $k;
        }
        return $out;
    }
}
