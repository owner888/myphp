<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * A drawing compiled from an element, as mygo's internal/svg node: a
 * shape, or a group of nodes; $m maps its user space to its parent's.
 * $bbox is the box around its geometry in its user space (null for
 * none), for what is in objectBoundingBox units. $single is true when
 * the node paints one thing at most, so that its opacity can apply to it
 * instead of to a layer; $flat when its parent's opacity can apply to it
 * instead.
 */
final class Node
{
    public array $m = Matrix::IDENTITY;
    public float $opacity = 1;
    public ?ClipDef $clip = null;
    public ?MaskDef $mask = null;
    public ?Shape $shape = null;
    /** @var list<Node> */
    public array $kids = [];
    public ?array $bbox = null;
    public bool $single = false, $flat = false;
}
