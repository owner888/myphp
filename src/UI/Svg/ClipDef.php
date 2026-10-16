<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** A clipPath: the union of its shapes clips. */
final class ClipDef
{
    public bool $bbox = false; // clipPathUnits is objectBoundingBox
    public array $m = Matrix::IDENTITY;
    /** @var list<Node> */
    public array $kids = [];
    public ?ClipDef $clip = null; // its own clip-path
}
