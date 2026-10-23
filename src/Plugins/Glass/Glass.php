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
    ) {}

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
     * the renderer's shader bends, tones and lights it.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public static function paint(Painter $p, array $r, float $radius, self $g): void
    {
        if ($r[2] <= 0 || $r[3] <= 0) {
            return;
        }
        $s = $p->scale();
        $sigma = $g->style === Style::Clear ? $s : min(max(min($r[2], $r[3]) * 0.035, 1), 10) * $s;
        $p->glass($r, $radius, $g->style->value, $g->tint ?? 0, $g->tint === null ? 0.0 : $g->tintAlpha * 0.88, $sigma);
    }
}
