<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use Closure;
use MyPHP\UI\{DisplayList, Path};

/**
 * Characters of box drawing, blocks and Powerline's separators, drawn
 * rather than taken from the font, as Ghostty draws them (mygo's box.go):
 * fonts leave gaps between the lines of cells taller than their glyphs,
 * and many lack the separators.
 *
 * @internal
 */
final class Box
{
    /** Weights of the arms of box drawing characters. */
    private const NONE = 0, LIGHT = 1, HEAVY = 2, DOUBLE = 3;

    /**
     * The arms of U+2500 to U+257F, from their Unicode names: the weights
     * of up, right, down and left, a digit each; "" for those drawn
     * otherwise (arcs, diagonals) or by the font (dashes).
     */
    private const ARMS = [
        '0101', '0202', '1010', '2020', '', '', '', '', '', '', '', '', '0110', '0210', '0120', '0220',
        '0011', '0012', '0021', '0022', '1100', '1200', '2100', '2200', '1001', '1002', '2001', '2002', '1110', '1210', '2110', '1120',
        '2120', '2210', '1220', '2220', '1011', '1012', '2011', '1021', '2021', '2012', '1022', '2022', '0111', '0112', '0211', '0212',
        '0121', '0122', '0221', '0222', '1101', '1102', '1201', '1202', '2101', '2102', '2201', '2202', '1111', '1112', '1211', '1212',
        '2111', '1121', '2121', '2112', '2211', '1122', '1221', '2212', '1222', '2122', '2221', '2222', '', '', '', '',
        '0303', '3030', '0310', '0130', '0330', '0013', '0031', '0033', '1300', '3100', '3300', '1003', '3001', '3003', '1310', '3130',
        '3330', '1013', '3031', '3033', '0313', '0131', '0333', '1303', '3101', '3303', '1313', '3131', '3333', '', '', '',
        '', '', '', '', '0001', '1000', '0100', '0010', '0002', '2000', '0200', '0020', '0201', '1020', '0102', '2010',
    ];

    /** Quadrants: upper left, upper right, lower left, lower right. */
    private const QUADRANTS = [0x2596 => '0010', 0x2597 => '0001', 0x2598 => '1000', 0x2599 => '1011', 0x259a => '1001',
        0x259b => '1110', 0x259c => '1101', 0x259d => '0100', 0x259e => '0110', 0x259f => '0111'];

    /**
     * Whether $r is a shape rather than text, which keeps its color under
     * the minimum contrast, as Ghostty's (mygo's noMinContrast): box
     * drawing, block elements, the legacy computing symbols and Powerline's.
     */
    public static function noMinContrast(int $r): bool
    {
        return $r >= 0x2500 && $r <= 0x259f || $r >= 0x1fb00 && $r <= 0x1fbff || $r >= 0xe0b0 && $r <= 0xe0d7;
    }

    /** Whether the terminal draws $r itself. */
    public static function drawn(int $r): bool
    {
        return match (true) {
            $r >= 0x2500 && $r <= 0x257f => self::ARMS[$r - 0x2500] !== '' || ($r >= 0x256d && $r <= 0x2573),
            $r >= 0x2580 && $r <= 0x259f, $r >= 0xe0b0 && $r <= 0xe0b7 => true,
            default => false,
        };
    }

    /** Draws $r in cell $c, at $s device pixels a point. @param array{0: float, 1: float, 2: float, 3: float} $c @param array{0: int, 1: float} $col */
    public static function draw(DisplayList $list, float $s, int $r, array $c, array $col): void
    {
        // The cell in device pixels.
        $x0 = round($c[0] * $s); $y0 = round($c[1] * $s);
        $x1 = round(($c[0] + $c[2]) * $s); $y1 = round(($c[1] + $c[3]) * $s);
        $fill = function (float $ax, float $ay, float $bx, float $by) use ($list, $s, $col): void {
            if ($bx > $ax && $by > $ay) { $list->rect($ax / $s, $ay / $s, ($bx - $ax) / $s, ($by - $ay) / $s, $col[0], $col[1]); }
        };
        $w = $x1 - $x0; $h = $y1 - $y0;
        if ($r >= 0x2580 && $r <= 0x259f) {
            self::block($r, $x0, $y0, $w, $h, $fill, $list, $col, $s);
            return;
        }
        if ($r >= 0xe0b0 && $r <= 0xe0b3) {
            $path = new Path;
            if ($r === 0xe0b0 || $r === 0xe0b1) {
                $path->moveTo($x0 / $s, $y0 / $s)->lineTo($x1 / $s, ($y0 + $h / 2) / $s)->lineTo($x0 / $s, $y1 / $s);
            } else {
                $path->moveTo($x1 / $s, $y0 / $s)->lineTo($x0 / $s, ($y0 + $h / 2) / $s)->lineTo($x1 / $s, $y1 / $s);
            }
            if ($r === 0xe0b0 || $r === 0xe0b2) {
                $path->close();
                $list->path($path, $col[0], $col[1]);
            } else {
                $list->path($path, $col[0], $col[1], max(1, round($s)) / $s);
            }
            return;
        }
        if ($r >= 0xe0b4 && $r <= 0xe0b7) {
            // Powerline's rounded caps: half an ellipse spanning the cell, as the triangles do (mygo's); the font's glyph fell short of the taller cells a line height above 1 makes, next to the prompt's background.
            $k = 0.5523; // a quarter circle's cubic control distance
            $ym = $y0 + $h / 2;
            [$ex, $fx] = $r >= 0xe0b6 ? [$x0, $x1] : [$x1, $x0]; // the cap's tip and its flat side
            $kx = $fx + ($ex - $fx) * $k; $ky = $h / 2 * $k;
            $path = (new Path)->moveTo($fx / $s, $y0 / $s)
                ->cubeTo($kx / $s, $y0 / $s, $ex / $s, ($ym - $ky) / $s, $ex / $s, $ym / $s)
                ->cubeTo($ex / $s, ($ym + $ky) / $s, $kx / $s, $y1 / $s, $fx / $s, $y1 / $s);
            if ($r === 0xe0b4 || $r === 0xe0b6) {
                $path->close();
                $list->path($path, $col[0], $col[1]);
            } else {
                $list->path($path, $col[0], $col[1], max(1, round($s)) / $s);
            }
            return;
        }
        $light = max(1, round($s));
        $cx = $x0 + floor(($w - $light) / 2);
        $cy = $y0 + floor(($h - $light) / 2);
        if ($r >= 0x256d && $r <= 0x2573) {
            $path = new Path;
            $mx = ($cx + $light / 2) / $s; $my = ($cy + $light / 2) / $s;
            $rad = min($w, $h) / 2 / $s;
            switch ($r) {
                case 0x256d: $path->moveTo($mx, $y1 / $s)->lineTo($mx, $my + $rad)->quadTo($mx, $my, $mx + $rad, $my)->lineTo($x1 / $s, $my); break; // ╭
                case 0x256e: $path->moveTo($mx, $y1 / $s)->lineTo($mx, $my + $rad)->quadTo($mx, $my, $mx - $rad, $my)->lineTo($x0 / $s, $my); break; // ╮
                case 0x256f: $path->moveTo($mx, $y0 / $s)->lineTo($mx, $my - $rad)->quadTo($mx, $my, $mx - $rad, $my)->lineTo($x0 / $s, $my); break; // ╯
                case 0x2570: $path->moveTo($mx, $y0 / $s)->lineTo($mx, $my - $rad)->quadTo($mx, $my, $mx + $rad, $my)->lineTo($x1 / $s, $my); break; // ╰
                case 0x2571: $path->moveTo($x1 / $s, $y0 / $s)->lineTo($x0 / $s, $y1 / $s); break; // ╱
                case 0x2572: $path->moveTo($x0 / $s, $y0 / $s)->lineTo($x1 / $s, $y1 / $s); break; // ╲
                case 0x2573: // ╳
                    $path->moveTo($x1 / $s, $y0 / $s)->lineTo($x0 / $s, $y1 / $s);
                    $list->path($path, $col[0], $col[1], $light / $s);
                    $path = (new Path)->moveTo($x0 / $s, $y0 / $s)->lineTo($x1 / $s, $y1 / $s);
                    break;
            }
            $list->path($path, $col[0], $col[1], $light / $s);
            return;
        }
        $arms = self::ARMS[$r - 0x2500];
        if ($arms === '') {
            return;
        }
        self::arms($fill, [(int)$arms[0], (int)$arms[1], (int)$arms[2], (int)$arms[3]], $x0, $y0, $x1, $y1, $cx + $light / 2, $cy + $light / 2, $light);
    }

    /**
     * Draws the arms of a box drawing character (up, right, down, left) in
     * the cell from $x0, $y0 to $x1, $y1, whose lines cross at $mx, $my,
     * $light pixels wide. Light and heavy arms are lines; a double arm is
     * two light lines, a light line apart, which turn into the arm across
     * them as a corner does, or go through.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $a
     */
    private static function arms(Closure $fill, array $a, float $x0, float $y0, float $x1, float $y1, float $mx, float $my, float $light): void
    {
        [$up, $right, $down, $left] = $a;
        $off = $light; // from the middle to each line of a double arm
        $vDouble = $up === self::DOUBLE || $down === self::DOUBLE;
        $hDouble = $left === self::DOUBLE || $right === self::DOUBLE;
        $thick = fn(int $w): float => match ($w) { self::HEAVY => 2 * $light, self::DOUBLE => 2 * $off + $light, default => $light };
        // The joint is as wide as the widest arm across.
        $hw = max($thick($left), $thick($right));
        $vw = max($thick($up), $thick($down));
        $single = fn(int $w): bool => $w === self::LIGHT || $w === self::HEAVY;
        if ($single($up)) {
            $t = $thick($up); $end = $my + $hw / 2;
            if ($hDouble && $down === self::NONE) { $end = $my - $off + $light / 2; } // up to the upper line
            $fill($mx - $t / 2, $y0, $mx + $t / 2, $end);
        }
        if ($single($down)) {
            $t = $thick($down); $start = $my - $hw / 2;
            if ($hDouble && $up === self::NONE) { $start = $my + $off - $light / 2; }
            $fill($mx - $t / 2, $start, $mx + $t / 2, $y1);
        }
        if ($single($left)) {
            $t = $thick($left); $end = $mx + $vw / 2;
            if ($vDouble && $right === self::NONE) { $end = $mx - $off + $light / 2; }
            $fill($x0, $my - $t / 2, $end, $my + $t / 2);
        }
        if ($single($right)) {
            $t = $thick($right); $start = $mx - $vw / 2;
            if ($vDouble && $left === self::NONE) { $start = $mx + $off - $light / 2; }
            $fill($start, $my - $t / 2, $x1, $my + $t / 2);
        }
        // The lines of double arms. The one on a side with a double arm across is inside the corner it turns, and the other outside.
        foreach ([-1, 1] as $side) {
            if ($vDouble) {
                $lx = $mx + $side * $off - $light / 2;
                $across = $side > 0 ? $right : $left;
                if ($up === self::DOUBLE && $down === self::DOUBLE && $across !== self::DOUBLE) {
                    $fill($lx, $y0, $lx + $light, $y1);
                } else {
                    // Where the line meets the arms across: inside or outside their double lines, or at their single line.
                    [$in, $out] = [$my - $off + $light / 2, $my + $off + $light / 2];
                    if ($across === self::DOUBLE) { $out = $in; } elseif (!$hDouble) { $out = $my + $light / 2; }
                    if ($up === self::DOUBLE) { $fill($lx, $y0, $lx + $light, $out); }
                    [$in, $out] = [$my + $off - $light / 2, $my - $off - $light / 2];
                    if ($across === self::DOUBLE) { $out = $in; } elseif (!$hDouble) { $out = $my - $light / 2; }
                    if ($down === self::DOUBLE) { $fill($lx, $out, $lx + $light, $y1); }
                }
            }
            if ($hDouble) {
                $ly = $my + $side * $off - $light / 2;
                $across = $side > 0 ? $down : $up;
                if ($left === self::DOUBLE && $right === self::DOUBLE && $across !== self::DOUBLE) {
                    $fill($x0, $ly, $x1, $ly + $light);
                } else {
                    [$in, $out] = [$mx - $off + $light / 2, $mx + $off + $light / 2];
                    if ($across === self::DOUBLE) { $out = $in; } elseif (!$vDouble) { $out = $mx + $light / 2; }
                    if ($left === self::DOUBLE) { $fill($x0, $ly, $out, $ly + $light); }
                    [$in, $out] = [$mx + $off - $light / 2, $mx - $off - $light / 2];
                    if ($across === self::DOUBLE) { $out = $in; } elseif (!$vDouble) { $out = $mx - $light / 2; }
                    if ($right === self::DOUBLE) { $fill($out, $ly, $x1, $ly + $light); }
                }
            }
        }
    }

    /** Draws a block element (U+2580 to U+259F). @param array{0: int, 1: float} $col */
    private static function block(int $r, float $x0, float $y0, float $w, float $h, Closure $fill, DisplayList $list, array $col, float $s): void
    {
        $rx = fn(float $f): float => $x0 + round($w * $f);
        $ry = fn(float $f): float => $y0 + round($h * $f);
        $x1 = $x0 + $w; $y1 = $y0 + $h;
        switch (true) {
            case $r === 0x2580: $fill($x0, $y0, $x1, $ry(0.5)); break;
            case $r >= 0x2581 && $r <= 0x2588: $fill($x0, $ry(1 - ($r - 0x2580) / 8), $x1, $y1); break;
            case $r >= 0x2589 && $r <= 0x258f: $fill($x0, $y0, $rx((0x2590 - $r) / 8), $y1); break;
            case $r === 0x2590: $fill($rx(0.5), $y0, $x1, $y1); break;
            case $r >= 0x2591 && $r <= 0x2593: $list->rect($x0 / $s, $y0 / $s, $w / $s, $h / $s, $col[0], ($r - 0x2590) / 4); break;
            case $r === 0x2594: $fill($x0, $y0, $x1, $ry(1 / 8)); break;
            case $r === 0x2595: $fill($rx(7 / 8), $y0, $x1, $y1); break;
            default:
                $q = self::QUADRANTS[$r] ?? '';
                $mx = $rx(0.5); $my = $ry(0.5);
                if ($q !== '') {
                    if ($q[0] === '1') { $fill($x0, $y0, $mx, $my); }
                    if ($q[1] === '1') { $fill($mx, $y0, $x1, $my); }
                    if ($q[2] === '1') { $fill($x0, $my, $mx, $y1); }
                    if ($q[3] === '1') { $fill($mx, $my, $x1, $y1); }
                }
        }
    }
}
