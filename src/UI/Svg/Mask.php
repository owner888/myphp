<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

use MyPHP\UI\Path as UIPath;

/**
 * The coverage of a UI path (points, in the window) as a mask of w × h
 * device pixels starting at (x0, y0) points, one byte a pixel: filled by
 * the non-zero rule, or stroked $width points wide with the path's caps,
 * joins and dashes (round and whole unless it sets them), as mygo's
 * drawPath has the text system rasterize paths. The
 * rasterizer of the SVG renderer draws it, so a path looks the same on
 * every platform.
 */
final class Mask
{
    public static function of(UIPath $path, float $width, float $scale, float $x0, float $y0, int $w, int $h): string
    {
        $p = new Path;
        foreach ($path->cmds as $c) {
            match ($c[0]) {
                UIPath::MOVE => $p->moveTo($c[1], $c[2]),
                UIPath::LINE => $p->lineTo($c[1], $c[2]),
                UIPath::QUAD => $p->quadTo($c[1], $c[2], $c[3], $c[4]),
                UIPath::CUBE => $p->cubicTo($c[1], $c[2], $c[3], $c[4], $c[5], $c[6]),
                UIPath::CLOSE => $p->close(),
            };
        }
        $m = [$scale, 0.0, 0.0, $scale, -$x0 * $scale, -$y0 * $scale]; // points to the mask's pixels
        $flat = new Polys;
        if ($width > 0) {
            $p->flatten($m, false, $flat);
            if ($path->dash !== []) { // cut from the center line, each subpath from where it starts on the path trim() cut it from (mygo's d9d45f4); past 65536 dashes, the rest whole
                $dashed = new Polys;
                Stroker::dash($flat, $path->dash, $path->dashOffset, $dashed, 1 << 16, $path->phases);
                $flat = $dashed;
            }
            $st = new Stroker; $st->hw = $width / 2; $st->tol = 0.1 / $scale; $st->miter = $path->miter;
            $st->join = match ($path->join) { UIPath::JOIN_MITER => Style::JOIN_MITER, UIPath::JOIN_BEVEL => Style::JOIN_BEVEL, default => Style::JOIN_ROUND };
            $st->cap = match ($path->cap) { UIPath::CAP_BUTT => Style::CAP_BUTT, UIPath::CAP_SQUARE => Style::CAP_SQUARE, default => Style::CAP_ROUND };
            $st->stroke($flat);
            $flat = $st->out; $flat->transform($m);
        } else {
            $p->flatten($m, true, $flat);
        }
        $z = new Rasterizer; $z->reset($w, $h); $pts = $flat->pts;
        for ($i = 0, $n = $flat->count(); $i < $n; $i++) {
            [$from, $to] = $flat->range($i);
            $z->moveTo($pts[$from], $pts[$from + 1]);
            for ($k = $from + 2; $k < $to; $k += 2) { $z->lineTo($pts[$k], $pts[$k + 1]); }
            $z->closePath();
        }
        return pack('C*', ...$z->mask(false));
    }
}
