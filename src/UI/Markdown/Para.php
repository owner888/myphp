<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/**
 * What a paragraph, heading or cell shows (mygo's para): the spans of its
 * text, cut into pieces at its links, which are nodes of their own inside
 * it, with where each span's text is in the source, for copying.
 */
final class Para
{
    /** @var list<array{0: int, 1: list<\MyPHP\UI\Span>}> the pieces: a link's (an index into the block's inlines, below -1 a footnote reference's, -2 - its index) when at least 0, else plain text (-1), and their spans */
    public array $pieces = [];
    /** @var list<array{0: int, 1: int, 2: int, 3: bool}> that the text from a rune comes from the source's bytes [src, end): exactly those bytes, else written otherwise there, as an entity or an escape (mygo's runMark) */
    public array $marks = [];
    public int $runes = 0;
    /** @var list<array{0: int, 1: int, 2: int, 3: ?\Closure}> its math Options::$inlineMath made objects of, in order: the rune of each, where its source is, delimiters included [src, end), and how it draws (mygo's mathObject) */
    public array $objects = [];
    /** Its spans fade in: built anew next frame. */
    public bool $fading = false;
    /** The width of its text on one line, once measured. */
    public float $width = 0;
    public bool $measured = false;

    /**
     * The source offset of rune $r of the text: for the end of a selection
     * ($end), that of the text before it, so that what follows, as an
     * emphasis's opening *, stays out (mygo's sourceAt).
     */
    public function sourceAt(string $src, int $r, bool $end): int
    {
        if ($this->marks === []) {
            return 0;
        }
        $lo = 0; $hi = count($this->marks);
        while ($lo < $hi) {
            $m = intdiv($lo + $hi, 2);
            if ($this->marks[$m][0] <= $r) { $lo = $m + 1; } else { $hi = $m; }
        }
        $k = max($lo - 1, 0);
        [$rune, $at, $to, $exact] = $this->marks[$k];
        if ($r <= $rune) {
            return $end && $k > 0 ? $this->marks[$k - 1][2] : $at;
        }
        if (!$exact) {
            return $to;
        }
        $off = $at;
        for ($n = $rune; $n < $r && $off < $to; $n++) {
            $b = ord($src[$off]);
            $off += $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
        }
        return $off;
    }
}
