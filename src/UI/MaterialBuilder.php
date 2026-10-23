<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A material that follows the node it fills, as its state (mygo's
 * MaterialBuilder): Node::material() calls buildMaterial() as the node is
 * built, and paints the material it returns.
 */
interface MaterialBuilder extends Material
{
    public function buildMaterial(Context $c, Node $n): Material;
}
