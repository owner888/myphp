<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Glass;

use MyPHP\UI\{Context, Material, MaterialBuilder, Node, Painter};

/**
 * Liquid Glass in native UI, as macOS 26 and later draw it (AppKit's
 * NSGlassEffectView; mygo's plugins/glass), a material: what the nodes
 * under it painted shows through, blurred, bent near its edges as through
 * the rim of a lens, and lit along its hairline rim. Node::material() fills
 * a node with it, in place of a background, shaped by its round():
 *
 *     $c->row(8, 16)->round(22)->material(new Glass);
 *
 * ScrollEdge is macOS's scroll edge effect, which content scrolling under
 * a bar fades or frosts under, and Blur blurs what is under a node without
 * the glass, evenly or fading along a gradient.
 */
final class Glass implements MaterialBuilder
{
    public function __construct(
        public Style $style = Style::Regular,
        /** Colors the glass by $tintAlpha, as for a prominent button: an opaque tint lets a little of the glass show, as AppKit's does. */
        public ?int $tint = null,
        public float $tintAlpha = 1.0,
        /** Makes the glass grow a little while the node is pressed, as AppKit's interactive glass does. */
        public bool $interactive = false,
        /** How far an interactive glass has grown, from 0 to 1, as its node is built. @internal */
        public float $grow = 0,
        /**
         * From 0 to 1, makes panes as large as sidebars clearer, the content
         * under them reading through (mygo's Clarity): 0, the default, is
         * macOS's material, as frosty as a control's; 0.5 blurs half as much
         * and lightens (or darkens) less; 1 is nearly clear. Panes up to 100
         * points keep their material whatever it is; it takes full effect
         * from 200. On clear glass, which barely tones already, it only
         * lights the rim and curves the bezel.
         */
        public float $clarity = 0.0,
    ) {}

    /**
     * A parameter of a large pane's material at clarity 0 (the measured
     * material's), 0.5 and 1 (mygo's paneTone): the most it blurs, in
     * points; what black maps to in light mode (white to 1); what black
     * and white map to in dark mode; how bright the rim is lit; how wide
     * the bezel curves, in points.
     */
    private const BLUR = [10.0, 5.0, 2.0], LIGHT_LOW = [0.541, 0.35, 0.2], DARK_LOW = [0.15, 0.15, 0.1], DARK_HIGH = [0.51, 0.6, 0.85],
        RIM = [0.35, 0.5, 0.5], BEZEL = [36.0, 48.0, 48.0];

    /** Follows the press of an interactive glass's node: it grows as it is pressed and shrinks back as it is let go, within 150 ms, as AppKit's does. */
    public function buildMaterial(Context $c, Node $n): Material
    {
        if (!$this->interactive) {
            return $this;
        }
        $pressed = ($c->runtime?->input->mouseDown ?? false) && $c->hovered($n);
        $g = clone $this;
        $g->grow = $c->animate($n, 'glass-press', $pressed ? 1.0 : 0.0, 0.15);
        return $g;
    }

    /**
     * Paints the glass, grown by how far it is pressed: as macOS 27's,
     * measured from NSGlassEffectView, by about 1.1 points left and right
     * and 0.45 above and below, whatever its size. AppKit stretches the
     * content too, a pixel or so, which the children here are not.
     */
    public function paintMaterial(Painter $p, array $box, float $radius): void
    {
        if ($this->grow > 0) {
            $dx = 1.1 * $this->grow; $dy = 0.45 * $this->grow;
            $box = [$box[0] - $dx, $box[1] - $dy, $box[2] + 2 * $dx, $box[3] + 2 * $dy];
            if ($radius > 0) {
                $radius += $dy;
            }
        }
        self::paint($p, $box, $radius, $this);
    }

    /**
     * Paints a pane of glass shaped as $r rounded by $radius, in a drawing
     * (Node::draw, a game's), over what the painter painted before it.
     *
     * Its material follows macOS 27's, measured from NSGlassEffectView:
     * larger panes blur more, up to 10 points, the clear one by a pixel;
     * the renderer's shader bends, tones and lights it. Panes as large as
     * sidebars are clearer by the glass's $clarity (paneMaterial).
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public static function paint(Painter $p, array $r, float $radius, self $g): void
    {
        if ($r[2] <= 0 || $r[3] <= 0) {
            return;
        }
        $s = $p->scale();
        $m = min($r[2], $r[3]);
        $clear = $g->style === Style::Clear;
        $light = self::paneMaterial($m, $s, $g->clarity, $clear, false);
        $dark = self::paneMaterial($m, $s, $g->clarity, $clear, true);
        $p->glass($r, $radius, $g->style->value, $g->tint ?? 0, $g->tint === null ? 0.0 : $g->tintAlpha * 0.88, $light['blur'],
            [$light['bezel'] / $s, $light['rim'], $light['low'], $dark['low'], $dark['high']]);
    }

    /**
     * The material of a pane whose shorter side is $m points, at $s pixels
     * a point, of clarity $k from 0 to 1, clear or regular, in dark mode or
     * light (mygo's paneMaterial): the blur and the bezel in pixels, how
     * bright the rim is, what black (low) and white (high) map to. Panes
     * up to 100 points, as controls, keep the measured material; from 200,
     * as sidebars and inspectors, theirs is clarity's; between, it eases
     * from one to the other. Clarity goes in two straight steps, each half
     * of it, from the measured material to a clearer one and on to one
     * nearly clear.
     * @internal
     * @return array{blur: float, bezel: float, rim: float, low: float, high: float}
     */
    public static function paneMaterial(float $m, float $s, float $k, bool $clear, bool $dark): array
    {
        $f = min(max(($m - 100) / 100, 0.0), 1.0);
        $f = $f * $f * (3 - 2 * $f); // smoothstep
        $k = is_nan($k) ? 0.0 : min(max($k, 0.0), 1.0);
        $at = function (array $v) use ($k, $f): float {
            [$frost, $mid, $clearer] = $v;
            $to = $k <= 0.5 ? $frost + ($mid - $frost) * $k * 2 : $mid + ($clearer - $mid) * ($k - 0.5) * 2;
            return $frost + ($to - $frost) * $f;
        };
        $bezel = min($at(self::BEZEL), $m / 2);
        $blur = min(max($m * 0.035, 1), $at(self::BLUR)) * $s;
        [$blur, $low, $high] = match (true) {
            $clear => [$s, 0.125, 1.082],
            $dark => [$blur, $at(self::DARK_LOW), $at(self::DARK_HIGH)],
            default => [$blur, $at(self::LIGHT_LOW), 1.0],
        };
        return ['blur' => $blur, 'bezel' => $bezel * $s, 'rim' => $at(self::RIM), 'low' => $low, 'high' => $high];
    }
}
