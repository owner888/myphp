<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A picture of a video and how long it shows (mygo's VideoFrame). */
final class VideoFrame
{
    public function __construct(
        /** Its size in pixels. */
        public readonly int $w,
        public readonly int $h,
        /** Its pixels, premultiplied RGBA, four bytes a pixel, top row first, as Image::fromPixels takes them. */
        public readonly string $rgba,
        /** How long it shows, in seconds; a frame of none shows for 100 ms, as browsers show GIFs'. */
        public readonly float $duration = 0.0,
    ) {}
}
