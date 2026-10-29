<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Media\Ass;
use MyPHP\Media\AssLine;
use MyPHP\Media\AssRun;
use MyPHP\Media\PlayerSettings;

/**
 * The style IINA's settings give subtitles (mpv's sub-font, sub-font-size,
 * sub-color, sub-border-*, sub-shadow-*, sub-align-*, sub-margin-*, sub-bold,
 * sub-italic, sub-spacing, sub-border-style), as mpv applies it: a text
 * subtitle's lines drawn as ASS lines of a Default style of them, on a
 * plane 720 high (the height mpv's "scaled pixels" are of); an ASS track's
 * lines given it too where sub-ass-override forces it.
 *
 * @internal VideoPlayer
 */
final class VideoPlayerSubtitleStyle
{
    /** The height mpv's sizes of subtitles are of (its scaled pixels). */
    public const HEIGHT = 720;

    /** @var array<string, Ass> the scripts of the styles made, by what made them */
    private static array $scripts = [];

    /** A color as IINA keeps it, "r/g/b/a" of 0 to 1 (its mpvColorString): [0xRRGGBB, alpha]. @return array{0: int, 1: float} */
    public static function color(string $s): array
    {
        $p = array_map('floatval', explode('/', $s)) + [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 1.0];
        $byte = fn(float $v): int => (int)round(max(0.0, min(1.0, $v)) * 255);
        return [($byte($p[0]) << 16) | ($byte($p[1]) << 8) | $byte($p[2]), max(0.0, min(1.0, $p[3]))];
    }

    /** [0xRRGGBB, alpha] as IINA keeps a color. */
    public static function colorString(int $rgb, float $alpha): string
    {
        $f = fn(int $v): string => rtrim(rtrim(sprintf('%.6f', $v / 255), '0'), '.') ?: '0';
        return $f(($rgb >> 16) & 0xff) . '/' . $f(($rgb >> 8) & 0xff) . '/' . $f($rgb & 0xff) . '/' . (rtrim(rtrim(sprintf('%.6f', $alpha), '0'), '.') ?: '0');
    }

    /**
     * Text subtitle $texts as ASS lines of the settings' style, on a plane
     * of $aspect (its width by its height, 720), its sizes times $scale
     * (sub-scale-by-window's); at the top, of the alignment's column, for
     * the secondary subtitles (mpv's toptitles).
     * @param list<string> $texts
     * @return list<AssLine>
     */
    public static function lines(PlayerSettings $set, array $texts, float $aspect, float $scale, bool $secondary = false): array
    {
        $ass = self::script($set, $aspect, $scale, $secondary);
        $out = [];
        foreach ($texts as $text) {
            $l = $ass->line($text, 'Default', plain: true);
            if ($l !== null) { $out[] = $l; }
        }
        return $out;
    }

    /**
     * ASS line $l given the settings' style whole, as sub-ass-override's
     * force: its font, size, colors, outline, shadow, weight, slant,
     * spacing and box, its alignment and margins (unless \pos places it),
     * at its own plane's scale; what its tags scaled and turned kept.
     */
    public static function forced(PlayerSettings $set, AssLine $l, float $scale): AssLine
    {
        $s = self::style($set, $scale);
        $k = $l->playResY / self::HEIGHT;
        $runs = array_map(fn(AssRun $r) => new AssRun($r->text, $s['font'], $s['size'] * $k, $s['weight'], $s['italic'], $r->underline, $r->strikeout,
            $s['color'], $s['alpha'] * $r->alpha, $s['outlineColor'], $s['outlineAlpha'], $s['shadowColor'], $s['shadowAlpha'], $s['border'] * $k, $s['shadow'] * $k,
            $s['spacing'] * $k, $s['borderStyle'], $r->scaleX, $r->scaleY, $r->rotation), $l->runs);
        $placed = $l->position !== null;
        return new AssLine($runs, $placed ? $l->alignment : $s['alignment'], $l->position, $placed ? $l->marginLeft : (int)round($s['marginX'] * $k), $placed ? $l->marginRight : (int)round($s['marginX'] * $k),
            $placed ? $l->marginVertical : (int)round($s['marginY'] * $k), $l->layer, $l->playResX, $l->playResY, true, $l->wraps, $l->start, $l->duration, $l->alpha, $l->clip, $l->origin, $l->moved);
    }

    /** ASS line $l of its sizes $k times (mpv's sub-scale, as sub-ass-override's scale applies it): its fonts, outlines, shadows and spacing. */
    public static function scaled(AssLine $l, float $k): AssLine
    {
        $runs = array_map(fn(AssRun $r) => new AssRun($r->text, $r->font, $r->size * $k, $r->weight, $r->italic, $r->underline, $r->strikeout, $r->color, $r->alpha, $r->outlineColor,
            $r->outlineAlpha, $r->shadowColor, $r->shadowAlpha, $r->border * $k, $r->shadow * $k, $r->spacing * $k, $r->borderStyle, $r->scaleX, $r->scaleY, $r->rotation), $l->runs);
        return new AssLine($runs, $l->alignment, $l->position, $l->marginLeft, $l->marginRight, $l->marginVertical, $l->layer, $l->playResX, $l->playResY, $l->scaledBorder, $l->wraps,
            $l->start, $l->duration, $l->alpha, $l->clip, $l->origin, $l->moved);
    }

    /**
     * The settings' style, of a plane 720 high, its sizes times $scale:
     * a box behind it all (mpv's background-box) as an opaque box of the
     * shadow's color.
     * @return array<string, mixed>
     */
    private static function style(PlayerSettings $set, float $scale): array
    {
        [$c, $a] = self::color($set->get('subTextColorString'));
        [$oc, $oa] = self::color($set->get('subBorderColorString'));
        [$sc, $sa] = self::color($set->get('subShadowColorString'));
        $box = $set->get('subBorderStyle');
        if ($box === 4) { [$oc, $oa] = [$sc, $sa]; }
        $alignment = [7, 4, 1][$set->get('subAlignY')] + $set->get('subAlignX');
        return ['font' => $set->get('subTextFont'), 'size' => $set->get('subTextSize') * $scale, 'weight' => $set->get('subBold') ? 700 : 400, 'italic' => $set->get('subItalic'),
            'color' => $c, 'alpha' => $a, 'outlineColor' => $oc, 'outlineAlpha' => $oa, 'shadowColor' => $sc, 'shadowAlpha' => $sa,
            'border' => $set->get('subBorderSize') * $scale, 'shadow' => $set->get('subShadowSize') * $scale, 'spacing' => $set->get('subSpacing') * $scale,
            'borderStyle' => $box === 1 ? 1 : 3, 'alignment' => $alignment, 'marginX' => $set->get('subMarginX') * $scale, 'marginY' => $set->get('subMarginY') * $scale];
    }

    /** The script of a Default style of the settings' (Ass's), of a plane 720 high and $aspect wide, kept while the same. */
    private static function script(PlayerSettings $set, float $aspect, float $scale, bool $secondary): Ass
    {
        $s = self::style($set, $scale);
        if ($secondary) { $s['alignment'] = 7 + ($s['alignment'] - 1) % 3; }
        $width = max(1, (int)round(self::HEIGHT * $aspect));
        $key = serialize([$s, $width]);
        if (!isset(self::$scripts[$key])) {
            if (count(self::$scripts) > 8) { self::$scripts = []; }
            $color = fn(int $rgb, float $alpha): string => sprintf('&H%02X%02X%02X%02X', (int)round((1 - $alpha) * 255), $rgb & 0xff, ($rgb >> 8) & 0xff, ($rgb >> 16) & 0xff); // ASS's: alpha (0 opaque), blue, green, red
            $num = fn(float $v): string => rtrim(rtrim(sprintf('%.3f', $v), '0'), '.') ?: '0';
            self::$scripts[$key] = new Ass("[Script Info]\nPlayResX: $width\nPlayResY: " . self::HEIGHT . "\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n"
                . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
                . 'Style: Default,' . str_replace(',', ' ', $s['font']) . ',' . $num($s['size']) . ',' . $color($s['color'], $s['alpha']) . ',&H000000FF,' . $color($s['outlineColor'], $s['outlineAlpha']) . ',' . $color($s['shadowColor'], $s['shadowAlpha'])
                . ',' . ($s['weight'] === 700 ? -1 : 0) . ',' . ($s['italic'] ? -1 : 0) . ',0,0,100,100,' . $num($s['spacing']) . ',0,' . $s['borderStyle'] . ',' . $num($s['border']) . ',' . $num($s['shadow'])
                . ',' . $s['alignment'] . ',' . (int)round($s['marginX']) . ',' . (int)round($s['marginX']) . ',' . (int)round($s['marginY']) . ",1\n");
        }
        return self::$scripts[$key];
    }
}
