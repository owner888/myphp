<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * What a node's ContentTransition keeps from frame to frame (mygo's
 * contentRec): what it showed (its text, its draw closure and icon, its
 * picture) and what it showed before, which goes as $k goes from 0 to 1;
 * $frame is the last frame that painted it.
 */
final class ContentState
{
    public int $frame = 0;
    public ?string $text = null, $oldText = null;
    public ?SVG $svg = null, $oldSvg = null;
    public ?Image $image = null, $oldImage = null;
    public ?Closure $draw = null, $oldDraw = null;
    public float $oldX = 0.0, $oldY = 0.0;
    public int $oldColor = 0;
    public MotionChannel $k;
    public bool $still = false;
}
