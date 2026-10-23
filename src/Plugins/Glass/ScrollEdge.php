<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Glass;

use MyPHP\UI\{LinearGradient, Material, Painter};

/**
 * The scroll edge effect of macOS 26 and later, a material (mygo's
 * glass.ScrollEdge): what macOS draws over content scrolling under a bar
 * of controls, so that they stand out from it, as SwiftUI's safeAreaBar
 * with scrollEdgeEffectStyle does. Fill a node over the content's edge
 * with it, under the bar, as long as the bar and 10 points more when soft,
 * as long as the bar when hard:
 *
 *     $c->column()->at(0, 0)->size($c->width, 74)->material(new ScrollEdge);
 *
 * Its looks are macOS 27's, measured from SwiftUI's: the soft edge fades
 * the content into its background, with no blur; the hard edge frosts it.
 */
final class ScrollEdge implements Material
{
    /** The alpha of the background at a soft scroll edge's bar, AppKit's. */
    public const SOFT_EDGE = 0.85;
    /** How much a hard edge blurs, in points: AppKit's Gaussian of radius 6 spreads an edge 30 pixels from 10% to 90% at a scale of 2, as the renderer's does at 6.4. */
    public const HARD_EDGE_BLUR = 6.4;

    public function __construct(
        /** Hard: what is under it blurred by about 6 points, its colors saturated, under 82% of the background, evenly, with a hairline along its other edge. Soft: the background fades over the content, from 85% at the bar's edge to nothing at the other. */
        public bool $hard = false,
        /** Puts the bar at the node's bottom, the effect rising from it, rather than at its top. */
        public bool $bottom = false,
        /** What the content scrolls over, which the effect lays over it; the theme's background when null. */
        public ?int $background = null,
    ) {}

    public function paintMaterial(Painter $p, array $box, float $radius): void
    {
        if ($box[2] <= 0 || $box[3] <= 0) {
            return;
        }
        $t = $p->theme();
        $bg = $this->background ?? $t->background;
        if (!$this->hard) {
            // The background, its alpha 0.85 at the bar's edge, falling
            // linearly to 0 at the other, as AppKit's soft pocket replays the
            // window's background under a gradient mask, in sRGB.
            $p->fillGradient($box, new LinearGradient($bg, self::SOFT_EDGE, $bg, 0.0, $this->bottom ? 0 : 180), $radius);
            return;
        }
        // Under a hard edge, the content blurred, saturated by 1.25 and
        // lightened by 0.03, as AppKit's HardPocketContentBlur, under the
        // background at 82.45%, desaturated to 0.947 and lightened by 0.03,
        // as its HardPocketBackgroundReplay, evenly.
        $p->hardBlur($box, $radius, self::HARD_EDGE_BLUR * $p->scale(), self::toned($bg, 0.947, 0.03), 0.8245);
        // The hairline along the other edge, outside it, half a point: black
        // at 10% in light mode, white at 7% in dark mode.
        $h = max(0.5, 1 / $p->scale());
        $y = $this->bottom ? $box[1] - $h : $box[1] + $box[3];
        $p->fill([$box[0], $y, $box[2], $h], $t->dark ? 0xffffff : 0x000000, 0, ($t->dark ? 0.07 : 0.1) * 0.5 / $h);
    }

    /** $rgb saturated by $saturation around its luminance, with $offset added, clamped, as AppKit's color matrices tone. */
    public static function toned(int $rgb, float $saturation, float $offset): int
    {
        $c = [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255];
        $l = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        $out = 0;
        foreach ($c as $v) {
            $out = ($out << 8) | (int)round(min(max($l + ($v - $l) * $saturation + $offset, 0), 1) * 255);
        }
        return $out;
    }
}
