<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\{Boundaries, Layout, Params, Style, System};
use MyPHP\UI\{DisplayList, Painter, Span, TextPainter};

/**
 * Spans shaped by the text system, kept to paint and to ask where carets
 * are (mygo's ui.TextLayout of ShapeRichTextWith): offsets are runes of
 * the layout, which the editor's paragraph and its composition make.
 *
 * @internal
 */
final class ParaLayout
{
    /** @var ?list<int> whole-grapheme offsets */
    private ?array $bounds = null;

    /** @param list<Span> $spans */
    private function __construct(public readonly Layout $l, public readonly array $spans) {}

    /**
     * $spans shaped in the font of $family, $size and $weight, wrapped at
     * $width and aligned by $align (Params::START, CENTER or END), keeping
     * the spaces at the ends of lines, as an editor does.
     *
     * @param list<Span> $spans
     */
    public static function shape(System $sys, array $spans, string $family, float $size, int $weight, float $width, int $align, float $indent = 0): self
    {
        $text = ''; $runes = 0; $styles = [];
        foreach ($spans as $sp) {
            $text .= $sp->text;
            $runes += mb_strlen($sp->text, 'UTF-8');
            $styles[] = $sp->textSpan($runes);
        }
        $p = new Params($text, new Style($family, $size, $weight), $width, align: $align, keepSpaces: true, spans: $styles, indent: $indent); // the indent on the side the paragraph starts at, the right of one reading right to left
        return new self($sys->layout($p), $spans);
    }

    /** The layout's extent. @return array{0: float, 1: float} */
    public function size(): array { return [$this->l->width, $this->l->height]; }

    /** The number of runes laid out. */
    public function len(): int { return count($this->l->runes); }

    /** The caret at rune $i on the edge $affinity takes: its x, the top of its line and the line's height. @return array{0: float, 1: float, 2: float} */
    public function caretAt(int $i, int $affinity = Layout::DOWNSTREAM): array { return $this->l->caretAt($i, $affinity); }

    /** The caret position at ($x, $y): its rune and affinity. @return array{0: int, 1: int} */
    public function positionAt(float $x, float $y): array { return $this->l->positionAt($x, $y); }

    /** The caret a grapheme to the right ($dir > 0) or left on screen. @return array{0: int, 1: int} */
    public function moveCaret(int $i, int $affinity, int $dir): array { return $this->l->moveCaret($i, $affinity, $dir); }

    /** The rectangles, [x, y, w, h], of the runes from $a to $b. @return list<array{0: float, 1: float, 2: float, 3: float}> */
    public function selectionRects(int $a, int $b): array { return $this->l->selection($a, $b); }

    /** The whole-grapheme offset before rune $i, for deleting and moving by graphemes (mygo's PreviousBoundary). */
    public function previousBoundary(int $i): int
    {
        $b = $this->boundaries();
        $k = $this->search($b, $this->snap($i));
        return $b[max(0, $k - 1)];
    }

    /** The whole-grapheme offset after rune $i (mygo's NextBoundary). */
    public function nextBoundary(int $i): int
    {
        $b = $this->boundaries();
        $k = $this->search($b, $this->snap($i));
        return $b[min(count($b) - 1, $k + 1)];
    }

    /** $i, or the grapheme offset before it when it is inside a grapheme. */
    private function snap(int $i): int
    {
        $b = $this->boundaries();
        $i = max(0, min($i, $this->len()));
        $k = $this->search($b, $i);
        return $k < count($b) && $b[$k] === $i ? $i : $b[max(0, $k - 1)];
    }

    /** The first index of $b not below $i. @param list<int> $b */
    private function search(array $b, int $i): int
    {
        $lo = 0; $hi = count($b);
        while ($lo < $hi) {
            $m = intdiv($lo + $hi, 2);
            if ($b[$m] < $i) { $lo = $m + 1; } else { $hi = $m; }
        }
        return $lo;
    }

    /** @return list<int> */
    private function boundaries(): array
    {
        if ($this->bounds === null) {
            $bd = new Boundaries;
            $bd->reset($this->l->runes, $this->l->params->text);
            $this->bounds = $bd->graphemeOffsets();
        }
        return $this->bounds;
    }

    /**
     * The inline objects of the layout, in the order of the text (mygo's
     * Objects): the rune of each object replacement character and its box,
     * [x, y, w, h], standing on its line's baseline.
     *
     * @return list<array{0: int, 1: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public function objects(): array
    {
        return \MyPHP\UI\TextLayout::objectBoxes($this->l);
    }

    /** Paints the layout at ($x, $y), $rgb at $alpha the color of what its spans leave uncolored (mygo's Painter.TextLayout). */
    public function paint(DisplayList $list, TextPainter $text, float $x, float $y, int $rgb, float $alpha): void
    {
        Painter::paintSpans($list, $text, $this->l, $this->spans, $x, $y, $rgb, $alpha);
    }
}
