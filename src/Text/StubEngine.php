<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * Lays out text without fonts (mygo's stubEngine): every rune takes half
 * an em, lines are an em high, ascent 0.8 of it, and nothing draws; lines
 * break after spaces where a width wraps them. A glyph's id is its rune;
 * the outlines of g, j, p, q and y are a bar from the x-height down to
 * the descent across the middle of their advance, which underlines skip.
 * The tests' engine.
 */
final class StubEngine implements Engine
{
    /** @var array<string, Font> */
    private array $fonts = [];
    /** @var list<array{0: string, 1: bool}> the families registered: any data but none is a font here */
    private array $registered = [];
    /** The system's families the tests see. */
    public const SYSTEM = [['Helvetica', false], ['Menlo', true], ['Helvetica', false], ['.Hidden', false]];

    public function register(string $data, string $family): void
    {
        if ($data === '') {
            throw new \RuntimeException('no font data');
        }
        $this->registered[] = [$family !== '' ? $family : 'Stub Sans', str_contains($data, 'mono')];
    }

    public function fontFamilies(): array
    {
        return [$this->registered, array_values(array_filter(self::SYSTEM, fn(array $f): bool => $f[0][0] !== '.'))];
    }

    public function font(Style $style): ?Font
    {
        $size = $style->fontSize();
        return $this->fonts[(string)$size] ??= new Font($size, $size * 0.8, $size * 0.2, 0, 0, $size * 0.1, $size * 0.05, -$size * 0.25, $size * 0.05);
    }

    public function outline(Font $font, int $glyph): array
    {
        if (!in_array($glyph, [0x67, 0x6A, 0x70, 0x71, 0x79], true)) {
            return [];
        }
        $s = $font->size; [$x0, $x1, $y0, $y1] = [0.15 * $s, 0.35 * $s, -$font->descent, 0.5 * $s];
        return [[$x0, $y0, $x1, $y0], [$x1, $y0, $x1, $y1], [$x1, $y1, $x0, $y1], [$x0, $y1, $x0, $y0]];
    }

    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        $n = count($runes);
        $sizes = []; $objects = []; // an inline object's rune is as wide as the object
        $from = 0;
        foreach ($spans as $sp) {
            $size = $sp->style($style)->fontSize();
            for ($i = $from; $i < min($sp->end, $n); $i++) { $sizes[$i] = $size; if ($sp->objectWidth > 0) { $objects[$i] = $sp->objectWidth; } }
            $from = max($from, $sp->end);
        }
        $lines = [];
        $start = 0;
        while ($start < $n || $lines === []) {
            $x = 0.0; $end = $start; $lastBreak = -1;
            while ($end < $n) {
                $adv = $objects[$end] ?? ($sizes[$end] ?? $style->fontSize()) / 2;
                if ($width > 0 && $x + $adv > $width && $end > $start && $runes[$end] !== 0x20) {
                    if ($lastBreak > $start) {
                        $end = $lastBreak;
                    } elseif ($wholeWords) {
                        while ($end < $n && $runes[$end] !== 0x20) { $end++; }
                    }
                    break;
                }
                $x += $adv;
                $end++;
                if ($runes[$end - 1] === 0x20 || $runes[$end - 1] === 0xAD) { // after a space, or a soft hyphen
                    $lastBreak = $end;
                }
            }
            $runs = [];
            $f = null; $run = null; $pen = 0.0;
            for ($i = $start; $i < $end; $i++) {
                $font = $this->font($style->with(['size' => $sizes[$i] ?? $style->fontSize()]));
                if ($font !== $f) {
                    if ($run !== null) { $runs[] = $run; }
                    $run = ['font' => $font, 'start' => $i, 'end' => $i, 'glyphs' => []];
                    $f = $font;
                }
                $adv = $objects[$i] ?? $font->size / 2;
                $run['glyphs'][] = new Glyph($font, $runes[$i], $font->size, $pen, 0, $adv, $i, 0, $rtl);
                $run['end'] = $i + 1;
                $pen += $adv;
            }
            if ($run !== null) { $runs[] = $run; }
            $lines[] = ['start' => $start, 'end' => $end, 'runs' => $runs];
            if ($end === $start) {
                break;
            }
            $start = $end;
        }
        return $lines;
    }
}
