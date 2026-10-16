<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Compiles a document's elements into nodes, as mygo's internal/svg
 * compiler: shapes with their paints resolved, groups, nested svg, use
 * and symbol elements, gradients, clip paths and masks.
 */
final class Compiler
{
    private const SHAPES = ['path' => true, 'rect' => true, 'circle' => true, 'ellipse' => true, 'line' => true, 'polyline' => true, 'polygon' => true];

    /** @var array<string, Elem> */
    private array $ids = [];
    /** @var \SplObjectStorage<Elem, Style> the style of an element where the document has it */
    private \SplObjectStorage $styles;
    /** @var \SplObjectStorage<Elem, ?Gradient> */
    private \SplObjectStorage $grads;
    /** @var \SplObjectStorage<Elem, ?ClipDef> */
    private \SplObjectStorage $clips;
    /** @var \SplObjectStorage<Elem, ?MaskDef> */
    private \SplObjectStorage $masks;
    /** @var list<Elem> the elements being drawn by use elements, which may not use themselves */
    private array $using = [];
    /** How many more nodes the document may compile to. */
    private int $budget = 1 << 17;
    /** The size of the viewport, for percentages. */
    private float $vw = 0, $vh = 0;
    private Style $base;
    /** Set once a paint is currentColor. */
    public bool $current = false;

    public function __construct(private Elem $root)
    {
        $this->styles = new \SplObjectStorage; $this->grads = new \SplObjectStorage; $this->clips = new \SplObjectStorage; $this->masks = new \SplObjectStorage;
        $this->base = new Style;
        $root->walk(function (Elem $e) {
            $id = $e->attrs['id'] ?? '';
            if ($id !== '' && !isset($this->ids[$id])) { $this->ids[$id] = $e; }
        });
    }

    private function diag(): float { return sqrt(($this->vw * $this->vw + $this->vh * $this->vh) / 2); }

    /** The attribute $name of $e as a length, with $def when it is missing or in error, percentages of $base. */
    private function length(Elem $e, string $name, float $base, float $def): float
    {
        if (isset($e->attrs[$name]) && ($l = Style::parseLength($e->attrs[$name], $base)) !== null) { return $l; }
        return $def;
    }

    private function style(Elem $e, Style $parent): Style { return Style::compute($e, $parent, $this->vw, $this->vh); }

    /** The style of $e where the document has it: for the content of gradients, clip paths and masks, which inherit from where they are, not from what they paint. */
    private function domStyle(Elem $e): Style
    {
        if (isset($this->styles[$e])) { return $this->styles[$e]; }
        $s = $this->style($e, $e->parent === null ? $this->base : $this->domStyle($e->parent));
        $this->styles[$e] = $s;
        return $s;
    }

    /** Compiles the document: [root node or null, viewBox, aspect, w, h]. */
    public function doc(): array
    {
        $root = $this->root;
        $vb = Style::parseViewBox($root->attrs['viewBox'] ?? '');
        $w = self::absLength($root->attrs['width'] ?? ''); $h = self::absLength($root->attrs['height'] ?? '');
        if ($w !== null && $h !== null) {}
        elseif ($vb !== null && $w !== null) { $h = $w * $vb[3] / $vb[2]; }
        elseif ($vb !== null && $h !== null) { $w = $h * $vb[2] / $vb[3]; }
        elseif ($vb !== null) { $w = $vb[2]; $h = $vb[3]; }
        else { $w ??= 300.0; $h ??= 150.0; }
        $vb ??= [0.0, 0.0, $w, $h];
        $this->vw = $vb[2]; $this->vh = $vb[3];
        $out = [null, $vb, Style::parseAspect($root->attrs['preserveAspectRatio'] ?? ''), max($w, 0), max($h, 0)];
        $st = $this->style($root, $this->base);
        if (!$st->display) { return $out; }
        $n = new Node;
        $this->group($n, $root, $st);
        $this->finish($n, $st);
        $out[0] = $n;
        return $out;
    }

    /** An absolute length, ignoring percentages. */
    private static function absLength(string $v): ?float
    {
        return str_ends_with(trim($v), '%') ? null : Style::parseLength($v, 0);
    }

    /** Compiles the children of $e into $n. */
    private function group(Node $n, Elem $e, Style $st): void
    {
        foreach ($e->kids as $k) {
            if (($kn = $this->elem($k, $st)) !== null) { $n->kids[] = $kn; }
        }
    }

    /** Gives a node its opacity, clipping and masking, and works out its box and whether it is single and flat. */
    private function finish(Node $n, Style $st): void
    {
        $n->opacity = $st->opacity;
        $n->clip = $this->clipRef($st->clipPath);
        $n->mask = $this->maskRef($st->mask);
        if ($n->shape !== null) {
            $n->bbox = $n->shape->path->bounds();
            $n->single = $n->shape->fill[0] === Shape::NONE || $n->shape->stroke[0] === Shape::NONE;
        } else {
            foreach ($n->kids as $k) {
                if ($k->bbox === null) { continue; }
                $b = Matrix::transformBox($k->bbox, $k->m);
                $n->bbox = $n->bbox === null ? $b : Matrix::union($n->bbox, $b);
            }
            $n->single = $n->kids === [] || (count($n->kids) === 1 && $n->kids[0]->flat);
        }
        $n->flat = $n->clip !== null || $n->mask !== null || $n->single;
    }

    /** Compiles a graphics element, or returns null for others. */
    private function elem(Elem $e, Style $parent): ?Node
    {
        if (!in_array($e->name, ['g', 'a', 'switch', 'svg', 'use'], true) && !isset(self::SHAPES[$e->name])) { return null; }
        if ($this->budget <= 0) { return null; }
        $this->budget--;
        $st = $this->style($e, $parent);
        if (!$st->display) { return null; }
        $n = new Node;
        switch ($e->name) {
            case 'g': case 'a':
                $this->group($n, $e, $st);
                break;
            case 'switch': // the first child whose conditions hold, of which only requiredExtensions fails: no extension is supported
                foreach ($e->kids as $k) {
                    if (($k->attrs['requiredExtensions'] ?? '') !== '' || $k->name === 'title' || $k->name === 'desc' || $k->name === 'metadata') { continue; }
                    if (($kn = $this->elem($k, $st)) !== null) { $n->kids[] = $kn; }
                    break;
                }
                break;
            case 'svg':
                $x = $this->length($e, 'x', $this->vw, 0); $y = $this->length($e, 'y', $this->vh, 0);
                $w = $this->length($e, 'width', $this->vw, $this->vw); $h = $this->length($e, 'height', $this->vh, $this->vh);
                if ($w <= 0 || $h <= 0) { return null; }
                $n->kids = $this->viewport($e, $st, $x, $y, $w, $h);
                break;
            case 'use':
                if (!$this->use($n, $e, $st)) { return null; }
                break;
            default:
                if (!$st->visible) { return null; }
                $pa = $this->shapePath($e);
                if ($pa->ops === []) { return null; }
                $s = new Shape($pa);
                $s->fill = $this->paint($st->fill, $st); $s->stroke = $this->paint($st->stroke, $st);
                $s->fillOpacity = $st->fillOpacity; $s->strokeOpacity = $st->strokeOpacity; $s->evenOdd = $st->fillEvenOdd;
                $s->width = $st->strokeWidth; $s->miter = $st->miterLimit; $s->cap = $st->cap; $s->join = $st->join;
                $s->dashes = $st->dashes; $s->dashOffset = $st->dashOffset; $s->strokeFirst = $st->strokeFirst;
                $n->shape = $s;
        }
        if ($n->shape === null && $n->kids === []) { return null; }
        if (isset($e->attrs['transform'])) { $n->m = Matrix::mul(Matrix::parse($e->attrs['transform']), $n->m); }
        $this->finish($n, $st);
        return $n;
    }

    /** Compiles the children of an svg or symbol element $e drawn in a viewport $w × $h at ($x, $y), which clips them. @return list<Node> */
    private function viewport(Elem $e, Style $st, float $x, float $y, float $w, float $h): array
    {
        $vw = $this->vw; $vh = $this->vh;
        $inner = new Node;
        if (($vb = Style::parseViewBox($e->attrs['viewBox'] ?? '')) !== null) {
            $inner->m = Style::viewBoxTransform($vb, Style::parseAspect($e->attrs['preserveAspectRatio'] ?? ''), $w, $h);
            $this->vw = $vb[2]; $this->vh = $vb[3];
        } else {
            $this->vw = $w; $this->vh = $h;
        }
        $this->group($inner, $e, $st);
        $this->vw = $vw; $this->vh = $vh;
        if ($inner->kids === []) { return []; }
        $this->finish($inner, $this->base);
        $r = new Path; $r->rect(0, 0, $w, $h);
        $clipped = new Node; $clipped->m = Matrix::translate($x, $y); $clipped->kids = [$inner];
        $this->finish($clipped, $this->base);
        $clipped->clip = new ClipDef;
        $rn = new Node; $rn->shape = new Shape($r);
        $clipped->clip->kids = [$rn];
        $clipped->flat = true;
        return [$clipped];
    }

    /** Compiles the element a use element $e draws into $n. */
    private function use(Node $n, Elem $e, Style $st): bool
    {
        $ref = $this->ids[self::hrefID($e)] ?? null;
        if ($ref === null || in_array($ref, $this->using, true)) { return false; }
        for ($p = $e; $p !== null; $p = $p->parent) { if ($p === $ref) { return false; } } // it would draw itself
        $this->using[] = $ref;
        $x = $this->length($e, 'x', $this->vw, 0); $y = $this->length($e, 'y', $this->vh, 0);
        try {
            if ($ref->name === 'symbol') {
                $sst = $this->style($ref, $st);
                if (!$sst->display) { return false; }
                $w = $this->length($e, 'width', $this->vw, $this->vw); $h = $this->length($e, 'height', $this->vh, $this->vh);
                if ($w <= 0 || $h <= 0) { return false; }
                $n->kids = $this->viewport($ref, $sst, $x, $y, $w, $h);
                return $n->kids !== [];
            }
            $kid = $this->elem($ref, $st);
            if ($kid === null) { return false; }
            $n->m = Matrix::translate($x, $y);
            $n->kids = [$kid];
            return true;
        } finally {
            array_pop($this->using);
        }
    }

    private static function hrefID(Elem $e): string
    {
        $v = trim($e->attrs['href'] ?? '');
        return str_starts_with($v, '#') ? substr($v, 1) : '';
    }

    /** The path of a shape element. */
    private function shapePath(Elem $e): Path
    {
        $pa = new Path; $vw = $this->vw; $vh = $this->vh; $diag = $this->diag();
        switch ($e->name) {
            case 'path': return Path::parse($e->attrs['d'] ?? '');
            case 'rect':
                $x = $this->length($e, 'x', $vw, 0); $y = $this->length($e, 'y', $vh, 0);
                $w = $this->length($e, 'width', $vw, 0); $h = $this->length($e, 'height', $vh, 0);
                if ($w <= 0 || $h <= 0) { break; }
                [$rx, $ry] = $this->radii($e, $vw, $vh);
                $pa->rect($x, $y, $w, $h, min($rx, $w / 2), min($ry, $h / 2));
                break;
            case 'circle':
                if (($r = $this->length($e, 'r', $diag, 0)) > 0) { $pa->ellipse($this->length($e, 'cx', $vw, 0), $this->length($e, 'cy', $vh, 0), $r, $r); }
                break;
            case 'ellipse':
                [$rx, $ry] = $this->radii($e, $vw, $vh);
                if ($rx > 0 && $ry > 0) { $pa->ellipse($this->length($e, 'cx', $vw, 0), $this->length($e, 'cy', $vh, 0), $rx, $ry); }
                break;
            case 'line':
                $pa->moveTo($this->length($e, 'x1', $vw, 0), $this->length($e, 'y1', $vh, 0));
                $pa->lineTo($this->length($e, 'x2', $vw, 0), $this->length($e, 'y2', $vh, 0));
                break;
            case 'polyline': case 'polygon':
                $n = Scanner::numbers($e->attrs['points'] ?? '');
                for ($i = 0; $i + 1 < count($n); $i += 2) { $i === 0 ? $pa->moveTo($n[0], $n[1]) : $pa->lineTo($n[$i], $n[$i + 1]); }
                if ($e->name === 'polygon') { $pa->close(); }
                break;
        }
        return $pa;
    }

    /** The rx and ry of a rect or ellipse: one that is missing, or in error, is the other. @return array{0: float, 1: float} */
    private function radii(Elem $e, float $vw, float $vh): array
    {
        $rx = Style::parseLength($e->attrs['rx'] ?? '', $vw); $ry = Style::parseLength($e->attrs['ry'] ?? '', $vh);
        if ($rx !== null && $rx < 0) { $rx = null; }
        if ($ry !== null && $ry < 0) { $ry = null; }
        return [$rx ?? $ry ?? 0.0, $ry ?? $rx ?? 0.0];
    }

    /** Resolves a fill or stroke of an element of style $st to [kind, color, gradient]. */
    private function paint(array $ps, Style $st): array
    {
        switch ($ps[0]) {
            case Style::COLOR: return [Shape::COLOR, $ps[1], null];
            case Style::CURRENT:
                if ($st->colorSet) { return [Shape::COLOR, $st->color, null]; }
                $this->current = true;
                return [Shape::CURRENT, Style::BLACK, null];
            case Style::URL:
                $el = $this->ids[$ps[2]] ?? null;
                if ($el !== null && ($el->name === 'linearGradient' || $el->name === 'radialGradient')) {
                    $g = $this->gradient($el);
                    return $g === null || $g->stops === [] ? [Shape::NONE, Style::BLACK, null] : [Shape::GRADIENT, Style::BLACK, $g];
                }
                return $this->paint([$ps[3], $ps[1], '', Style::NONE], $st); // what the reference falls back to, or nothing
        }
        return [Shape::NONE, Style::BLACK, null];
    }

    /** Compiles a gradient element, with what it inherits from the gradients it refers to. */
    private function gradient(Elem $e): ?Gradient
    {
        if (isset($this->grads[$e])) { return $this->grads[$e]; }
        $this->grads[$e] = null;
        $chain = [$e];
        for ($cur = $e; count($chain) < 32;) {
            $ref = $this->ids[self::hrefID($cur)] ?? null;
            if ($ref === null || ($ref->name !== 'linearGradient' && $ref->name !== 'radialGradient') || in_array($ref, $chain, true)) { break; }
            $chain[] = $ref; $cur = $ref;
        }
        $g = new Gradient; $g->radial = $e->name === 'radialGradient';
        // An attribute along the chain; the coordinates come from gradients of the same kind only.
        $attr = function (string $name, bool $own) use ($chain, $e): ?string {
            foreach ($chain as $el) {
                if ($own && $el->name !== $e->name) { continue; }
                if (isset($el->attrs[$name])) { return $el->attrs[$name]; }
            }
            return null;
        };
        if (($v = $attr('gradientUnits', false)) !== null) { $g->bbox = trim($v) !== 'userSpaceOnUse'; }
        if (($v = $attr('gradientTransform', false)) !== null) { $g->m = Matrix::parse($v); }
        if (($v = $attr('spreadMethod', false)) !== null) { $g->spread = match (trim($v)) { 'reflect' => Gradient::REFLECT, 'repeat' => Gradient::REPEAT, default => Gradient::PAD }; }
        $coord = function (string $name, string $def, float $base) use ($attr, $g): float {
            $v = $attr($name, true) ?? $def;
            if ($g->bbox) { $base = 1; }
            return Style::parseLength($v, $base) ?? Style::parseLength($def, $base);
        };
        $diag = $this->diag();
        if ($g->radial) {
            $g->cx = $coord('cx', '50%', $this->vw); $g->cy = $coord('cy', '50%', $this->vh); $g->r = $coord('r', '50%', $diag);
            $g->fx = $attr('fx', true) !== null ? $coord('fx', '50%', $this->vw) : $g->cx;
            $g->fy = $attr('fy', true) !== null ? $coord('fy', '50%', $this->vh) : $g->cy;
            $g->fr = $coord('fr', '0%', $diag);
            $d = hypot($g->fx - $g->cx, $g->fy - $g->cy);
            if ($d > $g->r * 0.999 && $d > 0) { $k = $g->r * 0.999 / $d; $g->fx = $g->cx + ($g->fx - $g->cx) * $k; $g->fy = $g->cy + ($g->fy - $g->cy) * $k; } // SVG 1.1 moves a focal point outside the circle onto it
        } else {
            $g->x1 = $coord('x1', '0%', $this->vw); $g->y1 = $coord('y1', '0%', $this->vh);
            $g->x2 = $coord('x2', '100%', $this->vw); $g->y2 = $coord('y2', '0%', $this->vh);
        }
        foreach ($chain as $el) { // the stops of the first gradient along the chain that has some
            if (($g->stops = $this->stops($el)) !== []) { break; }
        }
        $this->grads[$e] = $g;
        return $g;
    }

    /** @return list<array{0: float, 1: array, 2: bool, 3: float}> */
    private function stops(Elem $e): array
    {
        $out = []; $last = 0.0;
        foreach ($e->kids as $k) {
            if ($k->name !== 'stop') { continue; }
            $st = $this->domStyle($k);
            $off = isset($k->attrs['offset']) ? Style::parseOpacity(trim($k->attrs['offset']), 0) : 0.0;
            $off = max($off, $last); $last = $off;
            $s = [$off, $st->stopColor[1], false, $st->stopOpacity];
            if ($st->stopColor[0] === Style::CURRENT) {
                if ($st->colorSet) { $s[1] = $st->color; } else { $s[2] = true; $this->current = true; }
            }
            $out[] = $s;
        }
        return $out;
    }

    /** Compiles the clipPath an element refers to, if it is one. */
    private function clipRef(string $id): ?ClipDef
    {
        $el = $this->ids[$id] ?? null;
        if ($id === '' || $el === null || $el->name !== 'clipPath') { return null; }
        if (isset($this->clips[$el])) { return $this->clips[$el]; } // null while it is being compiled: it clips itself
        $this->clips[$el] = null;
        $cd = new ClipDef; $cd->m = Matrix::parse($el->attrs['transform'] ?? ''); $cd->bbox = trim($el->attrs['clipPathUnits'] ?? '') === 'objectBoundingBox';
        $st = $this->domStyle($el);
        foreach ($el->kids as $k) {
            if (($n = $this->clipKid($k, $st, 0)) !== null) { $cd->kids[] = $n; }
        }
        $cd->clip = $this->clipRef($st->clipPath);
        $this->clips[$el] = $cd;
        return $cd;
    }

    /** Compiles a child of a clipPath: a shape, or a use of one. Only their geometry clips, filled with the clip-rule. */
    private function clipKid(Elem $k, Style $parent, int $depth): ?Node
    {
        if ($this->budget <= 0 || $depth > 1) { return null; }
        $this->budget--;
        $st = $this->style($k, $parent);
        if (!$st->display || !$st->visible) { return null; }
        $n = new Node; $n->m = Matrix::parse($k->attrs['transform'] ?? '');
        if ($k->name === 'use') {
            $ref = $this->ids[self::hrefID($k)] ?? null;
            if ($ref === null || !isset(self::SHAPES[$ref->name])) { return null; }
            $kid = $this->clipKid($ref, $st, $depth + 1);
            if ($kid === null) { return null; }
            $n->m = Matrix::mul($n->m, Matrix::translate($this->length($k, 'x', $this->vw, 0), $this->length($k, 'y', $this->vh, 0)));
            $n->kids = [$kid];
        } elseif (isset(self::SHAPES[$k->name])) {
            $pa = $this->shapePath($k);
            if ($pa->ops === []) { return null; }
            $n->shape = new Shape($pa); $n->shape->evenOdd = $st->clipEvenOdd;
        } else {
            return null;
        }
        return $n;
    }

    /** Compiles the mask an element refers to, if it is one. */
    private function maskRef(string $id): ?MaskDef
    {
        $el = $this->ids[$id] ?? null;
        if ($id === '' || $el === null || $el->name !== 'mask') { return null; }
        if (isset($this->masks[$el])) { return $this->masks[$el]; }
        $this->masks[$el] = null;
        $md = new MaskDef;
        $md->units = trim($el->attrs['maskUnits'] ?? '') !== 'userSpaceOnUse';
        $md->content = trim($el->attrs['maskContentUnits'] ?? '') === 'objectBoundingBox';
        $bw = $this->vw; $bh = $this->vh;
        if ($md->units) { $bw = 1; $bh = 1; }
        $md->region = [$this->length($el, 'x', $bw, -0.1 * $bw), $this->length($el, 'y', $bh, -0.1 * $bh), $this->length($el, 'width', $bw, 1.2 * $bw), $this->length($el, 'height', $bh, 1.2 * $bh)];
        $st = $this->domStyle($el);
        $md->alpha = $st->maskAlpha;
        foreach ($el->kids as $k) {
            if (($n = $this->elem($k, $st)) !== null) { $md->kids[] = $n; }
        }
        $this->masks[$el] = $md;
        return $md;
    }
}
