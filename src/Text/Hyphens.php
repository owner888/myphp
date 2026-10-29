<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * The hyphens that soft hyphens (U+00AD) show as where lines break, in the
 * style of each, shaped once a paragraph (mygo's text hyphens): a soft
 * hyphen is a place a line may break at, which shows as a hyphen there and
 * as nothing elsewhere, whatever the engine makes of it.
 *
 * @internal System
 */
final class Hyphens
{
    public const SOFT = 0xAD;

    /** @var array<int, list<Glyph>> the hyphen of each span, by its index, count($spans) for the text's style */
    private array $glyphs = [];
    /** @var ?array<int, true> where the paragraph's lines may break, once asked */
    private ?array $opps = null;

    /** @param list<Span> $spans */
    public function __construct(private readonly Engine $e, private readonly Style $style, private readonly array $spans) {}

    /** Whether a line of paragraph $text may break before rune $i (LineBreaks). @param list<int> $text */
    public function opportunity(array $text, int $i): bool
    {
        $this->opps ??= LineBreaks::at($text);
        return isset($this->opps[$i]);
    }

    /** The glyphs of the hyphen of rune $i, from a pen at 0. @return list<Glyph> */
    private function at(int $i): array
    {
        $k = count($this->spans);
        foreach ($this->spans as $j => $sp) {
            if ($i < $sp->end) { $k = $j; break; }
        }
        if (isset($this->glyphs[$k])) {
            return $this->glyphs[$k];
        }
        $style = $k < count($this->spans) ? $this->spans[$k]->style($this->style) : $this->style;
        // U+2010 HYPHEN would be the better glyph, but fonts lack it more often than U+002D, which every one has.
        $out = [];
        foreach ($this->e->shape([0x2D], $style, [], 0, false, false) as $l) {
            foreach ($l['runs'] as $r) {
                foreach ($r['glyphs'] as $g) { $out[] = $g; }
            }
        }
        return $this->glyphs[$k] = $out;
    }

    /** The advance of the hyphen of rune $i. */
    public function width(int $i): float
    {
        $w = 0.0;
        foreach ($this->at($i) as $g) { $w += $g->advance; }
        return $w;
    }

    /** The width of shaped line $l of paragraph $text ending with soft hyphen $c, once its hyphen shows and its other soft hyphens take no room. @param list<int> $text */
    public function lineWidth(array $l, array $text, int $c): float
    {
        $w = $this->width($c);
        foreach ($l['runs'] as $r) {
            foreach ($r['glyphs'] as $g) {
                if ($g->cluster < 0 || $g->cluster >= count($text) || $text[$g->cluster] !== self::SOFT) { $w += $g->advance; }
            }
        }
        return $w;
    }

    /**
     * Shaped line $l of paragraph $text with its soft hyphens showing as
     * Unicode says: a hyphen for the one the line breaks after, when
     * $broken, and nothing, taking no room, for the others; the glyphs
     * after one move by the room it takes or gives. $rtl is the
     * paragraph's direction, whose end a hyphen goes to when the engine
     * gave its soft hyphen no glyph.
     * @param list<int> $text
     */
    public function place(array $l, array $text, bool $broken, bool $rtl): array
    {
        $placed = false;
        $n = count($text);
        foreach ($l['runs'] as $ri => $run) {
            $gs = $l['runs'][$ri]['glyphs'];
            for ($j = 0; $j < count($gs); $j++) {
                $c = $gs[$j]->cluster;
                if ($c < 0 || $c >= $n || $text[$c] !== self::SOFT) {
                    continue;
                }
                // Its cluster's glyphs, as the empty glyph and the hyphen an engine gives one it breaks at.
                $k = $j + 1;
                while ($k < count($gs) && $gs[$k]->cluster === $c) { $k++; }
                [$left, $was] = [$gs[$j]->x, 0.0];
                for ($q = $j; $q < $k; $q++) { $left = min($left, $gs[$q]->x); $was += $gs[$q]->advance; }
                $glyphRTL = $gs[$j]->rtl;
                [$now, $with] = [0.0, []];
                if ($broken && $c === $l['end'] - 1) {
                    [$with, $now] = [$this->glyphsAt($c, $left, $glyphRTL), $this->width($c)];
                    $placed = true;
                }
                if ($with === []) { // a glyph of no font draws nothing, and keeps the caret's place
                    $with = [new Glyph(null, 0, $gs[$j]->size, $left, $gs[$j]->y, 0.0, $c, 0, $glyphRTL)];
                }
                if (($d = $now - $was) != 0) {
                    $mine = array_slice($gs, $j, $k - $j);
                    foreach ($l['runs'] as $rk => $other) {
                        foreach ($rk === $ri ? $gs : $other['glyphs'] as $g) {
                            if (!in_array($g, $mine, true) && $g->x >= $left + $was / 2) { $g->x += $d; }
                        }
                    }
                }
                array_splice($gs, $j, $k - $j, $with);
                $l['runs'][$ri]['glyphs'] = $gs;
                $j += count($with) - 1;
            }
        }
        if ($broken && !$placed && $l['runs'] !== []) { // the engine left the soft hyphen ending the line out, as a character nothing shows: its hyphen ends the line
            $c = $l['end'] - 1;
            [$x0, $x1] = [INF, -INF];
            foreach ($l['runs'] as $r) {
                foreach ($r['glyphs'] as $g) { $x0 = min($x0, $g->x); $x1 = max($x1, $g->x + $g->advance); }
            }
            $x = $x0 > $x1 ? 0.0 : ($rtl ? $x0 - $this->width($c) : $x1);
            $ri = count($l['runs']) - 1; // in the run of the soft hyphen, whose clusters end at it
            foreach ($l['runs'] as $i => $r) {
                if ($r['start'] <= $c && $c < $r['end']) { $ri = $i; }
            }
            array_push($l['runs'][$ri]['glyphs'], ...$this->glyphsAt($c, $x, $rtl));
        }
        return $l;
    }

    /** The glyphs of the hyphen of rune $i, standing for it, from a pen at $x. @return list<Glyph> */
    private function glyphsAt(int $i, float $x, bool $rtl): array
    {
        $out = [];
        foreach ($this->at($i) as $g) {
            $h = clone $g;
            $h->x += $x; $h->cluster = $i; $h->rtl = $rtl;
            $out[] = $h;
        }
        return $out;
    }

    /** The soft hyphen shaped line $l of paragraph $text breaks after, or -1 when it ends otherwise (mygo's softBreak). @param list<int> $text */
    public static function softBreak(array $l, array $text): int
    {
        return $l['end'] > $l['start'] && $l['end'] < count($text) && $text[$l['end'] - 1] === self::SOFT ? $l['end'] - 1 : -1;
    }
}
