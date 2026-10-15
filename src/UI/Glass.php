<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Liquid Glass, as macOS 26's NSGlassEffectView and mygo's glass plugin
 * draw it: what was painted under the node shows through, blurred, bent
 * near the edge as through the rim of a lens, toned so what is on the
 * glass reads, and lit along a hairline rim. A node takes it with
 * material(); its corners come from round().
 *
 *     $c->row(8, 16)->round(22)->material(new Glass());
 */
final class Glass
{
    public const REGULAR = 0, CLEAR = 1;

    public function __construct(
        /** REGULAR frosts and tones what shows through; CLEAR barely blurs or tones it (over photos and video). */
        public int $style = self::REGULAR,
        /** Colors the glass by $tintAlpha, as for a prominent button. */
        public ?int $tint = null,
        public float $tintAlpha = 0.0,
        /** Grows a little while the node is pressed, as AppKit's interactive glass does. */
        public bool $interactive = false,
    ) {}
}
