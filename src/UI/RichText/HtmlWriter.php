<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Params;

/**
 * Writes a Text as HTML (mygo's richtext html.go), for Text::html(), and
 * the CSS and URL helpers the codecs share.
 *
 * @internal
 */
final class HtmlWriter
{
    /** $t as HTML (Text::html()). */
    public static function write(Text $t): string
    {
        return '<meta charset="utf-8">' . self::blocks(iterator_to_array($t->paragraphs(), false));
    }

    private static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }

    /** Paragraphs as HTML blocks; those of a quotation in a blockquote, whatever they are. @param list<Paragraph> $paras */
    private static function blocks(array $paras): string
    {
        $b = '';
        $open = []; // the tags of the lists open
        $closeLists = function (int $depth) use (&$open, &$b): void {
            while (count($open) > $depth) {
                $b .= '</li></' . array_pop($open) . '>';
            }
        };
        for ($i = 0, $count = count($paras); $i < $count; $i++) {
            $p = $paras[$i];
            $st = $p->style;
            if ($st->list === ListKind::NoList || $st->quote) { $closeLists(0); }
            $align = match ($st->align) {
                Params::CENTER => ' style="text-align:center"',
                Params::END => ' style="text-align:right"',
                default => '',
            };
            if ($st->quote) {
                $quoted = [];
                for ($j = $i; $j < $count && $paras[$j]->style->quote; $j++) {
                    $q = new Paragraph(clone $paras[$j]->style, $paras[$j]->runs);
                    $q->style->quote = false;
                    $quoted[] = $q;
                }
                $b .= '<blockquote>' . self::blocks($quoted) . '</blockquote>';
                $i = $j - 1;
            } elseif ($st->list !== ListKind::NoList) {
                $tag = $st->list === ListKind::Numbers ? 'ol' : 'ul';
                $depth = $st->indent + 1;
                if (count($open) >= $depth && $open[$depth - 1] !== $tag) { $closeLists($depth - 1); }
                if (count($open) >= $depth) {
                    $closeLists($depth);
                    $b .= '</li>';
                } else {
                    while (count($open) < $depth) {
                        $b .= "<$tag>";
                        $open[] = $tag;
                        if (count($open) < $depth) { $b .= '<li>'; }
                    }
                }
                $b .= "<li$align>";
                if ($st->list === ListKind::Tasks) {
                    $b .= $st->checked ? '<input type="checkbox" checked disabled> ' : '<input type="checkbox" disabled> ';
                }
                $b .= self::runs($p->runs);
            } elseif ($st->codeBlock) {
                $b .= '<pre><code>';
                for (; $i < $count && $paras[$i]->style->codeBlock; $i++) {
                    foreach ($paras[$i]->runs as $r) { $b .= self::esc($r->text); }
                    if ($i + 1 < $count && $paras[$i + 1]->style->codeBlock) { $b .= "\n"; }
                }
                $i--;
                $b .= '</code></pre>';
            } else {
                $tag = $st->heading > 0 ? 'h' . min($st->heading, 6) : 'p';
                $b .= "<$tag$align>";
                if ($p->runs === []) { $b .= '<br>'; }
                $b .= self::runs($p->runs) . "</$tag>";
            }
        }
        $closeLists(0);
        return $b;
    }

    /** Runs as HTML: a link around the tags of the formats, and a span with the styles of fonts and colors. @param list<Run> $runs */
    private static function runs(array $runs): string
    {
        $b = '';
        for ($i = 0, $n = count($runs); $i < $n; $i++) {
            $a = $runs[$i]->attributes;
            if (($link = self::safeLink($a->link)) !== '') {
                $b .= '<a href="' . self::esc($link) . '">';
                for (; $i < $n && $runs[$i]->attributes->link === $a->link; $i++) { $b .= self::run($runs[$i]); }
                $i--;
                $b .= '</a>';
                continue;
            }
            $b .= self::run($runs[$i]);
        }
        return $b;
    }

    private static function run(Run $r): string
    {
        $a = $r->attributes;
        if (($at = $a->attachment) !== null) {
            [$w, $h] = $at->size();
            return sprintf('<img src="%s" alt="%s" width="%d" height="%d">', self::esc($at->url), self::esc($at->name), (int)($w + 0.5), (int)($h + 0.5));
        }
        $style = [];
        if ($a->font !== '') { $style[] = 'font-family:' . self::cssFamily($a->font); }
        if ($a->size > 0) { $style[] = 'font-size:' . sprintf('%.4G', $a->size) . 'px'; }
        if ($a->color !== null && $a->color[1] > 0) { $style[] = 'color:' . self::cssColor($a->color); }
        if ($a->background !== null && $a->background[1] > 0) { $style[] = 'background-color:' . self::cssColor($a->background); }
        if ($a->weight > 0 && $a->weight !== 400 && $a->weight !== 700) { $style[] = 'font-weight:' . $a->weight; }
        $tags = [];
        if ($a->weight === 700) { $tags[] = 'b'; }
        if ($a->italic) { $tags[] = 'i'; }
        if ($a->underline) { $tags[] = 'u'; }
        if ($a->strikethrough) { $tags[] = 's'; }
        if ($a->code) { $tags[] = 'code'; }
        $b = '';
        if ($style !== []) { $b .= '<span style="' . self::esc(implode(';', $style)) . '">'; }
        foreach ($tags as $t) { $b .= "<$t>"; }
        $b .= self::esc($r->text);
        foreach (array_reverse($tags) as $t) { $b .= "</$t>"; }
        if ($style !== []) { $b .= '</span>'; }
        return $b;
    }

    /**
     * A link's URL, or '' for one that runs a script where a browser opens
     * it: javascript:, vbscript: and data:, written as browsers read them,
     * with spaces before and tabs or newlines within (mygo's safeLink).
     */
    public static function safeLink(string $s): string
    {
        $s = trim($s, "\x00..\x20");
        $scheme = mb_strtolower(str_replace(["\t", "\n", "\r"], '', $s), 'UTF-8');
        foreach (['javascript:', 'vbscript:', 'data:'] as $bad) {
            if (str_starts_with($scheme, $bad)) { return ''; }
        }
        return $s;
    }

    /** A color [0xRRGGBB, alpha] as CSS. @param array{0: int, 1: float} $c */
    public static function cssColor(array $c): string
    {
        [$rgb, $alpha] = $c;
        $a = (int)round($alpha * 255);
        if ($a >= 255) { return sprintf('#%06x', $rgb & 0xffffff); }
        return sprintf('rgba(%d,%d,%d,%s)', ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff, rtrim(rtrim(sprintf('%.3G', $a / 255), '0'), '.') ?: '0');
    }

    /** A family as CSS, quoted when it has a space. */
    private static function cssFamily(string $f): string
    {
        if (strpbrk($f, ' ,') !== false && strpbrk($f, '"\'') === false) { return "'$f'"; }
        return $f;
    }

    /** A CSS color, #rgb, #rrggbb, rgb() or rgba(), as [0xRRGGBB, alpha], or null. @return ?array{0: int, 1: float} */
    public static function parseCSSColor(string $s): ?array
    {
        $s = strtolower(trim($s));
        if ($s === '' || $s === 'transparent' || $s === 'inherit' || $s === 'initial') { return null; }
        if (str_starts_with($s, '#')) {
            $h = substr($s, 1);
            if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
            if ((strlen($h) !== 6 && strlen($h) !== 8) || !ctype_xdigit($h)) { return null; }
            $v = hexdec($h);
            if (strlen($h) === 8) { return [($v >> 8) & 0xffffff, ($v & 0xff) / 255]; }
            return [$v, 1.0];
        }
        if (str_starts_with($s, 'rgba(')) { $s = 'rgb(' . substr($s, 5); }
        if (!str_starts_with($s, 'rgb(')) { return null; }
        $inner = rtrim(substr($s, 4), ')');
        $parts = preg_split('/[, \/]+/', $inner, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 3) { return null; }
        $c = [0.0, 0.0, 0.0, 1.0];
        foreach (array_slice($parts, 0, 4) as $i => $p) {
            $pct = str_ends_with($p, '%');
            $num = $pct ? substr($p, 0, -1) : $p;
            if (!is_numeric($num)) { return null; }
            $f = (float)$num;
            if ($pct) {
                $f = $f / 100 * 255;
                if ($i === 3) { $f /= 255; }
            }
            $c[$i] = $f;
        }
        if ($c[3] <= 0) { return null; }
        $byte = fn (float $v): int => (int)min(max($v, 0), 255);
        return [($byte($c[0]) << 16) | ($byte($c[1]) << 8) | $byte($c[2]), min($c[3], 1.0)];
    }
}
