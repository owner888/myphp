<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Draws nodes into layers of premultiplied RGBA pixels, w × h, as
 * mygo's internal/svg renderer: shapes rasterized into coverage, painted
 * with colors and gradients, group opacity, clip paths and masks through
 * layers of their own.
 */
final class Renderer
{
    /** The color currentColor is drawn with. */
    public array $current = Style::BLACK;
    private Rasterizer $z;
    /** @var \FFI\CData|null the coverage of what is being drawn, a rectangle of the canvas */
    private ?\FFI\CData $cov = null;
    private Polys $flat, $dashed;
    private Stroker $stroker;
    /** @var list<Layer> */
    private array $free = [];

    public function __construct(public readonly int $w, public readonly int $h)
    {
        $this->z = new Rasterizer; $this->flat = new Polys; $this->dashed = new Polys; $this->stroker = new Stroker;
    }

    public function layer(): Layer { return array_pop($this->free) ?? new Layer($this->w, $this->h); }

    public function release(Layer $l): void { $l->clear(); $this->free[] = $l; }

    /** Draws $n, whose parent's user space maps to pixels by $ctm, into $dst with an opacity. */
    public function node(Node $n, array $ctm, Layer $dst, float $alpha): void
    {
        $a = $alpha * $n->opacity;
        if ($a <= 0) { return; }
        $m = Matrix::mul($ctm, $n->m);
        if ($n->clip === null && $n->mask === null && ($n->opacity >= 1 || $n->single)) { $this->content($n, $m, $dst, $a); return; }
        if ($n->shape !== null && $n->clip !== null && $n->mask === null && ($n->shape->stroke[0] === Shape::NONE || $n->shape->fill[0] === Shape::NONE)) {
            $this->clippedShape($n->shape, $m, $dst, $a, $n->bbox, $n->clip);
            return;
        }
        // Group opacity, clipping and masking apply to the content as a whole.
        $l = $this->layer();
        $this->content($n, $m, $l, 1);
        if ($n->clip !== null) { $this->clip($l, $n->clip, $m, $n->bbox); }
        if ($n->mask !== null) { $this->mask($l, $n->mask, $m, $n->bbox); }
        $this->composite($dst, $l, $a);
        $this->release($l);
    }

    /** Fast path for a single fill-only or stroke-only shape with a clip-path: fuses clip mask directly into shape coverage before painting into $dst. */
    private function clippedShape(Shape $s, array $m, Layer $dst, float $alpha, ?array $bbox, ClipDef $cd): void
    {
        if ($s->fill[0] !== Shape::NONE) {
            $this->flat->reset();
            $s->path->flatten($m, true, $this->flat);
            $rect = $this->cover($this->flat, $s->evenOdd);
            $paint = $s->fill;
            $op = $alpha * $s->fillOpacity;
        } else {
            if ($s->width <= 0) { return; }
            $scale = Matrix::stretch($m);
            if (!($scale > 0)) { return; }
            $this->flat->reset();
            $s->path->flatten($m, false, $this->flat);
            $lines = $this->flat;
            if ($s->dashes !== []) { $this->dashed->reset(); Stroker::dash($this->flat, $s->dashes, $s->dashOffset, $this->dashed, 1 << 16); $lines = $this->dashed; }
            $st = $this->stroker; $st->out->reset();
            $st->hw = $s->width / 2; $st->join = $s->join; $st->cap = $s->cap; $st->miter = $s->miter; $st->tol = 0.1 / $scale;
            $st->stroke($lines);
            $st->out->transform($m);
            $rect = $this->cover($st->out, false);
            $paint = $s->stroke;
            $op = $alpha * $s->strokeOpacity;
        }
        if ($rect === null) { return; }
        $f = Layer::ffi();
        $n = ($rect[2] - $rect[0]) * ($rect[3] - $rect[1]);
        $shapeCov = $f->cast('uint8_t*', $f->calloc(max(1, $n), 1));
        \FFI::memcpy($shapeCov, $this->cov, $n);
        $clipCov = $this->clipMask($cd, $m, $bbox, $rect);
        try {
            for ($i = 0; $i < $n; $i++) {
                $cv = $clipCov[$i];
                if ($cv === 0) { $shapeCov[$i] = 0; continue; }
                if ($cv === 255) { continue; }
                $sv = $shapeCov[$i];
                if ($sv !== 0) { $shapeCov[$i] = self::div255($sv * $cv); }
            }
            $this->cov = $shapeCov;
            $this->paint($dst, $rect, $paint, $op, $m, $bbox);
        } finally {
            $f->free($clipCov);
            $f->free($shapeCov);
        }
    }

    private function content(Node $n, array $m, Layer $dst, float $alpha): void
    {
        $s = $n->shape;
        if ($s === null) {
            foreach ($n->kids as $k) { $this->node($k, $m, $dst, $alpha); }
            return;
        }
        if ($s->strokeFirst) { $this->stroke($s, $m, $dst, $alpha, $n->bbox); $this->fill($s, $m, $dst, $alpha, $n->bbox); }
        else { $this->fill($s, $m, $dst, $alpha, $n->bbox); $this->stroke($s, $m, $dst, $alpha, $n->bbox); }
    }

    private function fill(Shape $s, array $m, Layer $dst, float $alpha, ?array $bbox): void
    {
        if ($s->fill[0] === Shape::NONE) { return; }
        $this->flat->reset();
        $s->path->flatten($m, true, $this->flat);
        if (($rect = $this->cover($this->flat, $s->evenOdd)) !== null) { $this->paint($dst, $rect, $s->fill, $alpha * $s->fillOpacity, $m, $bbox); }
    }

    private function stroke(Shape $s, array $m, Layer $dst, float $alpha, ?array $bbox): void
    {
        if ($s->stroke[0] === Shape::NONE || $s->width <= 0) { return; }
        $scale = Matrix::stretch($m);
        if (!($scale > 0)) { return; }
        $this->flat->reset();
        $s->path->flatten($m, false, $this->flat);
        $lines = $this->flat;
        if ($s->dashes !== []) { $this->dashed->reset(); Stroker::dash($this->flat, $s->dashes, $s->dashOffset, $this->dashed, 1 << 16); $lines = $this->dashed; }
        $st = $this->stroker; $st->out->reset();
        $st->hw = $s->width / 2; $st->join = $s->join; $st->cap = $s->cap; $st->miter = $s->miter; $st->tol = 0.1 / $scale;
        $st->stroke($lines);
        $st->out->transform($m);
        if (($rect = $this->cover($st->out, false)) !== null) { $this->paint($dst, $rect, $s->stroke, $alpha * $s->strokeOpacity, $m, $bbox); }
    }

    /** Rasterizes polygons in pixels into $cov, returning the rectangle [x0, y0, x1, y1] of the canvas it covers, or null for none. */
    private function cover(Polys $ps, bool $evenOdd): ?array
    {
        if ($ps->count() === 0) { return null; }
        $far = 1 << 24; // shapes far beyond the canvas, or not finite, draw nothing
        $x0 = $y0 = INF; $x1 = $y1 = -INF; $pts = $ps->pts;
        for ($i = 0, $n = count($pts); $i < $n; $i += 2) {
            $x = $pts[$i]; $y = $pts[$i + 1];
            if (!($x > -$far && $x < $far && $y > -$far && $y < $far)) { return null; }
            if ($x < $x0) { $x0 = $x; } if ($x > $x1) { $x1 = $x; } if ($y < $y0) { $y0 = $y; } if ($y > $y1) { $y1 = $y; }
        }
        $rx0 = max((int)floor($x0), 0); $ry0 = max((int)floor($y0), 0);
        $rx1 = min((int)ceil($x1) + 1, $this->w); $ry1 = min((int)ceil($y1) + 1, $this->h);
        if ($rx1 <= $rx0 || $ry1 <= $ry0) { return null; }
        $z = $this->z; $z->reset($rx1 - $rx0, $ry1 - $ry0);
        for ($i = 0, $n = $ps->count(); $i < $n; $i++) {
            [$from, $to] = $ps->range($i);
            $z->moveTo($pts[$from] - $rx0, $pts[$from + 1] - $ry0);
            for ($k = $from + 2; $k < $to; $k += 2) { $z->lineTo($pts[$k] - $rx0, $pts[$k + 1] - $ry0); }
            $z->closePath();
        }
        $this->cov = $z->maskBuffer($evenOdd);
        return [$rx0, $ry0, $rx1, $ry1];
    }

    /** Divides by 255, rounding, for x up to 255 × 255. */
    private static function div255(int $x): int { return ($x + 128 + (($x + 128) >> 8)) >> 8; }

    /** A color with an opacity, premultiplied, as [r, g, b, a] from 0 to 255. */
    public static function premul(array $c, float $alpha): array
    {
        $a = max(0.0, min(1.0, $c[3] * $alpha));
        $q = fn(float $v) => (int)(max(0.0, min(1.0, $v)) * $a * 255 + 0.5);
        return [$q($c[0]), $q($c[1]), $q($c[2]), (int)($a * 255 + 0.5)];
    }

    /** Paints the coverage in $cov, of $rect, with a paint [kind, color, gradient] and an opacity; $m maps the user space of the shape it covers to pixels. */
    private function paint(Layer $dst, array $rect, array $p, float $alpha, array $m, ?array $bbox): void
    {
        [$x0, $y0, $x1, $y1] = $rect; $w = $x1 - $x0; $lw = $this->w;
        $pix = $dst->pix; $cov = $this->cov;
        if ($p[0] === Shape::COLOR || $p[0] === Shape::CURRENT) {
            [$cr, $cg, $cb, $ca] = self::premul($p[0] === Shape::CURRENT ? $this->current : $p[1], $alpha);
            if ($ca === 0) { return; }
            for ($y = $y0; $y < $y1; $y++) {
                $ci = ($y - $y0) * $w; $pi = ($y * $lw + $x0) * 4;
                for ($x = 0; $x < $w; $x++, $pi += 4) {
                    $v = $cov[$ci + $x];
                    if ($v === 0) { continue; }
                    if ($v === 255 && $ca === 255) { $pix[$pi] = $cr; $pix[$pi + 1] = $cg; $pix[$pi + 2] = $cb; $pix[$pi + 3] = 255; continue; }
                    $t = $ca * $v + 128; $sa = ($t + ($t >> 8)) >> 8; $ia = 255 - $sa;
                    $t0 = $cr * $v + 128; $t1 = $pix[$pi] * $ia + 128;
                    $pix[$pi] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t0 = $cg * $v + 128; $t1 = $pix[$pi + 1] * $ia + 128;
                    $pix[$pi + 1] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t0 = $cb * $v + 128; $t1 = $pix[$pi + 2] * $ia + 128;
                    $pix[$pi + 2] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t1 = $pix[$pi + 3] * $ia + 128;
                    $pix[$pi + 3] = $sa + (($t1 + ($t1 >> 8)) >> 8);
                }
            }
        } elseif ($p[0] === Shape::GRADIENT) {
            $g = $p[2]; $gm = $m;
            if ($g->bbox) {
                if ($bbox === null || $bbox[2] == 0 || $bbox[3] == 0) { return; } // the gradient has no box to span
                $gm = Matrix::mul($gm, Matrix::box($bbox));
            }
            $inv = Matrix::invert(Matrix::mul($gm, $g->m));
            if ($inv === null) { return; }
            [$i0, $i1, $i2, $i3, $i4, $i5] = $inv;
            $lut = $g->lut($this->current, $alpha);
            // A linear gradient padded is a dot product a pixel, inline; the others go through Gradient::at.
            $linear = !$g->radial && $g->spread === Gradient::PAD && ($g->x2 - $g->x1) ** 2 + ($g->y2 - $g->y1) ** 2 > 0;
            if ($linear) { $dx = $g->x2 - $g->x1; $dy = $g->y2 - $g->y1; $dd = $dx * $dx + $dy * $dy; $tx = ($i0 * $dx + $i1 * $dy) / $dd; $ty = ($i2 * $dx + $i3 * $dy) / $dd; $t0 = (($i4 - $g->x1) * $dx + ($i5 - $g->y1) * $dy) / $dd; }
            $lutR = []; $lutG = []; $lutB = []; $lutA = [];
            foreach ($lut as $c) { $lutR[] = $c[0]; $lutG[] = $c[1]; $lutB[] = $c[2]; $lutA[] = $c[3]; }
            $empty = $dst->dirty === null;
            for ($y = $y0; $y < $y1; $y++) {
                $ci = ($y - $y0) * $w; $pi = ($y * $lw + $x0) * 4; $py = $y + 0.5;
                $rowT = $linear ? ($tx * ($x0 + 0.5) + $ty * $py + $t0) : 0.0;
                for ($x = 0; $x < $w; $x++, $pi += 4) {
                    $v = $cov[$ci + $x];
                    if ($v === 0) { if ($linear) { $rowT += $tx; } continue; }
                    if ($linear) {
                        $t = $rowT < 0.0 ? 0.0 : ($rowT > 1.0 ? 1.0 : $rowT);
                        $rowT += $tx;
                    } else {
                        $px = $x0 + $x + 0.5;
                        $t = $g->at($i0 * $px + $i2 * $py + $i4, $i1 * $px + $i3 * $py + $i5);
                        if ($t === null) { continue; }
                    }
                    $idx = (int)($t * 255 + 0.5);
                    $ca = $lutA[$idx];
                    if ($v === 255 && $ca === 255) {
                        $pix[$pi] = $lutR[$idx]; $pix[$pi + 1] = $lutG[$idx]; $pix[$pi + 2] = $lutB[$idx]; $pix[$pi + 3] = 255;
                        continue;
                    }
                    $cr = $lutR[$idx]; $cg = $lutG[$idx]; $cb = $lutB[$idx];
                    $tmp = $ca * $v + 128; $sa = ($tmp + ($tmp >> 8)) >> 8;
                    if ($empty) {
                        $t0 = $cr * $v + 128; $pix[$pi] = ($t0 + ($t0 >> 8)) >> 8;
                        $t0 = $cg * $v + 128; $pix[$pi + 1] = ($t0 + ($t0 >> 8)) >> 8;
                        $t0 = $cb * $v + 128; $pix[$pi + 2] = ($t0 + ($t0 >> 8)) >> 8;
                        $pix[$pi + 3] = $sa;
                        continue;
                    }
                    $ia = 255 - $sa;
                    $t0 = $cr * $v + 128; $t1 = $pix[$pi] * $ia + 128;
                    $pix[$pi] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t0 = $cg * $v + 128; $t1 = $pix[$pi + 1] * $ia + 128;
                    $pix[$pi + 1] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t0 = $cb * $v + 128; $t1 = $pix[$pi + 2] * $ia + 128;
                    $pix[$pi + 2] = (($t0 + ($t0 >> 8)) >> 8) + (($t1 + ($t1 >> 8)) >> 8);
                    $t1 = $pix[$pi + 3] * $ia + 128;
                    $pix[$pi + 3] = $sa + (($t1 + ($t1 >> 8)) >> 8);
                }
            }
        } else {
            return;
        }
        $dst->touch($rect);
    }

    /** Draws $src over $dst with an opacity. */
    private function composite(Layer $dst, Layer $src, float $alpha): void
    {
        $d = $src->dirty;
        if ($d === null) { return; }
        $k = (int)(max(0.0, min(1.0, $alpha)) * 255 + 0.5);
        if ($k === 0) { return; }
        if ($dst->dirty === null && $k === 255 && $dst->w === $src->w && $dst->h === $src->h) {
            [$dst->pix, $src->pix] = [$src->pix, $dst->pix];
            $dst->dirty = $d;
            $src->dirty = null;
            return;
        }
        [$x0, $y0, $x1, $y1] = $d; $lw = $this->w;
        $s = $src->pix; $t = $dst->pix;
        if ($k === 255) {
            for ($y = $y0; $y < $y1; $y++) {
                for ($i = ($y * $lw + $x0) * 4, $e = ($y * $lw + $x1) * 4; $i < $e; $i += 4) {
                    $sa = $s[$i + 3];
                    if ($sa === 0) { continue; }
                    if ($sa === 255) {
                        $t[$i] = $s[$i]; $t[$i + 1] = $s[$i + 1]; $t[$i + 2] = $s[$i + 2]; $t[$i + 3] = 255;
                        continue;
                    }
                    $ia = 255 - $sa;
                    $tmp = $t[$i] * $ia + 128;     $t[$i]     = $s[$i] + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 1] * $ia + 128; $t[$i + 1] = $s[$i + 1] + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 2] * $ia + 128; $t[$i + 2] = $s[$i + 2] + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 3] * $ia + 128; $t[$i + 3] = $sa + (($tmp + ($tmp >> 8)) >> 8);
                }
            }
        } else {
            for ($y = $y0; $y < $y1; $y++) {
                for ($i = ($y * $lw + $x0) * 4, $e = ($y * $lw + $x1) * 4; $i < $e; $i += 4) {
                    $sa = $s[$i + 3];
                    if ($sa === 0) { continue; }
                    $tmp = $s[$i] * $k + 128;     $sr = ($tmp + ($tmp >> 8)) >> 8;
                    $tmp = $s[$i + 1] * $k + 128; $sg = ($tmp + ($tmp >> 8)) >> 8;
                    $tmp = $s[$i + 2] * $k + 128; $sb = ($tmp + ($tmp >> 8)) >> 8;
                    $tmp = $sa * $k + 128;         $sa = ($tmp + ($tmp >> 8)) >> 8;
                    $ia = 255 - $sa;
                    $tmp = $t[$i] * $ia + 128;     $t[$i]     = $sr + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 1] * $ia + 128; $t[$i + 1] = $sg + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 2] * $ia + 128; $t[$i + 2] = $sb + (($tmp + ($tmp >> 8)) >> 8);
                    $tmp = $t[$i + 3] * $ia + 128; $t[$i + 3] = $sa + (($tmp + ($tmp >> 8)) >> 8);
                }
            }
        }
        $dst->touch($d);
    }

    /** Multiplies the pixels of $l within its dirty rectangle by the values of $mask, one uint8_t a pixel of that rectangle. */
    private function scale(Layer $l, \FFI\CData $mask): void
    {
        [$x0, $y0, $x1, $y1] = $l->dirty; $w = $x1 - $x0; $lw = $this->w;
        $pix = $l->pix;
        for ($y = $y0; $y < $y1; $y++) {
            $mi = ($y - $y0) * $w; $pi = ($y * $lw + $x0) * 4;
            for ($x = 0; $x < $w; $x++, $pi += 4) {
                $v = $mask[$mi + $x];
                if ($v === 255) { continue; }
                if ($v === 0) { $pix[$pi] = $pix[$pi + 1] = $pix[$pi + 2] = $pix[$pi + 3] = 0; continue; }
                $tmp = $pix[$pi] * $v + 128;     $pix[$pi]     = ($tmp + ($tmp >> 8)) >> 8;
                $tmp = $pix[$pi + 1] * $v + 128; $pix[$pi + 1] = ($tmp + ($tmp >> 8)) >> 8;
                $tmp = $pix[$pi + 2] * $v + 128; $pix[$pi + 2] = ($tmp + ($tmp >> 8)) >> 8;
                $tmp = $pix[$pi + 3] * $v + 128; $pix[$pi + 3] = ($tmp + ($tmp >> 8)) >> 8;
            }
        }
    }

    /** Clips what a layer holds to a clip path, applied in the user space $m of an element with a bounding box. */
    private function clip(Layer $l, ClipDef $cd, array $m, ?array $bbox): void
    {
        if ($l->dirty === null) { return; }
        $mask = $this->clipMask($cd, $m, $bbox, $l->dirty);
        try {
            $this->scale($l, $mask);
        } finally {
            Layer::ffi()->free($mask);
        }
    }

    /** The coverage of a clip path over a rectangle as a C uint8_t* buffer (caller frees). */
    private function clipMask(ClipDef $cd, array $m, ?array $bbox, array $rect): \FFI\CData
    {
        [$x0, $y0, $x1, $y1] = $rect; $w = $x1 - $x0;
        $n = max(1, $w * ($y1 - $y0));
        $f = Layer::ffi();
        $mask = $f->cast('uint8_t*', $f->calloc($n, 1));
        $cm = Matrix::mul($m, $cd->m);
        if ($cd->bbox) {
            if ($bbox === null || $bbox[2] == 0 || $bbox[3] == 0) { return $mask; } // nothing to clip to
            $cm = Matrix::mul($cm, Matrix::box($bbox));
        }
        $empty = true;
        foreach ($cd->kids as $k) { $this->clipNode($k, $cm, $rect, $mask, $empty); }
        if ($cd->clip !== null) {
            $inner = $this->clipMask($cd->clip, $m, $bbox, $rect);
            for ($i = 0; $i < $n; $i++) { $mask[$i] = self::div255($mask[$i] * $inner[$i]); }
            $f->free($inner);
        }
        return $mask;
    }

    /** Adds the coverage of a clip path's node to $mask, a rectangle. */
    private function clipNode(Node $n, array $m, array $rect, \FFI\CData $mask, bool &$empty): void
    {
        $m = Matrix::mul($m, $n->m);
        if ($n->shape === null) {
            foreach ($n->kids as $k) { $this->clipNode($k, $m, $rect, $mask, $empty); }
            return;
        }
        $this->flat->reset();
        $n->shape->path->flatten($m, true, $this->flat);
        $cr = $this->cover($this->flat, $n->shape->evenOdd);
        if ($cr === null) { return; }
        $ix0 = max($cr[0], $rect[0]); $iy0 = max($cr[1], $rect[1]); $ix1 = min($cr[2], $rect[2]); $iy1 = min($cr[3], $rect[3]);
        if ($ix1 <= $ix0 || $iy1 <= $iy0) { return; }
        $cw = $cr[2] - $cr[0]; $w = $rect[2] - $rect[0]; $cov = $this->cov;
        $n2 = $ix1 - $ix0;
        if ($empty) {
            $empty = false;
            if ($cr === $rect) {
                \FFI::memcpy($mask, $cov, $w * ($rect[3] - $rect[1]));
                return;
            }
            for ($y = $iy0; $y < $iy1; $y++) {
                $si = ($y - $cr[1]) * $cw + $ix0 - $cr[0];
                $di = ($y - $rect[1]) * $w + $ix0 - $rect[0];
                \FFI::memcpy($mask + $di, $cov + $si, $n2);
            }
            return;
        }
        for ($y = $iy0; $y < $iy1; $y++) {
            $si = ($y - $cr[1]) * $cw + $ix0 - $cr[0]; $di = ($y - $rect[1]) * $w + $ix0 - $rect[0];
            for ($x = 0; $x < $n2; $x++) {
                $b = $cov[$si + $x];
                if ($b === 0) { continue; }
                $a = $mask[$di + $x];
                $mask[$di + $x] = $a === 0 ? $b : ($b === 255 ? 255 : $a + $b - self::div255($a * $b));
            }
        }
    }

    /** Masks what a layer holds with a mask, applied in the user space $m of an element with a bounding box. */
    private function mask(Layer $l, MaskDef $md, array $m, ?array $bbox): void
    {
        $d = $l->dirty;
        if ($d === null) { return; }
        [$x0, $y0, $x1, $y1] = $d; $w = $x1 - $x0;
        $n = max(1, $w * ($y1 - $y0));
        $f = Layer::ffi();
        $values = $f->cast('uint8_t*', $f->calloc($n, 1));
        try {
            $region = $md->region; $cm = $m;
            if ($md->units || $md->content) {
                if ($bbox === null || $bbox[2] == 0 || $bbox[3] == 0) { $this->scale($l, $values); return; }
                if ($md->units) { $region = [$bbox[0] + $region[0] * $bbox[2], $bbox[1] + $region[1] * $bbox[3], $region[2] * $bbox[2], $region[3] * $bbox[3]]; }
                if ($md->content) { $cm = Matrix::mul($m, Matrix::box($bbox)); }
            }
            $ml = $this->layer();
            foreach ($md->kids as $k) { $this->node($k, $cm, $ml, 1); }
            $lw = $this->w; $mp = $ml->pix;
            $vi = 0;
            for ($y = $y0; $y < $y1; $y++) {
                $pi = ($y * $lw + $x0) * 4;
                for ($x = 0; $x < $w; $x++, $pi += 4, $vi++) {
                    $values[$vi] = $md->alpha ? $mp[$pi + 3] : min((54 * $mp[$pi] + 183 * $mp[$pi + 1] + 19 * $mp[$pi + 2] + 128) >> 8, 255); // luminance, of premultiplied colors
                }
            }
            $this->release($ml);
            // The mask's content shows within its region only.
            $rp = new Path; $rp->rect($region[0], $region[1], $region[2], $region[3]);
            $this->flat->reset();
            $rp->flatten($m, true, $this->flat);
            $cr = $this->cover($this->flat, false); $cov = $this->cov;
            for ($y = $y0; $y < $y1; $y++) {
                for ($x = $x0; $x < $x1; $x++) {
                    $i = ($y - $y0) * $w + $x - $x0;
                    if ($cr === null || $x < $cr[0] || $x >= $cr[2] || $y < $cr[1] || $y >= $cr[3]) { $values[$i] = 0; continue; }
                    $values[$i] = self::div255($values[$i] * $cov[($y - $cr[1]) * ($cr[2] - $cr[0]) + $x - $cr[0]]);
                }
            }
            $this->scale($l, $values);
        } finally {
            $f->free($values);
        }
    }
}
