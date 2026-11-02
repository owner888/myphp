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
        $fontStyle = ($style->bold() ? 1 : 0) | ($style->italic() ? 2 : 0);
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

    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
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

        $f = Android::ffi();
        $buf = $f->new('float[2048]');
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
            return [['start' => 0, 'end' => count($runes), 'runs' => [$run]]];
        }

        $count = (int)$buf[3];
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

        return [
            ['start' => 0, 'end' => count($runes), 'runs' => [$run]],
        ];
    }
}
