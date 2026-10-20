<?php
declare(strict_types=1);

namespace MyPHP\Text;

/** A line of a Layout (mygo's text.Line). */
final class Line
{
    /** @var list<Glyph> */
    public array $glyphs = [];
    /** @var ?list<float> the x of every caret position from start to end, made the first time */
    private ?array $carets = null;
    /** @var ?list<array{0: int, 1: int, 2: float, 3: int}> the visual caret stops: index, affinity, x, arrival */
    public ?array $stops = null;
    /** @var ?list<float> */
    public ?array $upstream = null;

    public function __construct(
        /** The line's box; $x includes the alignment. */
        public float $x = 0,
        public float $y = 0,
        public float $width = 0,
        public float $height = 0,
        /** The y of the baseline; ascent and descent the extent of the fonts above and below it. */
        public float $baseline = 0,
        public float $ascent = 0,
        public float $descent = 0,
        /** The line's runes; a newline ending it is not included. */
        public int $start = 0,
        public int $end = 0,
        /** A right-to-left paragraph. */
        public bool $rtl = false,
    ) {}

    /**
     * The x of every caret position of the line, from start to end:
     * inside a cluster of several runes (a ligature) the positions split
     * the cluster evenly (mygo's lineCarets).
     *
     * @return list<float>
     */
    public function carets(): array
    {
        if ($this->carets !== null) {
            return $this->carets;
        }
        $clusters = [];
        $gs = $this->glyphs; $ng = count($gs);
        for ($g = 0; $g < $ng;) {
            $first = $gs[$g];
            $c = [$first->x, $first->x + $first->advance, $first->cluster, $first->runes, $first->rtl];
            $g++;
            while ($g < $ng && $gs[$g]->cluster === $first->cluster && $gs[$g]->runes === $first->runes) {
                $c[0] = min($c[0], $gs[$g]->x);
                $c[1] = max($c[1], $gs[$g]->x + $gs[$g]->advance);
                $g++;
            }
            if ($c[3] > 0) {
                $clusters[] = $c;
            }
        }
        $pos = static function (array $c, int $k): float {
            $f = $k / $c[3];
            return $c[4] ? $c[1] - ($c[1] - $c[0]) * $f : $c[0] + ($c[1] - $c[0]) * $f;
        };
        $n = $this->end - $this->start + 1;
        $carets = array_fill(0, $n, 0.0);
        $set = array_fill(0, $n, false);
        // A cluster's start takes precedence over the end of the cluster before it, which differ in bidirectional text.
        foreach ($clusters as $c) {
            for ($k = 0; $k < $c[3]; $k++) {
                $i = $c[2] + $k - $this->start;
                if ($i >= 0 && $i < $n) {
                    $carets[$i] = $pos($c, $k); $set[$i] = true;
                }
            }
        }
        foreach ($clusters as $c) {
            $i = $c[2] + $c[3] - $this->start;
            if ($i >= 0 && $i < $n && !$set[$i]) {
                $carets[$i] = $pos($c, $c[3]); $set[$i] = true;
            }
        }
        $end = $this->rtl ? $this->x : $this->x + $this->width;
        for ($i = $n - 1; $i >= 0; $i--) {
            if (!$set[$i]) {
                $carets[$i] = $end;
                if ($i + 1 < $n && $set[$i + 1]) {
                    $carets[$i] = $carets[$i + 1]; // a trimmed space before the next cluster
                }
                $set[$i] = true;
            }
        }
        return $this->carets = $carets;
    }
}
