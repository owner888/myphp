<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;

/**
 * A small picture of a film's frame (Player::thumbnail): the keyframe's
 * time, its size and its pixels, RGBA premultiplied, top row first, made
 * when first asked (one read from the thumbnails' cache decodes then).
 */
final class Thumbnail
{
    private ?string $rgba = null;

    /** @param Closure(): string $pixels */
    public function __construct(
        public readonly float $time,
        public readonly int $width,
        public readonly int $height,
        private ?Closure $pixels,
    ) {}

    /** The pixels, width × height × 4 bytes. */
    public function rgba(): string
    {
        if ($this->rgba === null) {
            $this->rgba = ($this->pixels)();
            $this->pixels = null;
        }
        return $this->rgba;
    }
}
