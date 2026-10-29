<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The text of a canvas's painter: the window's, a pixel a point, as the canvas's units are its pixels. @internal */
final class CanvasText implements TextPainter
{
    public function __construct(private readonly TextPainter $t) {}

    public function measure(string $text, float $size, int $style = 0): array { return $this->t->measure($text, $size, $style); }
    public function minContentWidth(string $text, float $size, int $style = 0): float { return $this->t->minContentWidth($text, $size, $style); }
    public function ascent(float $size, int $style = 0): float { return $this->t->ascent($size, $style); }
    public function xForIndex(string $text, float $size, int $byte, int $style = 0): float { return $this->t->xForIndex($text, $size, $byte, $style); }
    public function indexForX(string $text, float $size, float $x, int $style = 0): int { return $this->t->indexForX($text, $size, $x, $style); }
    public function textSystem(): \MyPHP\Text\System { return $this->t->textSystem(); }
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0, int $style = 0): void { $this->t->paint($list, $text, $size, $x, $y, $rgb, $alpha, $style); }
    public function paintRuns(DisplayList $list, string $text, float $size, float $x, float $y, int $style, \Closure $colorAt): void { $this->t->paintRuns($list, $text, $size, $x, $y, $style, $colorAt); }
    public function paintLayout(DisplayList $list, \MyPHP\Text\Layout $l, float $x, float $y, \Closure $colorAt, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false): void { $this->t->paintLayout($list, $l, $x, $y, $colorAt, $stroke, $blur, $cutOut); }
    public function glyphCoverage(array $glyphs, float $x, float $y, int $w, int $h, float $scale): string { return $this->t->glyphCoverage($glyphs, $x, $y, $w, $h, $scale); }
    public function imageSlot(Image $image): array { return $this->t->imageSlot($image); }
    public function iconQuad(SVG $svg, float $x, float $y, float $w, float $h, float $rotate = 0.0): ?array { return $this->t->iconQuad($svg, $x, $y, $w, $h, $rotate); }
    public function svgSlot(SVG $svg, int $w, int $h, int $current): array { return $this->t->svgSlot($svg, $w, $h, $current); }
    public function scale(): float { return 1.0; }
    public function drawnMark(): int { return $this->t->drawnMark(); }
    public function drawnSince(int $mark): array { return $this->t->drawnSince($mark); }
    public function drawnAgain(array $drawn): bool { return $this->t->drawnAgain($drawn); }
}
