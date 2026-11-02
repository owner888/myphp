<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * Decodes keyframes of a video track into small pictures, away from what
 * plays (Media::thumbnails). Its public readonly int $width and $height
 * are the size of the pictures: $width as asked, as tall as the track's
 * shape has it.
 */
interface Thumbnails
{
    /** Keyframe $p decoded, as a picture of the decoder's size: RGBA (opaque), top row first. */
    public function frame(\MyPHP\Media\Packet $p): string;
}
