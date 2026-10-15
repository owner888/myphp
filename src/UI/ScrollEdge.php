<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * macOS 26's scroll edge effect, a material (mygo's glass.ScrollEdge):
 * what macOS draws over content scrolling under a bar of controls, so
 * they stand out from it. Fill a node over the content's edge with it,
 * under the bar: as long as the bar and 10 points more when soft, as
 * long as the bar when hard.
 *
 *     $c->column()->at(0, 0)->size($c->width, 74)->material(new ScrollEdge());
 */
final class ScrollEdge
{
    public function __construct(
        /** Hard: what is under it blurred by about 6 points, saturated, under 82% of the background, with a hairline along the other edge. Soft: the background fades over the content, from 85% at the bar's edge to nothing at the other. */
        public bool $hard = false,
        /** The bar is at the node's bottom, the effect rising from it. */
        public bool $bottom = false,
        /** What the content scrolls over; the theme's background by default. */
        public ?int $background = null,
    ) {}
}
