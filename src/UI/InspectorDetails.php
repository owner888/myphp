<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What the inspector says of the node chosen, for the sidebar of its
 * Elements tab (mygo's inspector_details.go): its styles as CSS rules,
 * its own then those it inherits; its box model and the values it has as
 * laid out; what it is, its state and what assistive technology sees.
 * Declarations are [name, value, color or null], the color a swatch
 * before the value.
 */
final class InspectorDetails
{
    /**
     * @param list<Node> $chain the nodes around $n, outermost first
     * @return array{name: string, id: int, source: string, rules: list<array{selector: string, inherited: bool, decls: list<array{0: string, 1: string, 2: ?int}>}>, margin: array, border: array, padding: array, w: float, h: float, computed: list<array{0: string, 1: string, 2: ?int}>, props: list<array{title: string, rows: list<array{0: string, 1: string}>}>}
     */
    public static function describe(Runtime $rt, Inspector $in, Node $n, array $chain): array
    {
        $name = Inspector::name($n, count($chain));
        $sel = $name . (isset($in->keys[$n->id]) ? '#' . $in->keys[$n->id] : '');
        $rules = [['selector' => $sel, 'inherited' => false, 'decls' => self::own($in, $rt, $n)]];
        // What it inherits: the text styles that the nodes around it set and nothing nearer overrides.
        $taken = self::setOf($n->ts);
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $p = $chain[$i];
            $set = array_diff_key(self::setOf($p->ts), $taken);
            if ($set === []) { continue; }
            $taken += $set;
            $rules[] = ['selector' => Inspector::name($p, $i), 'inherited' => true, 'decls' => self::textStyles($p->ts, $set)];
        }
        $pad = $n->sides();
        return ['name' => $name, 'id' => $n->id, 'source' => $in->source, 'rules' => $rules,
            'margin' => $n->margins, 'border' => $n->bordered ? array_fill(0, 4, $n->borderWidth) : $n->borders, 'padding' => $pad,
            'w' => max($n->w - $n->padL() - $n->padR(), 0.0), 'h' => max($n->h - $n->padT() - $n->padB(), 0.0),
            'computed' => self::computed($in, $n, $chain), 'props' => self::properties($rt, $in, $n)];
    }

    /** @return array<string, true> the text styles $ts sets, by name */
    private static function setOf(?TextStyle $ts): array
    {
        if ($ts === null) { return []; }
        $set = [];
        foreach (get_object_vars($ts) as $k => $v) {
            if ($v !== null && $k !== 'fixedLine') { $set[$k] = true; }
        }
        return $set;
    }

    /** The styles $n sets, as CSS declarations. @return list<array{0: string, 1: string, 2: ?int}> */
    private static function own(Inspector $in, Runtime $rt, Node $n): array
    {
        $ds = [];
        $add = function (string $name, string $value, ?int $color = null) use (&$ds): void { $ds[] = [$name, $value, $color]; };
        if ($n->kind === Node::CONTAINER) {
            if ($n->gridCols !== null) {
                $add('display', 'grid');
                $add('grid-template-columns', count($n->gridCols) . ' tracks');
                if ($n->gridRows !== []) { $add('grid-template-rows', count($n->gridRows) . ' tracks'); }
            } elseif ($n->dir === Node::ROW) {
                $add('flex-direction', $n->reverse ? 'row-reverse' : 'row');
            } elseif ($n->reverse) {
                $add('flex-direction', 'column-reverse');
            }
            if ($n->wraps) { $add('flex-wrap', $n->wrapReverse ? 'wrap-reverse' : 'wrap'); }
            $gx = $n->gapX ?? $n->gap; $gy = $n->gapY ?? $n->gap;
            if ($gx == $gy && $gx != 0) { $add('gap', Inspector::px($gx)); } elseif ($gx != $gy) { $add('row-gap', Inspector::px($gy)); $add('column-gap', Inspector::px($gx)); }
            if ($n->alignContent !== Node::AUTO) { $add('align-content', self::align($n->alignContent)); }
            if ($n->justify !== Node::START) { $add('justify-content', self::align($n->justify)); }
            if ($n->align !== Node::AUTO) { $add('align-items', self::align($n->align)); }
        }
        if ($n->self !== Node::AUTO) { $add('align-self', self::align($n->self)); }
        if ($n->dirSet) { $add('direction', $n->rtl ? 'rtl' : 'ltr'); }
        $len = fn(float $v, bool $pct): string => $pct ? Inspector::num($v * 100) . '%' : Inspector::px($v);
        if ($n->attach !== null) {
            $add('position', 'absolute');
            $add('attach', strtolower($n->attach[0]->name) . ' / ' . strtolower($n->attach[1]->name));
        } elseif ($n->absolute) {
            $add('position', 'absolute');
            if ($n->insets === [null, null, null, null]) { $add('left', Inspector::px($n->ax)); $add('top', Inspector::px($n->ay)); }
        } elseif ($n->insets !== [null, null, null, null]) {
            $add('position', 'relative');
        }
        foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
            if ($n->insets[$i] !== null) { $add($side, $len(...$n->insets[$i])); }
        }
        if ($n->wPct !== null) { $add('width', $len($n->wPct, true)); } elseif ($n->fw > 0 && $n->kind !== Node::TEXT) { $add('width', Inspector::px($n->fw)); }
        if ($n->hPct !== null) { $add('height', $len($n->hPct, true)); } elseif ($n->fh > 0 && $n->kind !== Node::TEXT) { $add('height', Inspector::px($n->fh)); }
        if ($n->minWPct !== null) { $add('min-width', $len($n->minWPct, true)); } elseif ($n->minW > 0) { $add('min-width', Inspector::px($n->minW)); }
        if ($n->minHPct !== null) { $add('min-height', $len($n->minHPct, true)); } elseif ($n->minH > 0) { $add('min-height', Inspector::px($n->minH)); }
        if ($n->maxWPct !== null) { $add('max-width', $len($n->maxWPct, true)); } elseif ($n->maxW < INF) { $add('max-width', Inspector::px($n->maxW)); }
        if ($n->maxHPct !== null) { $add('max-height', $len($n->maxHPct, true)); } elseif ($n->maxH < INF) { $add('max-height', Inspector::px($n->maxH)); }
        if ($n->aspect > 0) { $add('aspect-ratio', Inspector::num($n->aspect)); }
        if ($n->grow != 0) { $add('flex-grow', Inspector::num($n->grow)); }
        if ($n->shrink != 1) { $add('flex-shrink', Inspector::num($n->shrink)); }
        if ($n->basisPct !== null) { $add('flex-basis', Inspector::num($n->basisPct * 100) . '%'); } elseif ($n->basis !== null) { $add('flex-basis', Inspector::px($n->basis)); }
        if (($v = self::edges($n->sides())) !== null) { $add('padding', $v); }
        if (($v = self::edges($n->margins)) !== null) { $add('margin', $v); }
        if (($v = self::edges($n->barInsets)) !== null) { $add('scrollbar-insets', $v); }
        $bc = $n->borderColor ?? $in->base?->border ?? 0;
        $style = $n->borderStyle === BorderStyle::Dashed ? ' dashed ' : ' solid ';
        $bv = $n->bordered ? Inspector::px($n->borderWidth) : self::edges($n->borders);
        if ($bv !== null && $n->borderGradient !== null) { // its gradient paints it (mygo's border-image)
            $add('border', $bv . rtrim($style));
            $add('border-image', 'gradient');
        } elseif ($bv !== null) {
            $add('border', $bv . $style . Inspector::color($bc), $bc);
        }
        if ($n->blur > 0) { $add('filter', 'blur(' . Inspector::px($n->blur) . ')'); }
        if (($v = self::edges($n->corners())) !== null) { $add('border-radius', $v); }
        if ($n->scroll !== null) {
            $add(match (true) { $n->scrollX && $n->scrollY => 'overflow', $n->scrollX => 'overflow-x', default => 'overflow-y' }, 'scroll');
        } elseif ($n->clip !== 0) {
            $add(match ($n->clip) { Node::CLIP_X => 'overflow-x', Node::CLIP_Y => 'overflow-y', default => 'overflow' }, 'clip');
        }
        if ($n->material !== null) {
            $add('background', $n->material instanceof \Stringable ? (string)$n->material : 'material'); // a material describes itself as a Stringable
        } elseif (($g = $n->gradient) instanceof ConicGradient) {
            $add('background', 'conic-gradient(from ' . Inspector::num($g->angle) . 'deg, ' . count($g->stops) . ' stops)');
        } elseif ($g !== null && $g->stops !== []) {
            $add('background', ($g instanceof RadialGradient ? 'radial' : 'linear') . '-gradient(' . ($g instanceof LinearGradient ? Inspector::num($g->angle) . 'deg, ' : '') . count($g->stops) . ' stops)');
        } elseif ($g instanceof RadialGradient) {
            $add('background', 'radial-gradient(circle ' . ($g->radius > 0 ? Inspector::num($g->radius) . 'px' : 'farthest-corner') . ' at ' . Inspector::num($g->centerX * 100) . '% ' . Inspector::num($g->centerY * 100) . '%, ' . Inspector::color($g->from, $g->fromAlpha) . ', ' . Inspector::color($g->to, $g->toAlpha) . ')');
        } elseif ($g !== null) {
            $add('background', 'linear-gradient(' . Inspector::num($g->angle) . 'deg, ' . Inspector::color($g->from, $g->fromAlpha) . ', ' . Inspector::color($g->to, $g->toAlpha) . ')');
        } elseif ($n->stripes !== null) {
            $add('background', 'stripes ' . Inspector::color($n->stripes[0], $n->stripes[1]), $n->stripes[0]);
        } elseif ($n->color !== null && $n->kind !== Node::TEXT && $n->kind !== Node::RICH) {
            $add('background-color', Inspector::color($n->color, $n->alpha * $n->bgAlpha), $n->color);
        }
        foreach ($n->shadows as [$x, $y, $blur, $spread, $rgb, $alpha, $inset]) {
            $add('box-shadow', ($inset ? 'inset ' : '') . Inspector::px($x) . ' ' . Inspector::px($y) . ' ' . Inspector::px($blur) . ' ' . Inspector::px($spread) . ' ' . Inspector::color($rgb, $alpha));
        }
        if ($n->opacity < 1) { $add('opacity', Inspector::num($n->opacity)); }
        if ($n->invisible) { $add('visibility', 'hidden'); }
        if ($n->passThrough || $n->ignoresInput()) { $add('pointer-events', 'none'); }
        if ($n->dividers !== null) { $add('dividers', Inspector::px($n->dividers[0]) . ' ' . Inspector::color($n->dividers[1], $n->dividers[2]), $n->dividers[1]); }
        if ($n->cursor !== null) { $add('cursor', $n->cursor->value); }
        if (($r = $rt->trans[$n->id] ?? null) !== null && $r->built + 1 >= $rt->frameNumber) {
            $p = $r->properties(); $what = [];
            if ($p & TransitionState::PROP_POSITION) { $what[] = 'position'; }
            if ($p & TransitionState::PROP_SIZE) { $what[] = 'size'; }
            if ($p & TransitionState::PROP_COLORS) { $what[] = 'colors'; }
            $add('transition', implode(' ', $what) . ' ' . (int)round($r->duration() * 1000) . 'ms');
        }
        if ($n->ts !== null) { array_push($ds, ...self::textStyles($n->ts, self::setOf($n->ts))); }
        if ($n->kind === Node::TEXT || $n->kind === Node::RICH) {
            if ($n->maxLines > 0) { $add('line-clamp', (string)$n->maxLines); }
            if ($n->noWrap || $n->single) { $add('white-space', 'nowrap'); }
            if ($n->ellipsis !== '') { $add('text-overflow', json_encode($n->ellipsis, JSON_UNESCAPED_UNICODE)); }
        }
        return $ds;
    }

    /** The text styles of $ts in $set, as CSS declarations. @param array<string, true> $set @return list<array{0: string, 1: string, 2: ?int}> */
    private static function textStyles(TextStyle $ts, array $set): array
    {
        $ds = [];
        $add = function (string $name, string $value, ?int $color = null) use (&$ds): void { $ds[] = [$name, $value, $color]; };
        if (isset($set['family'])) { $add('font-family', $ts->family); }
        if (isset($set['size'])) { $add('font-size', Inspector::px($ts->size)); }
        if (isset($set['weight'])) { $add('font-weight', (string)$ts->weight); }
        if (isset($set['italic']) && $ts->italic) { $add('font-style', 'italic'); }
        if (isset($set['color'])) { $add('color', Inspector::color($ts->color, $ts->alpha ?? 1.0), $ts->color); }
        if (isset($set['lineHeight'])) { $add('line-height', $ts->fixedLine ? Inspector::px($ts->lineHeight) : Inspector::num($ts->lineHeight)); }
        if (isset($set['align'])) { $add('text-align', match ($ts->align) { Node::CENTER => 'center', Node::END => 'end', default => 'start' }); }
        if (isset($set['spacing'])) { $add('letter-spacing', Inspector::px($ts->spacing)); }
        if (isset($set['features'])) { $add('font-feature-settings', $ts->features); }
        if (isset($set['lang'])) { $add('lang', $ts->lang); }
        $deco = [];
        if ($ts->underline) { $deco[] = $ts->wavy ? 'underline wavy' : 'underline'; }
        if ($ts->strike) { $deco[] = 'line-through'; }
        if ($deco !== []) { $add('text-decoration', implode(' ', $deco)); }
        if (isset($set['background'])) { $add('background-color', Inspector::color($ts->background), $ts->background); }
        if (isset($set['selection'])) { $add('selection-color', Inspector::color($ts->selection), $ts->selection); }
        return $ds;
    }

    /** The text style $n has as laid out: its own over those around it. @param list<Node> $chain */
    public static function resolved(Node $n, array $chain): TextStyle
    {
        $r = null;
        foreach ([...$chain, $n] as $k) {
            if ($k->ts !== null) { $r = $k->ts->over($r); }
        }
        $r ??= new TextStyle();
        if ($n->kind === Node::TEXT || $n->kind === Node::RICH) { // a text knows its size and color, the theme's where nothing set them
            $r = clone $r;
            $r->size ??= $n->fontSize; $r->color ??= $n->fg;
        }
        return $r;
    }

    /** The values $n has as laid out, by name. @param list<Node> $chain @return list<array{0: string, 1: string, 2: ?int}> */
    private static function computed(Inspector $in, Node $n, array $chain): array
    {
        $ts = self::resolved($n, $chain);
        $theme = $in->base;
        $ds = [
            ['display', match (true) { $n->gridCols !== null => 'grid', $n->kind === Node::TEXT || $n->kind === Node::RICH => 'text', $n->kind === Node::IMAGE || $n->svg !== null => 'image', default => 'flex' }, null],
            ['direction', $n->rtl ? 'rtl' : 'ltr', null],
            ['width', Inspector::px($n->w), null],
            ['height', Inspector::px($n->h), null],
            ['left', Inspector::px($n->x), null],
            ['top', Inspector::px($n->y), null],
            ['font-family', $ts->family ?? 'system-ui', null],
            ['font-size', Inspector::px($ts->size ?? $theme?->fontSize ?? 13), null],
            ['font-weight', (string)($ts->weight ?? 400), null],
            ['color', Inspector::color($c = $ts->color ?? $theme?->text ?? 0), $c],
            ['opacity', Inspector::num($n->opacity), null],
        ];
        if ($n->kind === Node::CONTAINER && $n->gridCols === null) { $ds[] = ['flex-direction', $n->dir === Node::ROW ? 'row' : 'column', null]; }
        if ($n->scroll !== null) {
            [$top, $maxY] = $n->scroll->extent();
            $ds[] = ['scroll-top', Inspector::px($top), null];
            $ds[] = ['scroll-top-max', Inspector::px($maxY), null];
            if ($n->scroll instanceof ScrollState) { $ds[] = ['scroll-left', Inspector::px($n->scroll->x), null]; }
        }
        usort($ds, fn($a, $b) => strcmp($a[0], $b[0]));
        return $ds;
    }

    /** What $n is, its state, and what assistive technology sees of it. @return list<array{title: string, rows: list<array{0: string, 1: string}>}> */
    private static function properties(Runtime $rt, Inspector $in, Node $n): array
    {
        $yes = fn(bool $b) => $b ? 'true' : 'false';
        $el = [['type', Inspector::name($n, $n->id === $in->nodes[0]['id'] ? 0 : 1)], ['id', sprintf('%016x', $n->id)]];
        if (isset($in->keys[$n->id])) { $el[] = ['key', var_export($in->keys[$n->id], true)]; }
        if ($in->source !== '') { $el[] = ['built at', $in->source]; }
        $text = match ($n->kind) { Node::TEXT => $n->text ?? '', Node::RICH => $n->plainText(), default => '' };
        if ($text !== '') { $el[] = ['text', json_encode(Inspector::clip($text, 200), JSON_UNESCAPED_UNICODE)]; }
        $state = [['focused', $yes($rt->focused($n->id))], ['hovered', $yes($rt->hovered($n->id))], ['pressed', $yes($rt->pressed($n->id))],
            ['disabled', $yes($n->disabled)], ['moving', $yes($rt->transitioning($n->id))]];
        if ($n->leaving !== 0) { $state[] = ['leaving', 'true']; }
        [$role, $seen] = Inspector::accessibleRole($rt, $n);
        $name = $n->label !== '' ? $n->label : Inspector::clip(Runtime::innerText($n), 80);
        $focusable = Runtime::takesFocus($n) && !$n->disabled && !$n->ignoresInput();
        $acc = [['role', $role], ['name', json_encode($name, JSON_UNESCAPED_UNICODE)], ['keyboard-focusable', $yes($focusable)], ['ignored', $yes(!$seen || $n->inert)]];
        if ($n->description !== null && $n->description !== '') { $acc[] = ['description', json_encode($n->description, JSON_UNESCAPED_UNICODE)]; }
        return [['title' => 'Element', 'rows' => $el], ['title' => 'State', 'rows' => $state], ['title' => 'Accessibility', 'rows' => $acc]];
    }

    public static function align(int $a): string
    {
        return match ($a) { Node::START => 'flex-start', Node::CENTER => 'center', Node::END => 'flex-end', Node::STRETCH => 'stretch', Node::SPACE_BETWEEN => 'space-between', Node::SPACE_AROUND => 'space-around', Node::SPACE_EVENLY => 'space-evenly', Node::BASELINE => 'baseline', default => 'auto' };
    }

    /** The four sides top, right, bottom and left as CSS writes them, or null when all are 0. @param array{0: float, 1: float, 2: float, 3: float} $v */
    public static function edges(array $v): ?string
    {
        if ($v[0] == 0 && $v[1] == 0 && $v[2] == 0 && $v[3] == 0) { return null; }
        $f = fn(float $x) => is_infinite($x) ? 'auto' : Inspector::px($x);
        return match (true) {
            $v[0] == $v[1] && $v[1] == $v[2] && $v[2] == $v[3] => $f($v[0]),
            $v[0] == $v[2] && $v[1] == $v[3] => $f($v[0]) . ' ' . $f($v[1]),
            default => $f($v[0]) . ' ' . $f($v[1]) . ' ' . $f($v[2]) . ' ' . $f($v[3]),
        };
    }
}
