<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * A parsed SVG document drawn into pixels, as mygo's internal/svg Doc: it
 * draws paths and the basic shapes, groups, nested svg elements, use and
 * symbol elements, transforms, fills and strokes (with joins, caps and
 * dashes) of colors, currentColor and linear and radial gradients,
 * opacity, clip paths and masks, and style sheets of simple selectors
 * (type, class and id). Text, embedded images, patterns, markers and
 * filters are not drawn.
 */
final class Doc
{
    private ?Node $root;
    /** The viewBox [x, y, w, h] and the preserveAspectRatio. */
    private array $view, $aspect;
    /** The document's size in pixels: its width and height, or those of its viewBox. */
    public readonly float $w, $h;
    /** Whether the color it is drawn with, for currentColor, changes what it draws. */
    public readonly bool $usesCurrentColor;

    public function __construct(string $data)
    {
        $c = new Compiler(Elem::parse($data));
        [$this->root, $this->view, $this->aspect, $this->w, $this->h] = $c->doc();
        $this->usesCurrentColor = $c->current;
    }

    /**
     * The document drawn in $w × $h pixels, fitting its viewBox in them as
     * its preserveAspectRatio says, or stretched to them with $stretch,
     * $current (r, g, b, a of 0..1) where it does not set the color
     * property: premultiplied RGBA, four ints of 0..255 a pixel, top row first.
     * @return list<int>
     */
    public function pixels(int $w, int $h, array $current = Style::BLACK, bool $stretch = false): array
    {
        return $this->draw($w, $h, $current, Style::viewBoxTransform($this->view, $stretch ? [true, 0.5, 0.5, false] : $this->aspect, $w, $h));
    }

    /** The document drawn as pixels() does in a box $w × $h at the center of $cw × $ch pixels, turned by $degrees clockwise around it. @return list<int> */
    public function rotated(int $w, int $h, int $cw, int $ch, float $degrees, array $current = Style::BLACK): array
    {
        $m = Matrix::mul(Matrix::mul(Matrix::translate($cw / 2, $ch / 2), Matrix::rotation($degrees)), Matrix::translate(-$w / 2, -$h / 2));
        return $this->draw($cw, $ch, $current, Matrix::mul($m, Style::viewBoxTransform($this->view, $this->aspect, $w, $h)));
    }

    /** @return list<int> */
    private function draw(int $w, int $h, array $current, array $m): array
    {
        if ($w <= 0 || $h <= 0) { return []; }
        $r = new Renderer($w, $h);
        $l = $r->layer();
        if ($this->root !== null) {
            $r->current = $current;
            $r->node($this->root, $m, $l, 1);
        }
        return $l->pix;
    }
}
