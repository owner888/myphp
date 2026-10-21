<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use Closure;
use MyPHP\UI\Markdown\Parser\{Document, Inline, Scan};
use MyPHP\UI\Span;

/** Makes the spans of a paragraph, a heading or a table cell from its inlines (mygo's spanBuilder). */
final class SpanBuilder
{
    public Document $d;
    public Theme $th;
    public int $block = 0;
    /** @var list<Inline> */
    private array $in = [];
    private Para $p;
    private int $cur = -1;
    /** @var ?Closure(int): float the opacity of text at a source offset, while revealing */
    public ?Closure $fade = null;
    private SpanStyle $style;
    /** @var list<string> the inline HTML tags open, as <b> */
    private array $html = [];
    /** @var ?Closure(string): int numbers footnotes by their definitions' order */
    public ?Closure $notes = null;
    /** The inline of the link being built. */
    private int $linkIndex = 0;

    /** Makes $p the paragraph of block $this->block, of base style $base (mygo's build). */
    public function build(Para $p, SpanStyle $base): void
    {
        $p->pieces = []; $p->marks = []; $p->runes = 0; $p->fading = false; $p->width = 0; $p->measured = false;
        $this->p = $p;
        $this->in = $this->d->inlines($this->block) ?? [];
        $this->html = [];
        $this->style = $base;
        $this->cur = -1;
        $this->inlines(0, count($this->in), $base);
    }

    /** The index of the piece of link $link to add to: the last when it is that link's. */
    private function piece(int $link): int
    {
        if ($this->cur < 0 || $this->p->pieces[$this->cur][0] !== $link) {
            $this->p->pieces[] = [$link, []];
            $this->cur = count($this->p->pieces) - 1;
        }
        return $this->cur;
    }

    /** Adds $text, which comes from the source's [$src, $end) (exactly those bytes when $exact), in style $s. */
    private function add(string $text, int $src, int $end, bool $exact, SpanStyle $s, int $link): void
    {
        if ($text === '') {
            return;
        }
        if ($this->fade !== null && $exact) { // text revealed at different times fades in on its own: cut where its opacity changes
            while ($text !== '') {
                $a = ($this->fade)($src);
                $n = strlen($text);
                for ($k = 1; $k < strlen($text); $k++) {
                    if (($this->fade)($src + $k) !== $a) { $n = $k; break; }
                }
                while ($n < strlen($text) && (ord($text[$n]) & 0xC0) === 0x80) { $n++; }
                $this->addSpan(substr($text, 0, $n), $src, $src + $n, $exact, $s, $link, $a);
                $text = substr($text, $n); $src += $n;
            }
            return;
        }
        $this->addSpan($text, $src, $end, $exact, $s, $link, $this->fade !== null ? ($this->fade)($src) : 1.0);
    }

    /** Adds text that comes from the source's [$src, $end) written with escapes or entities: as many runs as are its bytes, and each escape or entity of its own, so that copying maps its runes to the source's (mygo's addAligned). */
    private function addAligned(string $text, int $src, int $end, SpanStyle $s, int $link): void
    {
        $source = substr($this->d->source(), $src, $end - $src);
        $i = 0; $j = 0; $run = 0; // in source and in text; where the run of the same bytes started, in text
        $flush = function () use (&$i, &$j, &$run, $text, $src, $s, $link): void {
            if ($j > $run) {
                $this->add(substr($text, $run, $j - $run), $src + $i - ($j - $run), $src + $i, true, $s, $link);
            }
        };
        $ns = strlen($source); $nt = strlen($text);
        $rest = false;
        while ($i < $ns && $j < $nt) {
            if ($source[$i] === '\\' && $i + 1 < $ns && self::isEscapable($source[$i + 1]) && $source[$i + 1] === $text[$j]) {
                $flush();
                $this->add($text[$j], $src + $i, $src + $i + 2, false, $s, $link);
                $i += 2; $j++;
            } elseif ($source[$i] === '&' && self::entityAt($source, $i, substr($text, $j)) > 0) {
                [$n, $lit] = Scan::entity($source, $i);
                $flush();
                $this->add($lit, $src + $i, $src + $i + $n, false, $s, $link);
                $i += $n; $j += strlen($lit);
            } elseif ($source[$i] === $text[$j]) {
                $i++; $j++;
                continue;
            } else {
                $rest = true;
                break;
            }
            $run = $j;
        }
        if (!$rest) {
            $flush();
            $run = $j;
        }
        if ($j < $nt) {
            if ($j > $run) { $flush(); }
            $this->add(substr($text, $j), $src + $i, $end, false, $s, $link);
        }
    }

    /** The length of the entity at $i of $source that $text starts with, decoded, or 0. */
    private static function entityAt(string $source, int $i, string $text): int
    {
        [$n, $lit] = Scan::entity($source, $i);
        return $n === 0 || !str_starts_with($text, $lit) ? 0 : $n;
    }

    private function addSpan(string $text, int $src, int $end, bool $exact, SpanStyle $s, int $link, float $alpha): void
    {
        $th = $this->th;
        $sp = new Span($text, $th->fontSize, font: $th->font !== '' ? $th->font : null, weight: $s->weight !== 0 ? $s->weight : null, italic: $s->italic, strikethrough: $s->strike, underline: $s->underline);
        if ($s->size != 0) { $sp->size = $s->size; }
        if ($s->font !== '') { $sp->font = $s->font; }
        $color = null; $bg = null;
        if ($s->code) {
            $sp->font = $th->codeFont;
            $sp->size = $s->size != 0 ? $s->size * $th->codeSize : $th->em($th->codeSize);
            $bg = $th->codeBackground;
            $sp->radius = max(3, $sp->size / 4);
            if (!$s->inLink) { $color = $th->code; }
        }
        if ($s->sub) { $sp->size *= 0.75; }
        if ($s->mark) { $bg = $th->mark; }
        if ($s->color !== null && $s->color[1] > 0) { $color = $s->color; }
        if ($alpha < 1) { // not quite transparent: a color of alpha 0 is none, the text's
            $alpha = max($alpha, 0.02);
            $color ??= $s->inLink ? $th->link : $th->text;
            $color = [$color[0], $color[1] * $alpha];
            if ($bg !== null) { $bg = [$bg[0], $bg[1] * $alpha]; }
        }
        if ($color !== null) { $sp->color = $color[0]; $sp->alpha = $color[1]; }
        if ($bg !== null) { $sp->background = $bg[0]; $sp->backgroundAlpha = $bg[1]; }
        if ($this->fade !== null) {
            $this->p->fading = $this->p->fading || $alpha < 1;
        }
        $pc = $this->piece($link);
        $this->p->pieces[$pc][1][] = $sp;
        $this->p->marks[] = [$this->p->runes, $src, $end, $exact];
        $this->p->runes += mb_strlen($text, 'UTF-8');
    }

    private function inlines(int $from, int $to, SpanStyle $s): void
    {
        $in = $this->in;
        $src = $this->d->source();
        $link = $s->inLink ? $this->linkIndex : -1;
        for ($k = $from; $k < $to; $k = $in[$k]->next) {
            $n = $in[$k];
            switch ($n->kind) {
                case Inline::TEXT:
                    if ($n->end - $n->start === strlen($n->text) && substr($src, $n->start, $n->end - $n->start) === $n->text) {
                        $this->add($n->text, $n->start, $n->end, true, $this->htmlStyle($s), $link);
                    } else {
                        $this->addAligned($n->text, $n->start, $n->end, $this->htmlStyle($s), $link);
                    }
                    break;
                case Inline::SOFT_BREAK:
                    $this->add(' ', $n->start, $n->end, false, $s, $link);
                    break;
                case Inline::LINE_BREAK:
                    $this->add("\n", $n->start, $n->end, false, $s, $link);
                    break;
                case Inline::CODE:
                    $cs = clone $this->htmlStyle($s);
                    $cs->code = true;
                    // The code's text is its source's bytes but for the spaces stripped at its ends and line endings as spaces.
                    $a = self::codeStart($src, $n); $z = $n->end - ($a - $n->start);
                    if ($z - $a === strlen($n->text) + 2 && $src[$a] === ' ') { $a++; }
                    $this->add($n->text, $a, min($a + strlen($n->text), $z), $z - $a === strlen($n->text), $cs, $link);
                    break;
                case Inline::EMPHASIS:
                    $cs = clone $s; $cs->italic = true;
                    $this->inlines($k + 1, $n->next, $cs);
                    break;
                case Inline::STRONG:
                    $cs = clone $s; $cs->weight = $this->th->strongWeight;
                    if ($cs->color === null && !$cs->inLink && $this->th->strong !== $this->th->text) { $cs->color = $this->th->strong; }
                    $this->inlines($k + 1, $n->next, $cs);
                    break;
                case Inline::STRIKETHROUGH:
                    $cs = clone $s; $cs->strike = true;
                    $this->inlines($k + 1, $n->next, $cs);
                    break;
                case Inline::LINK:
                    if ($s->inLink) {
                        $this->inlines($k + 1, $n->next, $s);
                        break;
                    }
                    $cs = clone $s; $cs->inLink = true; $cs->pendingLink = $n->pending;
                    $cs->color = null; // the link's
                    $save = $this->linkIndex;
                    $this->linkIndex = $k;
                    $this->inlines($k + 1, $n->next, $cs);
                    $this->linkIndex = $save;
                    break;
                case Inline::IMAGE: // images within text show their description; a paragraph of images alone shows the images (imagesOnly)
                    $cs = clone $s; $cs->color = $this->th->muted;
                    $alt = Inline::plainText(array_slice($in, $k + 1, $n->next - $k - 1));
                    $this->add('[' . ($alt === '' ? 'image' : $alt) . ']', $n->start, $n->end, false, $cs, $link);
                    break;
                case Inline::HTML:
                    if (!$this->inlineHTML($n->text)) {
                        $this->add($n->text, $n->start, $n->end, true, $s, $link);
                    }
                    break;
                case Inline::MATH:
                    $cs = clone $s; $cs->font = $this->th->codeFont; $cs->color = $this->th->muted;
                    if (!$cs->italic) { $cs->size = $this->th->em($this->th->codeSize); }
                    $this->add($n->text, self::mathStart($src, $n), $n->end, false, $cs, $link);
                    break;
                case Inline::FOOTNOTE_REF:
                    $cs = clone $s; $cs->sub = true; $cs->color = $this->th->link;
                    $num = $this->notes !== null ? ($this->notes)($n->text) : 0;
                    $this->add('[' . ($num > 0 ? (string)$num : $n->text) . ']', $n->start, $n->end, false, $cs, -2 - $k);
                    break;
            }
        }
    }

    /** Where a code span's text starts in the source (mygo's codeStart). */
    public static function codeStart(string $src, Inline $n): int
    {
        $i = $n->start;
        while ($i < $n->end && $src[$i] === '`') { $i++; }
        return $i;
    }

    private static function mathStart(string $src, Inline $n): int
    {
        $i = $n->start;
        while ($i < $n->end && str_contains('$\\([', $src[$i]) && $i - $n->start < 2) { $i++; }
        return $i;
    }

    /** Applies the inline HTML tags of formatting language models write, <b>, <i>, <code>, <br> and the like, and reports whether $tag was one: others show as text (mygo's inlineHTML). */
    private function inlineHTML(string $tag): bool
    {
        $t = strtolower(trim($tag, "<>/ \t\n"));
        if (($i = strcspn($t, " \t\n")) < strlen($t)) {
            $t = substr($t, 0, $i);
        }
        $closing = str_starts_with($tag, '</');
        if ($t === 'br') {
            $this->add("\n", 0, 0, false, $this->style, -1);
            return true;
        }
        if (!in_array($t, ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'del', 'strike', 'code', 'kbd', 'samp', 'mark', 'sub', 'sup', 'small'], true)) {
            return false;
        }
        if ($closing) {
            for ($i = count($this->html) - 1; $i >= 0; $i--) {
                if ($this->html[$i] === $t) {
                    array_splice($this->html, $i, 1);
                    break;
                }
            }
        } elseif (!str_ends_with($tag, '/>')) {
            $this->html[] = $t;
        }
        return true;
    }

    /** $s with the inline HTML tags open applied (mygo's htmlStyle). */
    private function htmlStyle(SpanStyle $s): SpanStyle
    {
        if ($this->html === []) {
            return $s;
        }
        $s = clone $s;
        foreach ($this->html as $t) {
            match ($t) {
                'b', 'strong' => $s->weight = $this->th->strongWeight,
                'i', 'em' => $s->italic = true,
                'u', 'ins' => $s->underline = true,
                's', 'del', 'strike' => $s->strike = true,
                'code', 'kbd', 'samp' => $s->code = true,
                'mark' => $s->mark = true,
                default => $s->sub = true, // sub, sup, small
            };
        }
        return $s;
    }

    /** Whether a backslash escapes $c: ASCII punctuation. */
    public static function isEscapable(string $c): bool
    {
        return ($c >= '!' && $c <= '/') || ($c >= ':' && $c <= '@') || ($c >= '[' && $c <= '`') || ($c >= '{' && $c <= '~');
    }
}
