<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The lines a rich text's spans break into at a width: greedy, at spaces
 * and between CJK characters, a chunk wider than the line broken by
 * character. Each line has its pieces (a span's text or part of it with
 * its x and width), its height and the ascent its baseline sits at.
 */
final class RichLayout
{
    /** @var list<array{pieces: list<array{0: Span, 1: string, 2: float, 3: float}>, h: float, ascent: float}> */
    public array $lines = [];
    public float $w = 0;
    public float $h = 0;

    /** Splits text into wrap units: runs of spaces, single CJK characters, other runs. */
    private const CHUNK = '/\s+|[\x{3000}-\x{9fff}\x{ac00}-\x{d7af}\x{ff00}-\x{ffef}]|[^\s\x{3000}-\x{9fff}\x{ac00}-\x{d7af}\x{ff00}-\x{ffef}]+/u';

    /** @param Span[] $spans */
    public static function wrap(TextMetrics $m, array $spans, float $size, float $width): self
    {
        $l = new self();
        $line = ['pieces' => [], 'h' => 0.0, 'ascent' => 0.0];
        $x = 0.0;
        $place = function (Span $sp, string $text, float $w) use (&$line, &$x, $m, $size) {
            $s = $sp->size ?? $size;
            [, $h] = $m->measure('X', $s, $sp->style);
            $last = count($line['pieces']) - 1;
            if ($last >= 0 && $line['pieces'][$last][0] === $sp) { // the same span goes on: one piece, shaped as one run
                $line['pieces'][$last][1] .= $text;
                $line['pieces'][$last][3] += $w;
            } else {
                $line['pieces'][] = [$sp, $text, $x, $w];
            }
            $line['h'] = max($line['h'], $h);
            $line['ascent'] = max($line['ascent'], $m->ascent($s, $sp->style));
            $x += $w;
        };
        $break = function () use (&$line, &$x, $l, $size, $m) {
            if ($line['pieces'] === []) { // an empty line still has a height
                [, $line['h']] = $m->measure('X', $size);
                $line['ascent'] = $m->ascent($size);
            }
            $l->lines[] = $line;
            $l->w = max($l->w, $x);
            $line = ['pieces' => [], 'h' => 0.0, 'ascent' => 0.0];
            $x = 0.0;
        };
        foreach ($spans as $sp) {
            $s = $sp->size ?? $size;
            foreach (explode("\n", $sp->text) as $p => $para) {
                if ($p > 0) {
                    $break();
                }
                preg_match_all(self::CHUNK, $para, $mm);
                foreach ($mm[0] as $chunk) {
                    [$w] = $m->measure($chunk, $s, $sp->style);
                    if ($x > 0 && $x + $w > $width && trim($chunk) !== '') {
                        $break(); // a space may hang past the edge; a word may not
                    }
                    if ($w > $width && $x === 0.0) { // wider than a line by itself: by character
                        foreach (preg_split('//u', $chunk, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                            [$cw] = $m->measure($ch, $s, $sp->style);
                            if ($x > 0 && $x + $cw > $width) {
                                $break();
                            }
                            $place($sp, $ch, $cw);
                        }
                        continue;
                    }
                    $place($sp, $chunk, $w);
                }
            }
        }
        $break();
        foreach ($l->lines as $ln) {
            $l->h += $ln['h'];
        }
        return $l;
    }
}
