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
     * @param ?Closure(Closure(string, float): float): array{0: float, 1: float} $bounds
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
        /**
         * Its size, asked as the editor lays it out, as TextKit asks an
         * NSTextAttachment's attachmentBounds: fn($measure) => [$w, $h], or
         * [$w, $h, $descent], the points it goes below the baseline (the
         * bounds' negative y), so a chip sits centered on the text;
         * $measure($text, $size) the width of $text in the editor's font at
         * $size points, so a chip fits its name. Over $width and $height.
         */
        public ?Closure $bounds = null,
    ) {}

    /** How far below the baseline the box goes, as $bounds say. @internal */
    public function descent(?Closure $measure = null): float
    {
        if ($this->bounds === null) { return 0.0; }
        return (float)(($this->bounds)($measure ?? static fn(string $text, float $size): float => mb_strlen($text) * $size * 0.55)[2] ?? 0.0);
    }

    /** The size of the box; $bounds answer it, measuring with $measure, the editor's. @internal @param ?Closure(string, float): float $measure @return array{0: float, 1: float} */
    public function size(?Closure $measure = null): array
    {
        if ($this->bounds !== null) {
            $measure ??= static fn(string $text, float $size): float => mb_strlen($text) * $size * 0.55; // out of an editor (copied, written as HTML): no font to measure in, an average glyph's width
            [$w, $h] = ($this->bounds)($measure);
            return [max((float)$w, 1.0), max((float)$h, 1.0)];
        }
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
