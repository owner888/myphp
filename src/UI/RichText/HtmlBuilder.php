<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Params;
use MyPHP\UI\Image;

/**
 * Builds a Text from the nodes of an HTML document (mygo's richtext
 * htmlBuilder), for RichText::parseHTML().
 *
 * @internal
 */
final class HtmlBuilder
{
    /** How many pixels a pasted picture may have, so a small file that claims a huge size takes no gigabytes to decode. */
    private const MAX_IMAGE_PIXELS = 1 << 25;

    /** @var list<Paragraph> */
    private array $paras = [];
    /** The paragraph being built, and whether it has begun. */
    private Paragraph $cur;
    private bool $started = false;
    /** Set after whitespace, which collapses into one space. */
    private bool $space = true;
    /** The kinds of the lists open. @var list<ListKind> */
    private array $lists = [];
    private ParagraphStyle $style;
    private int $pre = 0;

    private function __construct()
    {
        $this->cur = new Paragraph;
        $this->style = new ParagraphStyle;
    }

    public static function parse(string $s): Text
    {
        $b = new self;
        $b->node(HtmlNode::parse($s), new Attributes);
        $b->flush();
        if ($b->paras === []) { return new Text; }
        // Trailing empty paragraphs are the document's margins.
        $paras = $b->paras;
        while (count($paras) > 1 && $paras[count($paras) - 1]->runs === []) { array_pop($paras); }
        return Text::of(...$paras);
    }

    /** Ends the paragraph being built. */
    private function flush(): void
    {
        if (!$this->started) { return; }
        $p = $this->cur;
        // Trailing whitespace of a paragraph is not shown.
        if (($n = count($p->runs)) > 0 && $this->pre === 0) {
            $t = rtrim($p->runs[$n - 1]->text, ' ');
            if ($t === '') {
                array_pop($p->runs);
            } else {
                $p->runs[$n - 1] = new Run($t, $p->runs[$n - 1]->attributes);
            }
        }
        $this->paras[] = $p;
        $this->cur = new Paragraph;
        $this->started = false;
        $this->space = true;
    }

    /** Starts a paragraph of the style, unless one is being built. */
    private function begin(): void
    {
        if (!$this->started) {
            $this->cur = new Paragraph(clone $this->style);
            $this->started = true;
        }
    }

    /** Adds text in attributes $a. */
    private function text(string $s, Attributes $a): void
    {
        if ($this->pre > 0) {
            foreach (explode("\n", $s) as $i => $line) {
                if ($i > 0) {
                    $this->begin();
                    $this->flush();
                }
                if ($line !== '') {
                    $this->begin();
                    $this->cur->runs[] = new Run($line, $a);
                }
            }
            return;
        }
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $r) {
            if ($r === ' ' || $r === "\n" || $r === "\t" || $r === "\r" || $r === "\f") {
                if (!$this->space) {
                    $out .= ' ';
                    $this->space = true;
                }
                continue;
            }
            $out .= $r === "\u{A0}" ? ' ' : $r;
            $this->space = false;
        }
        if ($out === '') { return; }
        $this->begin();
        if ($this->cur->runs === []) {
            $out = ltrim($out, ' ');
            if ($out === '') { return; }
        }
        $this->cur->runs[] = new Run($out, $a);
    }

    /** Adds $n and what it holds, in attributes $a. */
    private function node(HtmlNode $n, Attributes $a): void
    {
        if ($n->type === HtmlNode::TEXT) {
            $this->text($n->data, $a);
            return;
        }
        if ($n->type !== HtmlNode::ELEMENT) {
            foreach ($n->children as $c) { $this->node($c, $a); }
            return;
        }
        $saved = $this->style;
        $block = false;
        $pre = false;
        $a = clone $a;
        switch ($n->data) {
            case 'script': case 'style': case 'head': case 'title': case 'template': case 'noscript':
                return;
            case 'br':
                $this->begin();
                $this->flush();
                return;
            case 'img':
                [$src] = $n->attr('src');
                [$alt] = $n->attr('alt');
                $at = new Attachment(width: self::imageSide($n->attr('width')[0]), height: self::imageSide($n->attr('height')[0]), name: $alt, url: $src);
                if (str_starts_with($src, 'data:') && ($k = strpos($src, ';base64,')) !== false) {
                    $raw = base64_decode(substr($src, $k + 8), true);
                    if ($raw !== false && self::smallImage($raw)) {
                        try {
                            $at->image = Image::fromBytes($raw);
                            $at->url = '';
                        } catch (\RuntimeException) {
                            // mygo keeps the URL of a picture it cannot decode
                        }
                    }
                }
                $this->begin();
                $x = clone $a->extending();
                $x->attachment = $at;
                $this->cur->runs[] = new Run(Text::OBJECT_REPLACEMENT, $x);
                $this->space = false;
                return;
            case 'input':
                if (strcasecmp($n->attr('type')[0], 'checkbox') === 0) {
                    $this->begin();
                    $this->cur->style->list = ListKind::Tasks;
                    $this->cur->style->checked = $n->attr('checked')[1];
                    $this->space = true;
                }
                return;
            case 'b': case 'strong':
                $a->weight = 700;
                break;
            case 'i': case 'em': case 'cite': case 'dfn':
                $a->italic = true;
                break;
            case 'u': case 'ins':
                $a->underline = true;
                break;
            case 's': case 'strike': case 'del':
                $a->strikethrough = true;
                break;
            case 'code': case 'kbd': case 'samp': case 'tt':
                if ($this->pre === 0) { $a->code = true; }
                break;
            case 'a':
                $a->link = HtmlWriter::safeLink($n->attr('href')[0]);
                break;
            case 'ul': case 'ol':
                $this->flush();
                $this->lists[] = $n->data === 'ol' ? ListKind::Numbers : ListKind::Bullets;
                foreach ($n->children as $c) { $this->node($c, $a); }
                array_pop($this->lists);
                $this->flush();
                $this->style = $saved;
                return;
            case 'li':
                $this->flush();
                $kind = $this->lists === [] ? ListKind::Bullets : $this->lists[count($this->lists) - 1];
                $this->style = new ParagraphStyle(list: $kind, indent: max(count($this->lists) - 1, 0), align: $saved->align, quote: $saved->quote);
                $block = true;
                break;
            case 'p': case 'div': case 'section': case 'article': case 'header': case 'footer': case 'main': case 'aside': case 'nav':
            case 'figure': case 'figcaption': case 'tr': case 'dt': case 'dd': case 'address': case 'center':
                $this->flush();
                $block = true;
                break;
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $this->flush();
                $this->style = new ParagraphStyle(heading: (int)$n->data[1], align: $saved->align, quote: $saved->quote);
                $block = true;
                break;
            case 'blockquote':
                $this->flush();
                $this->style = clone $this->style;
                $this->style->quote = true;
                $block = true;
                break;
            case 'pre':
                $this->flush();
                $this->style = new ParagraphStyle(indent: $saved->indent, quote: $saved->quote, codeBlock: true);
                $this->pre++;
                $pre = true;
                $block = true;
                break;
            case 'td': case 'th':
                $this->text(' ', $a);
                break;
        }
        if (($style = $n->attr('style')[0]) !== '') {
            if ($block && $this->style === $saved) { $this->style = clone $saved; }
            $this->applyCSS($style, $a, $block);
        }
        if ($block) {
            $align = match (strtolower($n->attr('align')[0])) { 'center' => Params::CENTER, 'right' => Params::END, default => null };
            if ($align !== null) {
                if ($this->style === $saved) { $this->style = clone $saved; }
                $this->style->align = $align;
            }
        }
        foreach ($n->children as $c) { $this->node($c, $a); }
        if ($block) {
            if ($n->data === 'p' && !$this->started && $this->pre === 0) {
                $this->begin(); // an empty paragraph shows as one
            }
            $this->flush();
            $this->style = $saved;
        }
        if ($pre) { $this->pre--; }
    }

    /**
     * Applies the declarations of a style attribute to a run's attributes
     * and, for a block, its paragraph's style: weights, italics, lines and
     * alignment; fonts, sizes and colors are left out.
     */
    private function applyCSS(string $style, Attributes $a, bool $block): void
    {
        foreach (explode(';', $style) as $decl) {
            if (!str_contains($decl, ':')) { continue; }
            [$name, $value] = explode(':', $decl, 2);
            $name = strtolower(trim($name));
            $value = strtolower(trim($value));
            if (str_ends_with($value, '!important')) { $value = trim(substr($value, 0, -10)); }
            switch ($name) {
                case 'font-weight':
                    if ($value === 'bold' || $value === 'bolder') {
                        $a->weight = 700;
                    } elseif ($value === 'normal' || $value === 'lighter') {
                        $a->weight = 0;
                    } elseif (preg_match('/^[+-]?\d+$/', $value) === 1) {
                        $a->weight = (int)$value >= 600 ? 700 : 0;
                    }
                    break;
                case 'font-style':
                    $a->italic = $value === 'italic' || $value === 'oblique';
                    break;
                case 'text-decoration': case 'text-decoration-line':
                    $a->underline = $a->underline || str_contains($value, 'underline');
                    $a->strikethrough = $a->strikethrough || str_contains($value, 'line-through');
                    if ($value === 'none') { $a->underline = $a->strikethrough = false; }
                    break;
                case 'text-align':
                    if ($block) {
                        $align = match ($value) {
                            'center' => Params::CENTER,
                            'right', 'end' => Params::END,
                            'left', 'start', 'justify' => Params::START,
                            default => null,
                        };
                        if ($align !== null) { $this->style->align = $align; }
                    }
                    break;
            }
        }
    }

    /** The width or height an img's attribute gives, or 0. */
    private static function imageSide(string $s): float
    {
        $s = trim($s);
        if (str_ends_with($s, 'px')) { $s = substr($s, 0, -2); }
        return is_numeric($s) ? self::side((float)$s) : 0.0;
    }

    /** $v as an attachment's width or height: 0, for its picture's, unless it is a size, not negative, infinite or not a number. @internal */
    public static function side(float $v): float
    {
        return !($v > 0) || $v > 1e5 ? 0.0 : $v;
    }

    /** Whether the picture of $data is small enough to decode. */
    private static function smallImage(string $data): bool
    {
        $c = @getimagesizefromstring($data);
        return $c !== false && $c[0] > 0 && $c[1] > 0 && $c[0] * $c[1] <= self::MAX_IMAGE_PIXELS;
    }
}
