<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Native\Android;
use MyPHP\Text\{Engine, Font, Glyph, Span, Style};

/**
 * Text Engine for Android using minSdk 31 TextRunShaper & system fonts.
 */
final class AndroidTextEngine implements Engine
{
    /** @var array<int, array{size: float, style: int}> */
    private array $fontConfigs = [];
    /** @var array<string, Font> */
    private array $fonts = [];
    /** @var array<string, array> */
    private array $shapeCache = [];
    private mixed $shapeBuf = null;
    private int $nextFontId = 1;

    public function __construct(private Fonts $fontsPlatform) {}

    public function register(string $data, string $family): void {}

    public function fontFamilies(): array
    {
        return [[], [['sans-serif', false], ['monospace', true]]];
    }

    public function registerFontConfig(int $fontId, float $size, int $style): void
    {
        $this->fontConfigs[$fontId] = ['size' => $size, 'style' => $style];
    }

    /** @return ?array{size: float, style: int} */
    public function getFontConfig(int $fontId): ?array
    {
        return $this->fontConfigs[$fontId] ?? null;
    }

    public function font(Style $style): ?Font
    {
        $size = $style->fontSize();
        $weight = (int)max(100, min(900, round($style->weight() / 100) * 100));
        $fontStyle = ($weight >= 700 ? 1 : 0) | ($style->italic ? 2 : 0) | ($weight << 2); // the weight itself to the shaper (Roboto's 600, a semibold, too), bold and italic as before
        $key = "{$size}:{$fontStyle}";
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }

        $id = $this->nextFontId++;
        $this->fontConfigs[$id] = ['size' => $size, 'style' => $fontStyle];

        return $this->fonts[$key] = new Font(
            size: $size,
            ascent: $size * 0.8,
            descent: $size * 0.2,
            lineGap: 0.0,
            id: $id,
            underlineCenter: $size * 0.1,
            underlineThick: max(1.0, $size * 0.05),
            strikeCenter: -$size * 0.25,
            strikeThick: max(1.0, $size * 0.05),
        );
    }

    public function outline(Font $font, int $glyph): array
    {
        return [];
    }

    /**
     * $runes in lines of at most $width points (Engine::shape), broken
     * where a line may break: after spaces and hyphens, beside CJK
     * characters, or inside a word too long for a line unless
     * $wholeWords; each line shaped whole by TextRunShaper. Not mygo's.
     */
    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        if ($runes === [] || $width <= 0 || $this->widthOf($runes, $style, $rtl) <= $width) {
            return $this->line($runes, $style, $rtl);
        }
        $lines = [];
        $n = count($runes);
        $start = 0;
        while ($start < $n) {
            $end = $start; // the furthest break whose line fits
            for ($i = $start + 1; $i <= $n; $i++) {
                if ($i < $n && !self::breaksBefore($runes, $i)) { continue; }
                if ($this->widthOf(self::trimmed(array_slice($runes, $start, $i - $start)), $style, $rtl) > $width) { break; }
                $end = $i;
            }
            if ($end === $start) { // a word longer than the line: overflowing it whole, or broken where it no longer fits
                $next = $start + 1; // the word's end
                while ($next < $n && !self::breaksBefore($runes, $next)) { $next++; }
                $end = $start + 1;
                if ($wholeWords) {
                    $end = $next;
                } else {
                    while ($end < $next && $this->widthOf(array_slice($runes, $start, $end + 1 - $start), $style, $rtl) <= $width) { $end++; }
                }
            }
            foreach ($this->line(array_slice($runes, $start, $end - $start), $style, $rtl) as $l) {
                $l['start'] += $start; $l['end'] += $start;
                foreach ($l['runs'] as &$r) {
                    $r['start'] += $start; $r['end'] += $start;
                    foreach ($r['glyphs'] as $g) { $g->cluster += $start; } // the paragraph's rune, as the Engine's lines give it
                }
                unset($r);
                $lines[] = $l;
            }
            $start = $end;
        }
        return $lines;
    }

    /** Whether a line may break before rune $i: after a space or a hyphen, or beside a CJK character (UAX #14's main cases). */
    private static function breaksBefore(array $runes, int $i): bool
    {
        $prev = $runes[$i - 1]; $at = $runes[$i];
        if ($at === 0x20 || $at === 0x3000) { return false; } // spaces stay with the line before them
        if ($prev === 0x20 || $prev === 0x3000 || $prev === 0x2d || $prev === 0x2010) { return true; }
        return self::cjk($prev) || self::cjk($at);
    }

    private static function cjk(int $r): bool
    {
        return ($r >= 0x2e80 && $r <= 0x9fff) || ($r >= 0xac00 && $r <= 0xd7af) || ($r >= 0xf900 && $r <= 0xfaff) || ($r >= 0xff00 && $r <= 0xffef) || ($r >= 0x20000 && $r <= 0x2fa1f);
    }

    /** $runes without the spaces ending them, which hang past a line's end. */
    private static function trimmed(array $runes): array
    {
        while ($runes !== [] && ($runes[count($runes) - 1] === 0x20 || $runes[count($runes) - 1] === 0x3000)) { array_pop($runes); }
        return $runes;
    }

    /** How wide $runes are shaped on one line, in points. */
    private function widthOf(array $runes, Style $style, bool $rtl): float
    {
        $w = 0.0;
        foreach ($this->line($runes, $style, $rtl) as $l) {
            foreach ($l['runs'] as $r) { foreach ($r['glyphs'] as $g) { $w = max($w, $g->x + $g->advance); } }
        }
        return $w;
    }

    /** $runes shaped on one line, kept by their text and font (TextRunShaper). */
    private function line(array $runes, Style $style, bool $rtl): array
    {
        if ($runes === []) {
            return [];
        }

        $text = '';
        foreach ($runes as $r) {
            $text .= mb_chr($r, 'UTF-8');
        }

        $font = $this->font($style);
        $cfg = $this->fontConfigs[$font->id];
        $size = $cfg['size'];
        $fontStyle = $cfg['style'];

        $cacheKey = "{$font->id}:" . ($rtl ? '1:' : '0:') . $text;
        if (isset($this->shapeCache[$cacheKey])) {
            return self::copied($this->shapeCache[$cacheKey]);
        }
        if (count($this->shapeCache) > 2048) {
            $this->shapeCache = array_slice($this->shapeCache, -1024, null, true);
        }

        $f = Android::ffi();
        $buf = $this->shapeBuf ??= $f->new('float[2048]');
        $n = $f->myphp_shape_text($text, $size, $fontStyle, $buf, 2048);

        if ($n < 4) {
            $adv = $size * 0.5;
            $glyphs = [];
            $pen = 0.0;
            foreach ($runes as $idx => $r) {
                $glyphs[] = new Glyph($font, $r, $size, $pen, 0.0, $adv, $idx, $idx, $rtl);
                $pen += $adv;
            }
            $run = ['font' => $font, 'start' => 0, 'end' => count($runes), 'glyphs' => $glyphs];
            return self::copied($this->shapeCache[$cacheKey] = [['start' => 0, 'end' => count($runes), 'runs' => [$run]]]);
        }

        $ascent = (float)$buf[1];
        $descent = (float)$buf[2];
        if ($ascent > 0 && $descent >= 0) {
            $font = new Font(
                size: $size,
                ascent: $ascent,
                descent: $descent,
                lineGap: 0.0,
                id: $font->id,
                underlineCenter: $size * 0.1,
                underlineThick: max(1.0, $size * 0.05),
                strikeCenter: -$ascent * 0.35,
                strikeThick: max(1.0, $size * 0.05),
            );
            $this->fonts["{$size}:{$fontStyle}"] = $font;
        }

        $count = min((int)$buf[3], intdiv($n - 4, 3));
        $glyphs = [];

        for ($i = 0; $i < $count; $i++) {
            $gid = (int)$buf[4 + $i * 3];
            $gx = (float)$buf[4 + $i * 3 + 1];
            $gy = (float)$buf[4 + $i * 3 + 2];
            $nextX = ($i + 1 < $count) ? (float)$buf[4 + ($i + 1) * 3 + 1] : (float)$buf[0];
            $adv = max(0.0, $nextX - $gx);

            $glyphs[] = new Glyph($font, $gid, $size, $gx, $gy, $adv, $i, 0, $rtl);
        }

        $run = [
            'font' => $font,
            'start' => 0,
            'end' => count($runes),
            'glyphs' => $glyphs,
        ];

        return self::copied($this->shapeCache[$cacheKey] = [
            ['start' => 0, 'end' => count($runes), 'runs' => [$run]],
        ]);
    }

    /**
     * The lines of the cache with glyphs of their own: Text\System lays a line out by moving its glyphs (onto its
     * baseline, from its start), so that the cache's, given again, would be moved again, the text sinking a baseline
     * at each layout (the tab bar's labels went from 8 to 16 points down). CoreText's and Pango's shape anew each time.
     * @param list<array{start: int, end: int, runs: list<array{glyphs: list<Glyph>}>}> $lines
     */
    private static function copied(array $lines): array
    {
        foreach ($lines as &$line) {
            foreach ($line['runs'] as &$run) { $run['glyphs'] = array_map(static fn(Glyph $g) => clone $g, $run['glyphs']); }
            unset($run);
        }
        unset($line);
        return $lines;
    }
}
