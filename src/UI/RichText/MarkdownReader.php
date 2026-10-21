<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\UI\Markdown\Parser\Block;
use MyPHP\UI\Markdown\Parser\Document as MarkdownDocument;
use MyPHP\UI\Markdown\Parser\Extensions;
use MyPHP\UI\Markdown\Parser\Inline;

/**
 * Reads the blocks and inlines of a Markdown document into paragraphs
 * (mygo's richtext mdReader), for RichText::parseMarkdown().
 *
 * @internal
 */
final class MarkdownReader
{
    /** @var list<Paragraph> */
    private array $paras = [];
    /** The inline HTML tags open, by the attribute they turn on: <u>, <s>, <b> and <i> and their synonyms. @var array{0: int, 1: int, 2: int, 3: int} */
    private array $html = [0, 0, 0, 0];

    private function __construct(private readonly MarkdownDocument $d) {}

    public static function parse(string $s): Text
    {
        $d = new MarkdownDocument(Extensions::GFM | Extensions::FOOTNOTES);
        $d->set($s);
        $m = new self($d);
        $m->blocks(0, new ParagraphStyle, -1);
        return $m->paras === [] ? new Text : Text::of(...$m->paras);
    }

    /** Adds the children of block $i in style $st; $depth is the level of the list they are in, -1 outside lists. */
    private function blocks(int $i, ParagraphStyle $st, int $depth): void
    {
        foreach ($this->d->children($i) as $k) { $this->block($k, $st, $depth); }
    }

    private function block(int $i, ParagraphStyle $st, int $depth): void
    {
        $b = $this->d->block($i);
        $st = clone $st;
        switch ($b->kind) {
            case Block::PARAGRAPH:
                $this->inlinePara($st, $this->d->inlines($i) ?? [], new Attributes);
                break;
            case Block::HEADING:
                $st->heading = $b->level;
                $this->inlinePara($st, $this->d->inlines($i) ?? [], new Attributes);
                break;
            case Block::CODE_BLOCK:
                $st->codeBlock = true; $st->heading = 0;
                $n = $this->d->lineCount($i);
                for ($k = 0; $k < $n; $k++) {
                    [$line] = $this->d->line($i, $k);
                    $this->paras[] = new Paragraph(clone $st, $line !== '' ? [new Run($line)] : []);
                }
                if ($n === 0) { $this->paras[] = new Paragraph(clone $st); }
                break;
            case Block::HTML_BLOCK:
                foreach (RichText::parseHTML($this->d->lines($i))->paragraphs() as $p) {
                    $p->style->quote = $p->style->quote || $st->quote;
                    $p->style->indent += $st->indent;
                    $this->paras[] = $p;
                }
                break;
            case Block::QUOTE:
                $st->quote = true;
                $this->blocks($i, $st, $depth);
                break;
            case Block::LIST:
                $kind = $b->ordered ? ListKind::Numbers : ListKind::Bullets;
                foreach ($this->d->children($i) as $it) {
                    $ist = new ParagraphStyle(list: $kind, indent: $depth + 1, quote: $st->quote);
                    if (($task = $this->d->block($it)->task) !== Block::NO_TASK) {
                        $ist->list = ListKind::Tasks;
                        $ist->checked = $task === Block::CHECKED;
                    }
                    $this->item($it, $ist, $depth + 1);
                }
                break;
            case Block::TABLE:
                foreach ($this->d->children($i) as $row) {
                    $p = new Paragraph(clone $st);
                    $a = new Attributes;
                    if ($this->d->block($row)->header) { $a->weight = 700; }
                    $first = true;
                    foreach ($this->d->children($row) as $cell) {
                        if (!$first) { $p->runs = self::addRun($p->runs, new Run("\t", $a)); }
                        $first = false;
                        foreach ($this->runs($this->d->inlines($cell) ?? [], $a) as $r) { $p->runs = self::addRun($p->runs, $r); }
                    }
                    $this->paras[] = $p;
                }
                break;
            case Block::FOOTNOTE_DEFINITION:
                $from = count($this->paras);
                $this->blocks($i, $st, $depth);
                if ($from < count($this->paras)) {
                    array_unshift($this->paras[$from]->runs, new Run('[' . $b->label() . '] '));
                }
                break;
        }
    }

    /** Adds a list item: its first paragraph in the item's style $ist, and what follows indented under it. */
    private function item(int $i, ParagraphStyle $ist, int $depth): void
    {
        $under = new ParagraphStyle(indent: $depth + 1, quote: $ist->quote);
        $first = true;
        foreach ($this->d->children($i) as $k) {
            $b = $this->d->block($k);
            if ($first) {
                $first = false;
                if ($b->kind === Block::PARAGRAPH || $b->kind === Block::HEADING) {
                    $this->block($k, $ist, $depth);
                    continue;
                }
                // An item starting with code or a list has an empty line of
                // its own, as it shows.
                $this->paras[] = new Paragraph(clone $ist);
            }
            $this->block($k, $under, $depth);
        }
        if ($first) { $this->paras[] = new Paragraph(clone $ist); }
    }

    /**
     * Adds a paragraph of inlines in style $st; a hard line break starts
     * another, indented under a list item rather than an item of its own.
     *
     * @param list<Inline> $in
     */
    private function inlinePara(ParagraphStyle $st, array $in, Attributes $a): void
    {
        $p = new Paragraph($st);
        foreach ($this->runs($in, $a) as $r) {
            $parts = explode("\n", $r->text);
            foreach ($parts as $k => $line) {
                if ($k > 0) {
                    $this->paras[] = $p;
                    if ($st->list !== ListKind::NoList) {
                        $st = new ParagraphStyle(indent: $st->indent + 1, quote: $st->quote);
                    }
                    $p = new Paragraph($st);
                }
                if ($line !== '') { $p->runs[] = new Run($line, $r->attributes); }
            }
        }
        $this->paras[] = $p;
    }

    /** The runs of inlines, in attributes $a; hard line breaks are newlines. @param list<Inline> $in @return list<Run> */
    private function runs(array $in, Attributes $a): array
    {
        $out = [];
        $this->html = [0, 0, 0, 0];
        $this->inlines($out, $in, 0, count($in), $a);
        return $out;
    }

    /** @param list<Run> $out @param list<Inline> $in */
    private function inlines(array &$out, array $in, int $from, int $to, Attributes $a): void
    {
        $add = function (string $s, Attributes $a) use (&$out): void {
            if ($this->html !== [0, 0, 0, 0]) {
                $a = clone $a;
                if ($this->html[0] > 0) { $a->underline = true; }
                if ($this->html[1] > 0) { $a->strikethrough = true; }
                if ($this->html[2] > 0) { $a->weight = 700; }
                if ($this->html[3] > 0) { $a->italic = true; }
            }
            $out = self::addRun($out, new Run($s, $a));
        };
        for ($k = $from; $k < $to; $k = $in[$k]->next) {
            $n = $in[$k];
            $x = clone $a;
            switch ($n->kind) {
                case Inline::TEXT: $add($n->text, $a); break;
                case Inline::SOFT_BREAK: $add(' ', $a); break;
                case Inline::LINE_BREAK: $add("\n", $a); break;
                case Inline::CODE:
                    $x->code = true;
                    $add($n->text, $x);
                    break;
                case Inline::EMPHASIS:
                    $x->italic = true;
                    $this->inlines($out, $in, $k + 1, $n->next, $x);
                    break;
                case Inline::STRONG:
                    $x->weight = 700;
                    $this->inlines($out, $in, $k + 1, $n->next, $x);
                    break;
                case Inline::STRIKETHROUGH:
                    $x->strikethrough = true;
                    $this->inlines($out, $in, $k + 1, $n->next, $x);
                    break;
                case Inline::LINK:
                    $x->link = HtmlWriter::safeLink($n->dest);
                    $this->inlines($out, $in, $k + 1, $n->next, $x);
                    break;
                case Inline::IMAGE:
                    $x = clone $a->extending();
                    $x->attachment = new Attachment(name: Inline::plainText(array_slice($in, $k + 1, $n->next - $k - 1)), url: HtmlWriter::safeLink($n->dest));
                    $out[] = new Run(Text::OBJECT_REPLACEMENT, $x);
                    break;
                case Inline::HTML:
                    $this->tag($n->text, $out, $a);
                    break;
                case Inline::FOOTNOTE_REF:
                    $add('[' . $n->text . ']', $a);
                    break;
            }
        }
    }

    /**
     * Applies an inline HTML tag: the opening and closing tags of
     * underlines, strikethrough, bold and italic, and line breaks. Other
     * tags are left out, as a browser shows none.
     *
     * @param list<Run> $out
     */
    private function tag(string $s, array &$out, Attributes $a): void
    {
        $s = strtolower(trim($s));
        if (!str_starts_with($s, '<') || !str_ends_with($s, '>')) { return; }
        $s = trim(substr($s, 1, -1));
        $closing = str_starts_with($s, '/');
        if ($closing) { $s = substr($s, 1); }
        if (str_ends_with($s, '/')) { $s = substr($s, 0, -1); }
        $name = explode(' ', trim($s), 2)[0];
        $which = match ($name) {
            'u', 'ins' => 0,
            's', 'strike', 'del' => 1,
            'b', 'strong' => 2,
            'i', 'em' => 3,
            default => -1,
        };
        if ($name === 'br') {
            $out[] = new Run("\n", $a);
            return;
        }
        if ($which < 0) { return; }
        $this->html[$which] = $closing ? max($this->html[$which] - 1, 0) : $this->html[$which] + 1;
    }

    /** $runs and $r, joined to the last when their attributes are the same. @param list<Run> $runs @return list<Run> */
    private static function addRun(array $runs, Run $r): array
    {
        $n = count($runs);
        if ($n > 0 && $r->attributes->attachment === null && $runs[$n - 1]->attributes->equals($r->attributes)) {
            $runs[$n - 1] = new Run($runs[$n - 1]->text . $r->text, $runs[$n - 1]->attributes);
            return $runs;
        }
        $runs[] = $r;
        return $runs;
    }
}
