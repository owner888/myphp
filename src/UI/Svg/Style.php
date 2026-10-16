<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * The computed style of an element, as mygo's internal/svg style: colors
 * are [r, g, b, a] from 0 to 1 (straight); a paint as written is
 * [kind, color, id, fallback] — none, a color, currentColor, or a
 * reference to a gradient with what to paint when it is not one.
 */
final class Style
{
    public const NONE = 0, COLOR = 1, CURRENT = 2, URL = 3;
    public const BLACK = [0.0, 0.0, 0.0, 1.0];
    public const CAP_BUTT = 0, CAP_ROUND = 1, CAP_SQUARE = 2;
    public const JOIN_MITER = 0, JOIN_ROUND = 1, JOIN_BEVEL = 2;

    /** The properties that attributes, style sheets and style attributes set. */
    public const PROPERTIES = [
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true,
        'stroke' => true, 'stroke-width' => true, 'stroke-opacity' => true, 'stroke-linecap' => true,
        'stroke-linejoin' => true, 'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'opacity' => true, 'color' => true, 'display' => true, 'visibility' => true, 'paint-order' => true,
        'clip-path' => true, 'clip-rule' => true, 'mask' => true, 'mask-type' => true,
        'stop-color' => true, 'stop-opacity' => true,
    ];

    // Inherited.
    public array $fill = [self::COLOR, self::BLACK, '', self::NONE];
    public array $stroke = [self::NONE, self::BLACK, '', self::NONE];
    public float $fillOpacity = 1, $strokeOpacity = 1;
    public bool $fillEvenOdd = false, $clipEvenOdd = false;
    public float $strokeWidth = 1, $miterLimit = 4;
    public int $cap = self::CAP_BUTT, $join = self::JOIN_MITER;
    /** @var list<float> */
    public array $dashes = [];
    public float $dashOffset = 0;
    /** The color property, which currentColor stands for; until a document sets it, currentColor is the color it is drawn with. */
    public array $color = self::BLACK;
    public bool $colorSet = false;
    public bool $visible = true, $strokeFirst = false;
    // Not inherited.
    public float $opacity = 1;
    public bool $display = true;
    public string $clipPath = '', $mask = '';
    public bool $maskAlpha = false;
    public array $stopColor = [self::COLOR, self::BLACK, '', self::NONE];
    public float $stopOpacity = 1;

    /** The style of $e, a child of an element of style $parent, with lengths in percent of the viewport $vw × $vh. */
    public static function compute(Elem $e, Style $parent, float $vw, float $vh): self
    {
        $s = clone $parent;
        $s->opacity = 1; $s->display = true; $s->clipPath = ''; $s->mask = ''; $s->maskAlpha = false;
        $s->stopColor = [self::COLOR, self::BLACK, '', self::NONE]; $s->stopOpacity = 1;
        $diag = sqrt(($vw * $vw + $vh * $vh) / 2);
        foreach ($e->props as [$name, $v]) {
            if ($v === 'inherit') { $s->inherit($name, $parent); continue; }
            switch ($name) {
                case 'fill': if (($p = self::parsePaint($v)) !== null) { $s->fill = $p; } break;
                case 'stroke': if (($p = self::parsePaint($v)) !== null) { $s->stroke = $p; } break;
                case 'fill-opacity': $s->fillOpacity = self::parseOpacity($v, $s->fillOpacity); break;
                case 'stroke-opacity': $s->strokeOpacity = self::parseOpacity($v, $s->strokeOpacity); break;
                case 'opacity': $s->opacity = self::parseOpacity($v, $s->opacity); break;
                case 'stop-opacity': $s->stopOpacity = self::parseOpacity($v, $s->stopOpacity); break;
                case 'fill-rule': if ($v === 'evenodd' || $v === 'nonzero') { $s->fillEvenOdd = $v === 'evenodd'; } break;
                case 'clip-rule': if ($v === 'evenodd' || $v === 'nonzero') { $s->clipEvenOdd = $v === 'evenodd'; } break;
                case 'stroke-width': if (($w = self::parseLength($v, $diag)) !== null && $w >= 0) { $s->strokeWidth = $w; } break;
                case 'stroke-miterlimit': if (($l = Scanner::parseNumber($v)) !== null && $l >= 1) { $s->miterLimit = $l; } break;
                case 'stroke-linecap': $s->cap = match ($v) { 'butt' => self::CAP_BUTT, 'round' => self::CAP_ROUND, 'square' => self::CAP_SQUARE, default => $s->cap }; break;
                case 'stroke-linejoin': $s->join = match ($v) { 'miter', 'miter-clip', 'arcs' => self::JOIN_MITER, 'round' => self::JOIN_ROUND, 'bevel' => self::JOIN_BEVEL, default => $s->join }; break;
                case 'stroke-dasharray': $s->dashes = self::parseDashes($v, $diag); break;
                case 'stroke-dashoffset': if (($o = self::parseLength($v, $diag)) !== null) { $s->dashOffset = $o; } break;
                case 'color':
                    if (strcasecmp($v, 'currentColor') === 0) { break; } // the color it inherits
                    if (($c = self::parseColor($v)) !== null) { $s->color = $c; $s->colorSet = true; }
                    break;
                case 'display': $s->display = $v !== 'none'; break;
                case 'visibility': if ($v === 'visible' || $v === 'hidden' || $v === 'collapse') { $s->visible = $v === 'visible'; } break;
                case 'paint-order': $s->strokeFirst = self::strokeFirst($v); break;
                case 'clip-path': $s->clipPath = self::urlID($v); break;
                case 'mask': $s->mask = self::urlID($v); break;
                case 'mask-type': $s->maskAlpha = $v === 'alpha'; break;
                case 'stop-color':
                    if (strcasecmp($v, 'currentColor') === 0) { $s->stopColor = [self::CURRENT, self::BLACK, '', self::NONE]; }
                    elseif (($c = self::parseColor($v)) !== null) { $s->stopColor = [self::COLOR, $c, '', self::NONE]; }
                    break;
            }
        }
        return $s;
    }

    /** Takes a property from the parent's style, undoing what an earlier declaration set. */
    private function inherit(string $name, Style $p): void
    {
        $prop = match ($name) {
            'fill' => 'fill', 'stroke' => 'stroke', 'fill-opacity' => 'fillOpacity', 'stroke-opacity' => 'strokeOpacity',
            'fill-rule' => 'fillEvenOdd', 'clip-rule' => 'clipEvenOdd', 'stroke-width' => 'strokeWidth', 'stroke-miterlimit' => 'miterLimit',
            'stroke-linecap' => 'cap', 'stroke-linejoin' => 'join', 'stroke-dasharray' => 'dashes', 'stroke-dashoffset' => 'dashOffset',
            'visibility' => 'visible', 'paint-order' => 'strokeFirst', 'opacity' => 'opacity', 'display' => 'display',
            'clip-path' => 'clipPath', 'mask' => 'mask', 'mask-type' => 'maskAlpha', 'stop-color' => 'stopColor', 'stop-opacity' => 'stopOpacity',
            'color' => 'color', default => null,
        };
        if ($prop === null) { return; }
        $this->$prop = $p->$prop;
        if ($name === 'color') { $this->colorSet = $p->colorSet; }
    }

    /** Whether a paint-order paints the stroke before the fill. */
    private static function strokeFirst(string $v): bool
    {
        foreach (preg_split('/\s+/', trim($v)) as $f) {
            if ($f === 'stroke') { return true; }
            if ($f === 'fill') { return false; }
        }
        return false;
    }

    /** The id url(#id) refers to, or ''. */
    public static function urlID(string $v): string
    {
        $v = trim($v);
        if (!str_starts_with($v, 'url(') || ($end = strpos($v, ')')) === false) { return ''; }
        $ref = trim(trim(substr($v, 4, $end - 4)), "\"'");
        return str_starts_with($ref, '#') ? substr($ref, 1) : '';
    }

    /** @return ?array{0: int, 1: array, 2: string, 3: int} */
    public static function parsePaint(string $v): ?array
    {
        if ($v === 'none') { return [self::NONE, self::BLACK, '', self::NONE]; }
        if (strcasecmp($v, 'currentColor') === 0) { return [self::CURRENT, self::BLACK, '', self::NONE]; }
        if (str_starts_with($v, 'url(')) {
            $id = self::urlID($v);
            if ($id === '') { return null; }
            $p = [self::URL, self::BLACK, $id, self::NONE];
            $rest = trim(substr($v, strpos($v, ')') + 1));
            if ($rest !== '') {
                $fb = self::parsePaint($rest);
                if ($fb === null || $fb[0] === self::URL) { return null; }
                $p[3] = $fb[0]; $p[1] = $fb[1];
            }
            return $p;
        }
        $c = self::parseColor($v);
        return $c === null ? null : [self::COLOR, $c, '', self::NONE];
    }

    /** A CSS color: a name, #rgb, #rgba, #rrggbb, #rrggbbaa, rgb(), rgba(), hsl(), hsla() or transparent; null for none. */
    public static function parseColor(string $v): ?array
    {
        $v = trim($v);
        if (($i = strpos($v, 'icc-color(')) !== false && $i > 0) { $v = trim(substr($v, 0, $i)); } // an ICC color follows the sRGB one, which is all that is drawn
        if ($v === '') { return null; }
        if ($v[0] === '#') { return self::parseHex(substr($v, 1)); }
        $lower = strtolower($v);
        if (($open = strpos($lower, '(')) !== false && $open > 0 && str_ends_with($lower, ')')) {
            $name = substr($lower, 0, $open); $args = substr($lower, $open + 1, -1);
            return match ($name) { 'rgb', 'rgba' => self::parseRGB($args), 'hsl', 'hsla' => self::parseHSL($args), default => null };
        }
        if ($lower === 'transparent') { return [0.0, 0.0, 0.0, 0.0]; }
        if (isset(Colors::NAMED[$lower])) { $c = Colors::NAMED[$lower]; return [($c >> 16) / 255, ($c >> 8 & 0xff) / 255, ($c & 0xff) / 255, 1.0]; }
        return null;
    }

    private static function parseHex(string $h): ?array
    {
        $n = strlen($h);
        if (($n !== 3 && $n !== 4 && $n !== 6 && $n !== 8) || !ctype_xdigit($h)) { return null; }
        $d = array_map('hexdec', str_split($h));
        if ($n <= 4) { return [$d[0] * 17 / 255, $d[1] * 17 / 255, $d[2] * 17 / 255, $n === 4 ? $d[3] * 17 / 255 : 1.0]; }
        return [($d[0] * 16 + $d[1]) / 255, ($d[2] * 16 + $d[3]) / 255, ($d[4] * 16 + $d[5]) / 255, $n === 8 ? ($d[6] * 16 + $d[7]) / 255 : 1.0];
    }

    /** The arguments of rgb() or hsl(), separated by commas or spaces, with the alpha after a slash in the latter syntax. @return ?list<string> */
    private static function colorArgs(string $s): ?array
    {
        $f = preg_split('/[,\s]+/', trim(str_replace('/', ' / ', $s)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($f) === 5 && $f[3] === '/') { return [$f[0], $f[1], $f[2], $f[4]]; }
        return in_array('/', $f, true) ? null : $f;
    }

    private static function parseRGB(string $s): ?array
    {
        $args = self::colorArgs($s);
        if ($args === null || (count($args) !== 3 && count($args) !== 4)) { return null; }
        $c = [0.0, 0.0, 0.0, 1.0];
        foreach ($args as $i => $a) {
            $pct = str_ends_with($a, '%');
            $n = Scanner::parseNumber($pct ? substr($a, 0, -1) : $a);
            if ($n === null) { return null; }
            $c[$i] = max(0.0, min(1.0, $pct ? $n / 100 : ($i === 3 ? $n : $n / 255)));
        }
        return $c;
    }

    private static function parseHSL(string $s): ?array
    {
        $args = self::colorArgs($s);
        if ($args === null || (count($args) !== 3 && count($args) !== 4)) { return null; }
        $h = $args[0]; $scale = 1.0;
        if (str_ends_with($h, 'deg')) { $h = substr($h, 0, -3); }
        elseif (str_ends_with($h, 'grad')) { $h = substr($h, 0, -4); $scale = 0.9; }
        elseif (str_ends_with($h, 'rad')) { $h = substr($h, 0, -3); $scale = 180 / M_PI; }
        elseif (str_ends_with($h, 'turn')) { $h = substr($h, 0, -4); $scale = 360.0; }
        $hue = Scanner::parseNumber($h);
        if ($hue === null) { return null; }
        $v = [0.0, 0.0, 1.0];
        foreach (array_slice($args, 1) as $i => $a) {
            $pct = str_ends_with($a, '%');
            $n = Scanner::parseNumber($pct ? substr($a, 0, -1) : $a);
            if ($n === null || ($i < 2 && !$pct)) { return null; }
            $v[$i] = max(0.0, min(1.0, $pct ? $n / 100 : $n));
        }
        $hue = fmod($hue * $scale, 360); if ($hue < 0) { $hue += 360; }
        [$sat, $light] = $v;
        $f = function (float $n) use ($hue, $sat, $light): float {
            $k = fmod($n + $hue / 30, 12); $a = $sat * min($light, 1 - $light);
            return $light - $a * max(-1.0, min(min($k - 3, 9 - $k), 1.0));
        };
        return [$f(0), $f(8), $f(4), $v[2]];
    }

    /** A number or percentage, clamped to [0, 1], or $old. */
    public static function parseOpacity(string $v, float $old): float
    {
        $pct = str_ends_with($v, '%');
        $n = Scanner::parseNumber($pct ? substr($v, 0, -1) : $v);
        if ($n === null) { return $old; }
        return max(0.0, min(1.0, $pct ? $n / 100 : $n));
    }

    /** A length: a number with an optional unit, in pixels, or a percentage of $base; null for none. */
    public static function parseLength(string $v, float $base): ?float
    {
        $p = new Scanner(trim($v));
        $n = $p->number();
        if ($n === null) { return null; }
        return match (strtolower(substr($p->s, $p->i))) {
            '', 'px' => $n, '%' => $n * $base / 100, 'pt' => $n * 4 / 3, 'pc' => $n * 16, 'in' => $n * 96,
            'cm' => $n * 96 / 2.54, 'mm' => $n * 96 / 25.4, 'q' => $n * 96 / 101.6, 'em', 'rem' => $n * 16, 'ex' => $n * 8,
            default => null,
        };
    }

    /** A dash array: [] for a solid line, as none or an array in error asks, and of even length otherwise. @return list<float> */
    private static function parseDashes(string $v, float $diag): array
    {
        if ($v === 'none') { return []; }
        $out = []; $total = 0.0;
        foreach (preg_split('/[,\s]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $f) {
            $l = self::parseLength($f, $diag);
            if ($l === null || $l < 0) { return []; }
            $out[] = $l; $total += $l;
        }
        if ($total <= 0) { return []; }
        return count($out) % 2 === 1 ? [...$out, ...$out] : $out;
    }

    /** A preserveAspectRatio: how a viewBox fits its viewport, as [none, ax, ay, slice] — ax/ay 0 at the start, 0.5 in the middle, 1 at the end. */
    public static function parseAspect(string $v): array
    {
        $a = [false, 0.5, 0.5, false];
        $f = preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY);
        if ($f !== [] && $f[0] === 'defer') { array_shift($f); }
        if ($f === []) { return $a; }
        if ($f[0] === 'none') { $a[0] = true; return $a; }
        $pos = ['Min' => 0.0, 'Mid' => 0.5, 'Max' => 1.0];
        $al = $f[0];
        if (strlen($al) !== 8 || $al[0] !== 'x' || $al[4] !== 'Y' || !isset($pos[substr($al, 1, 3)]) || !isset($pos[substr($al, 5, 3)])) { return $a; }
        return [false, $pos[substr($al, 1, 3)], $pos[substr($al, 5, 3)], isset($f[1]) && $f[1] === 'slice'];
    }

    /** The transform mapping viewBox $vb to a viewport $w × $h as aspect $a says. */
    public static function viewBoxTransform(array $vb, array $a, float $w, float $h): array
    {
        if ($vb[2] <= 0 || $vb[3] <= 0) { return Matrix::IDENTITY; }
        $sx = $w / $vb[2]; $sy = $h / $vb[3];
        if ($a[0]) { return [$sx, 0.0, 0.0, $sy, -$vb[0] * $sx, -$vb[1] * $sy]; }
        $s = $a[3] ? max($sx, $sy) : min($sx, $sy);
        return [$s, 0.0, 0.0, $s, -$vb[0] * $s + ($w - $vb[2] * $s) * $a[1], -$vb[1] * $s + ($h - $vb[3] * $s) * $a[2]];
    }

    /** A viewBox as [x, y, w, h], or null. */
    public static function parseViewBox(string $v): ?array
    {
        $n = Scanner::numbers($v);
        return count($n) === 4 && $n[2] > 0 && $n[3] > 0 ? $n : null;
    }
}
