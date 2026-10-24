<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Text\{Boundaries, Layout, Params, System};

/**
 * Shaped text kept for a text control of an app's own (mygo's TextLayout,
 * Context::shapeText and shapeRichText): the system's fonts, fallback,
 * bidi and line breaking, with no editing of its own. Offsets are UTF-16
 * code units, as TextInputClient's; carets snap to whole graphemes. Keep
 * layouts of the paragraphs shown, and shape again those an edit changes.
 */
final class TextLayout
{
    /** Which of the two places of an index a caret takes, where bidi runs or a soft wrap give it two: the edge after the text before it, or before the text after it (mygo's TextDownstream, TextUpstream). */
    public const DOWNSTREAM = Layout::DOWNSTREAM, UPSTREAM = Layout::UPSTREAM;

    /** @var list<int> the UTF-16 offset of each rune, then of the end */
    private array $units = [0];
    /** @var list<int> whole-grapheme offsets in runes */
    private array $bounds;

    /** @param list<Span> $spans */
    private function __construct(public readonly Layout $layout, public readonly array $spans)
    {
        foreach ($layout->runes as $i => $r) {
            $this->units[$i + 1] = $this->units[$i] + ($r > 0xffff ? 2 : 1);
        }
        $b = new Boundaries;
        $b->reset($layout->runes, $layout->params->text);
        $this->bounds = $b->graphemeOffsets();
    }

    /**
     * $spans shaped in $font at $size points, wrapped at $width (0 breaks
     * at newlines only), aligned by $align (Node::START, CENTER, END)
     * within it; $rtl, as in an interface laid out right to left, lines
     * start at the right and paragraphs with no strong direction read
     * right to left (mygo's ShapeRichTextWith).
     * @param list<Span> $spans
     */
    public static function shape(System $sys, array $spans, TextStyle $font, float $size, float $width = 0, int $align = Node::START, bool $rtl = false): self
    {
        $text = ''; $runes = 0; $styles = [];
        foreach ($spans as $sp) {
            $text .= $sp->text;
            $runes += mb_strlen($sp->text, 'UTF-8');
            $styles[] = $sp->textSpan($runes);
        }
        $a = match (true) {
            $align === Node::CENTER => Params::CENTER,
            $rtl && $align === Node::END => Params::LEFT,
            $rtl => Params::RIGHT,
            $align === Node::END => Params::END,
            default => Params::START,
        };
        return new self($sys->layout(new Params($text, $font->style($size), $width, align: $a, rtl: $rtl, keepSpaces: true, spans: $styles)), $spans);
    }

    /** The layout's width and height, in points (mygo's Size). @return array{0: float, 1: float} */
    public function size(): array
    {
        return [$this->layout->width, $this->layout->height];
    }

    /** The caret at $index, snapped to a whole grapheme: [x, y, 1, h]. @return array{0: float, 1: float, 2: float, 3: float} */
    public function caret(int $index): array
    {
        [$x, $y, $h] = $this->layout->caret($this->runeAt($index, false));
        return [$x, $y, 1.0, $h];
    }

    /** The caret on the edge $affinity of $index: [x, y, 1, h] (mygo's CaretAt). @return array{0: float, 1: float, 2: float, 3: float} */
    public function caretAt(int $index, int $affinity = self::DOWNSTREAM): array
    {
        [$x, $y, $h] = $this->layout->caretAt($this->runeAt($index, false), $affinity);
        return [$x, $y, 1.0, $h];
    }

    /** The caret nearest ($x, $y), hit-testing whole graphemes: [index, affinity] (mygo's PositionAt). @return array{0: int, 1: int} */
    public function positionAt(float $x, float $y): array
    {
        [$i, $a] = $this->layout->positionAt($x, $y);
        return [$this->units[$i], $a];
    }

    /** The index of the caret nearest ($x, $y) (mygo's IndexAt). */
    public function indexAt(float $x, float $y): int
    {
        return $this->positionAt($x, $y)[0];
    }

    /** The caret one visual grapheme left ($direction < 0) or right of [$index, $affinity] (mygo's MoveCaret). @return array{0: int, 1: int} */
    public function moveCaret(int $index, int $affinity, int $direction): array
    {
        [$i, $a] = $this->layout->moveCaret($this->runeAt($index, false), $affinity, $direction);
        return [$this->units[$i], $a];
    }

    /** The logical ranges a visual selection from caret $anchor to caret $caret, each [index, affinity], covers, apart through mixed directions (mygo's SelectionRanges). @param array{0: int, 1: int} $anchor @param array{0: int, 1: int} $caret @return list<TextInputRange> */
    public function selectionRanges(array $anchor, array $caret): array
    {
        $out = [];
        foreach ($this->layout->selectionRanges([$this->runeAt($anchor[0], false), $anchor[1]], [$this->runeAt($caret[0], false), $caret[1]]) as [$a, $b]) {
            $out[] = new TextInputRange($this->units[$a], $this->units[$b]);
        }
        return $out;
    }

    /** The rectangles [x, y, w, h] of $ranges, apart where bidi runs are (mygo's SelectionRects). @return list<array{0: float, 1: float, 2: float, 3: float}> */
    public function selectionRects(TextInputRange ...$ranges): array
    {
        $out = [];
        foreach ($ranges as $r) {
            $r = $r->normalized();
            $out = $this->layout->appendSelection($out, $this->runeAt($r->start, false), $this->runeAt($r->end, true), false);
        }
        return $out;
    }

    /** The whole-grapheme offset before $index, for deleting and moving by graphemes (mygo's PreviousBoundary). */
    public function previousBoundary(int $index): int
    {
        $b = $this->search($this->runeAt($index, false));
        return $this->units[$this->bounds[max(0, $b - 1)]];
    }

    /** The whole-grapheme offset after $index (mygo's NextBoundary). */
    public function nextBoundary(int $index): int
    {
        $b = $this->search($this->runeAt($index, false));
        return $this->units[$this->bounds[min(count($this->bounds) - 1, $b + 1)]];
    }

    /** The inline objects (Span::$objectWidth), in the order of the text: the UTF-16 offset of each object replacement character and its box [x, y, w, h], standing on its line's baseline (mygo's Objects). @return list<array{0: int, 1: array{0: float, 1: float, 2: float, 3: float}}> */
    public function objects(): array
    {
        $out = [];
        foreach ($this->layout->lines as $line) {
            foreach ($line->glyphs as $g) {
                if (!$g->object) { continue; }
                $h = 0.0;
                foreach ($this->layout->params->spans as $sp) {
                    if ($g->cluster < $sp->end) { $h = $sp->objectHeight; break; }
                }
                $out[] = [$this->units[$g->cluster], [$g->x, $line->baseline - $h, $g->advance, $h]];
            }
        }
        return $out;
    }

    /** The rune of UTF-16 offset $index, on a whole grapheme: the one at or before it, or after it when $forward (mygo's runeAt). */
    private function runeAt(int $index, bool $forward): int
    {
        $index = max(0, min($index, $this->units[count($this->units) - 1]));
        $i = self::lowerBound($this->units, $index);
        if ($this->units[$i] !== $index && !$forward) { $i--; }
        $b = self::lowerBound($this->bounds, $i);
        if (($this->bounds[$b] ?? -1) !== $i) {
            $i = $forward ? $this->bounds[min($b, count($this->bounds) - 1)] : $this->bounds[max(0, $b - 1)];
        }
        return $i;
    }

    /** The place of grapheme start $i among the boundaries. */
    private function search(int $i): int
    {
        return self::lowerBound($this->bounds, $i);
    }

    /** The first index of sorted $a whose value is at least $v. @param list<int> $a */
    private static function lowerBound(array $a, int $v): int
    {
        $lo = 0; $hi = count($a);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($a[$mid] < $v) { $lo = $mid + 1; } else { $hi = $mid; }
        }
        return $lo;
    }
}
