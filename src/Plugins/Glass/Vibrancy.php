<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Glass;

use MyPHP\UI\{Material, Painter};

/**
 * A system material of WindowOptions::$vibrancy's names (titlebar,
 * sidebar, popover, hud…) drawn by myphp where the system draws none
 * (Node::systemMaterial off macOS): what is under it blurred (Blur) and
 * veiled in the tint the theme implies, light or dark, as
 * NSVisualEffectView's materials frost and tint what they show through.
 * HUD and full screen UI are dark whatever the theme, as AppKit's are.
 */
final class Vibrancy implements Material
{
    /** The blur, in points; the veil's colors (light, dark) and how opaque it is. */
    private const BLUR = 20.0, LIGHT = 0xf6f6f6, DARK = 0x262626, ALPHA = 0.72;
    /** The materials dark whatever the appearance. */
    private const ALWAYS_DARK = ['hud', 'fullscreen-ui'];

    private Blur $blur;

    public function __construct(public readonly string $name, public readonly bool $dark)
    {
        $this->blur = new Blur(self::BLUR);
    }

    public function paintMaterial(Painter $p, array $box, float $radius): void
    {
        $this->blur->paintMaterial($p, $box, $radius);
        $dark = $this->dark || in_array($this->name, self::ALWAYS_DARK, true);
        $p->fill($box, $dark ? self::DARK : self::LIGHT, $radius, self::ALPHA);
    }
}
