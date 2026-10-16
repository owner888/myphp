<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\UI\Svg\Doc;
use RuntimeException;

/**
 * A vector image parsed once, as mygo's SVG: Context::icon shows its
 * shapes in the color of the text, from a mask the text system caches
 * in its atlas; Context::image shows it in its own colors, as a picture
 * drawn at the size it shows. Svg\Doc draws paths and the basic shapes,
 * groups, use and symbol elements, transforms, colors, gradients,
 * strokes and their dashes, opacity, clip paths, masks and style sheets
 * of simple selectors; text, embedded images, patterns, markers and
 * filters are left out: convert text to paths before. Parse one once
 * and keep it in the app's state.
 */
final class SVG
{
    private static int $next = 0;
    /** Tells the masks and pictures of one SVG from another's. */
    public readonly int $id;
    /** The size the SVG says it has, its width and height or those of its viewBox, in points. */
    public readonly float $w, $h;

    private function __construct(public readonly Doc $doc)
    {
        $this->id = ++self::$next;
        $this->w = $doc->w; $this->h = $doc->h;
    }

    public static function parse(string $data): self
    {
        return new self(new Doc($data));
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) { throw new RuntimeException("cannot read SVG $path"); }
        return self::parse($data);
    }
}
