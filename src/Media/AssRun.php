<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A run of an ASS line in one style, as its style and its override tags
 * make it: sizes in the script's PlayRes units, colors 0xRRGGBB with an
 * alpha of 0 (clear) to 1. A text of "\n" alone is a line's break (\N).
 * A drawing (\p) is a run of no text and its shape, in PlayRes units.
 */
final class AssRun
{
    public function __construct(
        public readonly string $text,
        public readonly string $font,
        public readonly float $size,
        /** 400 regular, 700 bold, or the weight \b gave. */
        public readonly int $weight,
        public readonly bool $italic,
        public readonly bool $underline,
        public readonly bool $strikeout,
        public readonly int $color,
        public readonly float $alpha,
        public readonly int $outlineColor,
        public readonly float $outlineAlpha,
        public readonly int $shadowColor,
        public readonly float $shadowAlpha,
        /** The outline's width and the shadow's offset, in PlayRes units. */
        public readonly float $border,
        public readonly float $shadow,
        public readonly float $spacing,
        /** 1, an outline and a shadow; 3, an opaque box of the outline's color behind the text. */
        public readonly int $borderStyle,
        /** \fscx and \fscy as parts (1 for 100 %), and \frz in degrees, counterclockwise. */
        public readonly float $scaleX = 1.0,
        public readonly float $scaleY = 1.0,
        public readonly float $rotation = 0.0,
        /** \frx and \fry: turned in 3D around the horizontal and the vertical axis, degrees, as libass (its top toward the viewer for a positive \frx, its right side away for a positive \fry). */
        public readonly float $rotationX = 0.0,
        public readonly float $rotationY = 0.0,
        /** \fax and \fay: sheared, x by y times it and y by x times it. */
        public readonly float $shearX = 0.0,
        public readonly float $shearY = 0.0,
        /** \blur: a Gaussian blur in PlayRes units (libass's radius); \be: passes of a 3 by 3 box blur, in the picture's pixels. */
        public readonly float $blur = 0.0,
        public readonly int $edgeBlur = 0,
        /** \p: the drawing this run is, else null: its commands, ['m', x, y] (a contour begun), ['l', x, y], ['b', x1, y1, x2, y2, x3, y3] (a cubic Bezier), ['c'] (the contour closed), in PlayRes units; and \pbo, how far below the baseline it goes. @var ?list<list<string|float>> */
        public readonly ?array $drawing = null,
        public readonly float $baselineOffset = 0.0,
        /** \kf and \K as they sing: how far across the syllable the primary color has swept over the secondary, 0 to 1; null for a run not being sung so. */
        public readonly ?float $sweep = null,
        public readonly int $secondary = 0xff0000,
        public readonly float $secondaryAlpha = 1.0,
    ) {}

    /** A drawing's box, in PlayRes units: the corners of what its points span (control points too, as libass's), [x1, y1, x2, y2]; null for a run of text or a drawing that draws nothing (no line or curve). @return ?array{0: float, 1: float, 2: float, 3: float} */
    public function drawingBounds(): ?array
    {
        return self::bounds($this->drawing ?? []);
    }

    /** The box of drawing commands $cmds as drawingBounds() gives it (a clip's drawing too, AssLine::clipDrawing). @param list<list<string|float>> $cmds @return ?array{0: float, 1: float, 2: float, 3: float} */
    public static function bounds(array $cmds): ?array
    {
        $xs = []; $ys = []; $draws = false;
        foreach ($cmds as $c) {
            $draws = $draws || $c[0] === 'l' || $c[0] === 'b';
            for ($i = 1; $i + 1 < count($c); $i += 2) { $xs[] = $c[$i]; $ys[] = $c[$i + 1]; }
        }
        return $draws ? [min($xs), min($ys), max($xs), max($ys)] : null;
    }
}
