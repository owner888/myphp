<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A pointer shape (mygo's Cursor), named as CSS's cursor property names it. */
enum Cursor: string
{
    case Default = 'default';
    case Pointer = 'pointer';
    case Text = 'text';
    case Move = 'move';
    case ResizeEW = 'ew-resize';
    case ResizeNS = 'ns-resize';
    case ResizeNWSE = 'nwse-resize';
    case ResizeNESW = 'nesw-resize';
    case NotAllowed = 'not-allowed';
    case Crosshair = 'crosshair';
    case Grab = 'grab';
    case Grabbing = 'grabbing';
    /** Resizing toward one side only, as at the limit of a resize. */
    case ResizeN = 'n-resize';
    case ResizeE = 'e-resize';
    case ResizeS = 's-resize';
    case ResizeW = 'w-resize';
    /** Moving the line between columns or rows, as of a table or a split. */
    case ResizeColumn = 'col-resize';
    case ResizeRow = 'row-resize';
    case VerticalText = 'vertical-text';
    /** A drop copies or links what is dragged. */
    case Copy = 'copy';
    case Alias = 'alias';
    case ContextMenu = 'context-menu';
    /** Hides the pointer over the node, as over a video. */
    case None = 'none';
}
