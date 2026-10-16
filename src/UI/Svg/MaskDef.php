<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** A mask: the luminance, or alpha, of its content masks, within its region. */
final class MaskDef
{
    public bool $units = true, $content = false; // maskUnits and maskContentUnits are objectBoundingBox
    public array $region = [0.0, 0.0, 0.0, 0.0];
    public bool $alpha = false;
    /** @var list<Node> */
    public array $kids = [];
}
