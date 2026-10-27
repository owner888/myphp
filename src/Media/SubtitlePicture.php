<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;

/**
 * A picture of an image subtitle (a PGS object, a VobSub's bitmap): where
 * it shows on the subtitles' plane, in its pixels, and the size of the
 * plane, which lies over the film's picture. Its pixels are decoded from
 * the file's runs as they are asked for, not kept: a film has hundreds.
 */
final class SubtitlePicture
{
    /** @param Closure(): string $decode its pixels, premultiplied RGBA, top row first */
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $planeWidth,
        public readonly int $planeHeight,
        private readonly Closure $decode,
    ) {}

    /** Its pixels: premultiplied RGBA, four bytes a pixel, top row first (UI\Image::fromPixels). */
    public function rgba(): string
    {
        return ($this->decode)();
    }
}
