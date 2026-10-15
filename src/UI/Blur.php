<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A backdrop blur, a material: what was painted under the node shows
 * through it blurred, with no tint, rim or shadow (mygo's glass.Blur).
 * With $fade, the blur fades along the node: full at the top and gone at
 * the bottom (or the reverse with $fromBottom), as under a bar that
 * content scrolls under. The fade mixes the blurred and the sharp
 * backdrop; mygo blurs in levels instead, which reads a little truer.
 *
 *     $c->row(8, 12)->at(0, 0)->size($c->width, 56)->material(new Blur(12, fade: true));
 */
final class Blur
{
    public function __construct(
        /** How much what shows through blurs, in points: a Gaussian's standard deviation, as CSS's blur(). */
        public float $radius = 12,
        public bool $fade = false,
        public bool $fromBottom = false,
    ) {}
}
