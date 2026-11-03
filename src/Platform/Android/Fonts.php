<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use FFI;
use MyPHP\Native\Android;
use MyPHP\Native\Mem;
use MyPHP\Platform;
use MyPHP\Text\Engine;

/**
 * Android system font shaping & rasterization (minSdk 31, TextRunShaper + Canvas.drawGlyphs).
 */
final class Fonts implements Platform\Fonts
{
    private float $scale = 1.0;
    private ?AndroidTextEngine $engine = null;
    /** @var array<string, int> */
    private array $fontMap = [];
    private int $nextFontId = 1000;

    public function __construct(float $scale = 1.0)
    {
        $this->scale = $scale;
    }

    public function engine(): Engine
    {
        return $this->engine ??= new AndroidTextEngine($this);
    }

    public function scale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): void
    {
        $this->scale = $scale;
    }

    public function positions(float $px): int
    {
        return 1;
    }

    public function baseline(float $y): float
    {
        return round($y);
    }

    public function isColor(int $fontId): bool
    {
        return false;
    }

    public function shape(string $text, float $size, int $style = 0): array
    {
        if ($text === '') {
            return ['width' => 0.0, 'ascent' => 0.0, 'descent' => 0.0, 'glyphs' => [], 'carets' => [0.0]];
        }

        $f = Android::ffi();
        $buf = $f->new('float[4096]');
        $n = $f->myphp_shape_text($text, $size, $style, $buf, 4096);

        if ($n < 4) {
            $w = mb_strlen($text) * $size * 0.5;
            return ['width' => $w, 'ascent' => $size * 0.8, 'descent' => $size * 0.2, 'glyphs' => [], 'carets' => [0.0, $w]];
        }

        $width = (float)$buf[0];
        $ascent = (float)$buf[1];
        $descent = (float)$buf[2];
        $count = min((int)$buf[3], intdiv($n - 4, 3));

        $fontKey = sprintf('%.2f:%d', $size, $style);
        if (!isset($this->fontMap[$fontKey])) {
            $fontId = $this->nextFontId++;
            $this->fontMap[$fontKey] = $fontId;
            $eng = $this->engine();
            if ($eng instanceof AndroidTextEngine) {
                $eng->registerFontConfig($fontId, $size, $style);
            }
        } else {
            $fontId = $this->fontMap[$fontKey];
        }

        // Map each rune to its UTF-16 code unit index
        $u16 = []; $u = 0;
        $runes = \MyPHP\Text\System::runes($text);
        foreach ($runes as $r) {
            $u16[] = $u;
            $u += ($r >= 0x10000) ? 2 : 1;
        }
        $totalU16 = $u;

        $glyphs = [];
        $carets = [0 => 0.0];

        for ($i = 0; $i < $count; $i++) {
            $gid = (int)$buf[4 + $i * 3];
            $x = (float)$buf[4 + $i * 3 + 1];
            $y = (float)$buf[4 + $i * 3 + 2];
            $uIdx = $u16[$i] ?? $i;
            $glyphs[] = ['font' => $fontId, 'glyph' => $gid, 'x' => $x, 'y' => $y, 'index' => $uIdx];
            $carets[$uIdx] = $x;
        }

        $carets[$totalU16] = $width;

        return [
            'width' => $width,
            'ascent' => $ascent,
            'descent' => $descent,
            'glyphs' => $glyphs,
            'carets' => $carets,
        ];
    }

    public function rasterize(int $fontId, int $glyph, ?float $scale = null, int $shade = -1, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false, float $dx = 0.0): ?array
    {
        $engine = $this->engine();
        $cfg = ($engine instanceof AndroidTextEngine ? $engine->getFontConfig($fontId) : null) ?? ['size' => 16.0, 'style' => 0];
        $s = $scale ?? $this->scale;
        $targetPx = $cfg['size'] * $s;

        $f = Android::ffi();
        $metrics = $f->new('int[4]');
        $pixels = $f->new('uint8_t[262144]');

        $len = $f->myphp_rasterize_glyph($glyph, $targetPx, $cfg['style'], $metrics, $pixels, 262144);

        $w = (int)$metrics[0];
        $h = (int)$metrics[1];
        $ox = (int)$metrics[2];
        $oy = (int)$metrics[3];

        if ($len <= 0 || $w <= 0 || $h <= 0) {
            return null;
        }

        $rows = Mem::alloc($len);
        FFI::memcpy($rows, $pixels, $len);

        return [
            'w' => $w,
            'h' => $h,
            'ox' => $ox,
            'oy' => $oy,
            'rows' => $rows,
            'addr' => Mem::addr($rows),
            'color' => false,
        ];
    }
}
