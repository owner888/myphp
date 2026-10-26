<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;
use MyPHP\UI\Image;
use MyPHP\UI\SVG;

/**
 * An object inside a Text, as an image in a note (mygo's
 * richtext.Attachment): one object replacement character (U+FFFC) whose
 * Attributes hold it, which the editor lays out as a box of its size
 * inside the line, on the baseline, and selects, copies and deletes as
 * one character.
 */
final class Attachment
{
    /**
     * @param ?Closure(\MyPHP\UI\Painter, array{0: float, 1: float, 2: float, 3: float}): void $draw
     */
    public function __construct(
        /** Drawn in the box, unless $draw draws it. */
        public Image|SVG|null $image = null,
        /** The size of the box in points; 0 takes the image's size. A box wider than the editor is scaled down to fit. */
        public float $width = 0,
        public float $height = 0,
        /** Describes the attachment to assistive technology, and stands for it in plain text: an image's alternative text, a file's name. */
        public string $name = '',
        /** Where the attachment comes from, as a file's or an image's, which HTML and Markdown write for it. */
        public string $url = '',
        /** Draws the attachment with a painter in its rect [x, y, w, h], instead of $image, as an app's chip for a mention or a file (mygo's Draw): the painter has the window's theme, and text. */
        public ?Closure $draw = null,
        /** The app's. */
        public mixed $data = null,
    ) {}

    /** The size of the box. @internal @return array{0: float, 1: float} */
    public function size(): array
    {
        $w = $this->width; $h = $this->height;
        if ($this->image !== null && ($w <= 0 || $h <= 0)) {
            $iw = (float)$this->image->w; $ih = (float)$this->image->h;
            if ($w <= 0 && $h <= 0) {
                $w = $iw; $h = $ih;
            } elseif ($w <= 0 && $ih > 0) {
                $w = $h * $iw / $ih;
            } elseif ($h <= 0 && $iw > 0) {
                $h = $w * $ih / $iw;
            }
        }
        return [max($w, 1.0), max($h, 1.0)];
    }
}
