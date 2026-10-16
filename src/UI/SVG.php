<?php
declare(strict_types=1);

namespace MyPHP\UI;

use RuntimeException;

/**
 * A vector image parsed once, shown by Context::icon in the color of the
 * text, as mygo's SVG: its path, rect, circle, ellipse, line, polyline and
 * polygon elements, in groups, filled (non-zero winding) or stroked where
 * fill is none and a stroke is set, in the box its viewBox (or width and
 * height) gives. Transforms, gradients, masks, clips, styles and text are
 * not read (an open item): convert such art to plain paths first.
 */
final class SVG
{
    /** @var list<array{0: Path, 1: float}> the shapes in viewBox units: the path, and the width it is stroked at (0 filled) */
    public array $shapes = [];
    public float $x = 0, $y = 0, $w = 0, $h = 0;

    public static function parse(string $data): self
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($data);
        libxml_use_internal_errors($prev);
        if ($doc === false) { throw new RuntimeException('svg: not XML'); }
        $s = new self;
        if (isset($doc['viewBox'])) {
            $v = preg_split('/[\s,]+/', trim((string)$doc['viewBox']));
            if (count($v) !== 4) { throw new RuntimeException('svg: viewBox'); }
            [$s->x, $s->y, $s->w, $s->h] = array_map(floatval(...), $v);
        } else {
            $s->w = (float)($doc['width'] ?? 0); $s->h = (float)($doc['height'] ?? 0);
        }
        if ($s->w <= 0 || $s->h <= 0) { throw new RuntimeException('svg: no size'); }
        $s->element($doc, ['fill' => 'black', 'stroke' => 'none', 'stroke-width' => 1.0]);
        return $s;
    }

    public static function fromFile(string $path): self
    {
        return self::parse(file_get_contents($path) ?: throw new RuntimeException("svg: $path"));
    }

    /** Reads an element and its children, with the paint $style inherited. */
    private function element(\SimpleXMLElement $e, array $style): void
    {
        foreach (['fill', 'stroke', 'stroke-width'] as $k) { if (isset($e[$k])) { $style[$k] = $k === 'stroke-width' ? (float)$e[$k] : trim((string)$e[$k]); } }
        $p = new Path;
        switch ($e->getName()) {
            case 'path': self::d((string)$e['d'], $p); break;
            case 'rect': $x = (float)$e['x']; $y = (float)$e['y']; $w = (float)$e['width']; $h = (float)$e['height']; $p->moveTo($x, $y)->lineTo($x + $w, $y)->lineTo($x + $w, $y + $h)->lineTo($x, $y + $h)->close(); break;
            case 'circle': $p->circle((float)$e['cx'], (float)$e['cy'], (float)$e['r']); break;
            case 'ellipse': self::ellipse($p, (float)$e['cx'], (float)$e['cy'], (float)$e['rx'], (float)$e['ry']); break;
            case 'line': $p->moveTo((float)$e['x1'], (float)$e['y1'])->lineTo((float)$e['x2'], (float)$e['y2']); break;
            case 'polyline': case 'polygon':
                $pts = array_map(floatval(...), preg_split('/[\s,]+/', trim((string)$e['points'])));
                for ($i = 0; $i + 1 < count($pts); $i += 2) { $i === 0 ? $p->moveTo($pts[0], $pts[1]) : $p->lineTo($pts[$i], $pts[$i + 1]); }
                if ($e->getName() === 'polygon') { $p->close(); }
                break;
        }
        if ($p->cmds !== []) {
            if ($style['fill'] !== 'none') { $this->shapes[] = [$p, 0.0]; }
            if ($style['stroke'] !== 'none') { $this->shapes[] = [$p, $style['stroke-width']]; }
        }
        foreach ($e->children() as $child) { $this->element($child, $style); }
    }

    private static function ellipse(Path $p, float $cx, float $cy, float $rx, float $ry): void
    {
        $kx = 0.5522847498 * $rx; $ky = 0.5522847498 * $ry;
        $p->moveTo($cx + $rx, $cy)->cubeTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)->cubeTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->cubeTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)->cubeTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)->close();
    }

    /** Reads a path's d attribute into $p: M L H V C S Q T A Z, absolute and relative. */
    private static function d(string $d, Path $p): void
    {
        preg_match_all('/[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?|[^\s,]/', $d, $m); // numbers, and any other character as a command (an unknown one is an error below)
        $tok = $m[0]; $i = 0; $n = count($tok);
        $cmd = ''; $x = $y = 0.0; $sx = $sy = 0.0; $cx = $cy = 0.0; $last = '';
        $num = function () use (&$i, $tok, $n): float { if ($i >= $n || !is_numeric($tok[$i])) { throw new RuntimeException('svg: path'); } return (float)$tok[$i++]; };
        while ($i < $n) {
            if (!is_numeric($tok[$i])) { $cmd = $tok[$i++]; } elseif ($cmd === 'M') { $cmd = 'L'; } elseif ($cmd === 'm') { $cmd = 'l'; }
            $rel = ctype_lower($cmd); $c = strtoupper($cmd);
            $ox = $rel ? $x : 0.0; $oy = $rel ? $y : 0.0;
            switch ($c) {
                case 'M': $x = $ox + $num(); $y = $oy + $num(); $p->moveTo($x, $y); $sx = $x; $sy = $y; break;
                case 'L': $x = $ox + $num(); $y = $oy + $num(); $p->lineTo($x, $y); break;
                case 'H': $x = $ox + $num(); $p->lineTo($x, $y); break;
                case 'V': $y = $oy + $num(); $p->lineTo($x, $y); break;
                case 'C': $c1x = $ox + $num(); $c1y = $oy + $num(); $cx = $ox + $num(); $cy = $oy + $num(); $x = $ox + $num(); $y = $oy + $num(); $p->cubeTo($c1x, $c1y, $cx, $cy, $x, $y); break;
                case 'S': [$c1x, $c1y] = $last === 'C' ? [2 * $x - $cx, 2 * $y - $cy] : [$x, $y]; $cx = $ox + $num(); $cy = $oy + $num(); $x = $ox + $num(); $y = $oy + $num(); $p->cubeTo($c1x, $c1y, $cx, $cy, $x, $y); $c = 'C'; break;
                case 'Q': $cx = $ox + $num(); $cy = $oy + $num(); $x = $ox + $num(); $y = $oy + $num(); $p->quadTo($cx, $cy, $x, $y); break;
                case 'T': [$cx, $cy] = $last === 'Q' ? [2 * $x - $cx, 2 * $y - $cy] : [$x, $y]; $x = $ox + $num(); $y = $oy + $num(); $p->quadTo($cx, $cy, $x, $y); $c = 'Q'; break;
                case 'A': $rx = $num(); $ry = $num(); $rot = $num(); $large = $num() !== 0.0; $sweep = $num() !== 0.0; $nx = $ox + $num(); $ny = $oy + $num(); self::arc($p, $x, $y, $rx, $ry, $rot, $large, $sweep, $nx, $ny); $x = $nx; $y = $ny; break;
                case 'Z': $p->close(); $x = $sx; $y = $sy; break;
                default: throw new RuntimeException("svg: path command $cmd");
            }
            $last = $c;
        }
    }

    /** An SVG arc from (x1, y1) to (x2, y2) as cubic curves (the endpoint to center conversion of the SVG spec, appendix B.2.4). */
    private static function arc(Path $p, float $x1, float $y1, float $rx, float $ry, float $rot, bool $large, bool $sweep, float $x2, float $y2): void
    {
        if ($rx == 0 || $ry == 0) { $p->lineTo($x2, $y2); return; }
        $phi = deg2rad($rot); $cp = cos($phi); $sp = sin($phi);
        $dx = ($x1 - $x2) / 2; $dy = ($y1 - $y2) / 2;
        $x1p = $cp * $dx + $sp * $dy; $y1p = -$sp * $dx + $cp * $dy;
        $rx = abs($rx); $ry = abs($ry);
        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1) { $rx *= sqrt($lambda); $ry *= sqrt($lambda); }
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        $k = ($den == 0 ? 0 : sqrt(max(0, $num / $den))) * ($large === $sweep ? -1 : 1);
        $cxp = $k * $rx * $y1p / $ry; $cyp = -$k * $ry * $x1p / $rx;
        $cx = $cp * $cxp - $sp * $cyp + ($x1 + $x2) / 2; $cy = $sp * $cxp + $cp * $cyp + ($y1 + $y2) / 2;
        $angle = fn(float $ux, float $uy, float $vx, float $vy) => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $t1 = $angle(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $dt = $angle(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);
        if (!$sweep && $dt > 0) { $dt -= 2 * M_PI; } elseif ($sweep && $dt < 0) { $dt += 2 * M_PI; }
        $segs = max(1, (int)ceil(abs($dt) / (M_PI / 2)));
        $d = $dt / $segs; $t = $t1;
        for ($i = 0; $i < $segs; $i++) {
            $a = 4 / 3 * tan($d / 4);
            $pt = function (float $th) use ($cx, $cy, $rx, $ry, $cp, $sp): array { $ex = $rx * cos($th); $ey = $ry * sin($th); return [$cx + $cp * $ex - $sp * $ey, $cy + $sp * $ex + $cp * $ey]; };
            $dv = function (float $th) use ($rx, $ry, $cp, $sp): array { $ex = -$rx * sin($th); $ey = $ry * cos($th); return [$cp * $ex - $sp * $ey, $sp * $ex + $cp * $ey]; };
            [$p0x, $p0y] = $pt($t); [$p3x, $p3y] = $pt($t + $d); [$d0x, $d0y] = $dv($t); [$d3x, $d3y] = $dv($t + $d);
            $p->cubeTo($p0x + $a * $d0x, $p0y + $a * $d0y, $p3x - $a * $d3x, $p3y - $a * $d3y, $p3x, $p3y);
            $t += $d;
        }
    }
}
