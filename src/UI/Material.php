<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What fills a node in place of a background color (mygo's ui.Material):
 * Node::material() fills it, shaped by its round(); the node's children
 * draw over it. Plugins\Glass has Liquid Glass, a blur and macOS's scroll
 * edges.
 */
interface Material
{
    /** Paints the material over $box, [x, y, w, h] in points, rounded by $radius, under the node's border and children. @param array{0: float, 1: float, 2: float, 3: float} $box */
    public function paintMaterial(Painter $p, array $box, float $radius): void;
}
