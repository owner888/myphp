<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** A document as HTML, as cmark-gfm renders it (mygo's htmlWriter): what the specs' examples compare. @internal */
final class Html
{
    private string $b = '';
    /** @var list<string> footnote labels in the order of their first reference */
    private array $notes = [];

    public function __construct(private Document $d) {}

    public function render(): string
    {
        $this->blocks(0);
        $this->footnotes();
        return $this->b;
    }

    private function cr(): void
    {
        if ($this->b !== '' && $this->b[strlen($this->b) - 1] !== "\n") {
            $this->b .= "\n";
        }
    }

    private function blocks(int $parent): void
    {
        foreach ($this->d->children($parent) as $i) {
            $this->block($i);
        }
    }

    private function tight(int $i): bool
    {
        $b = $this->d->block($i);
        if ($b->parent <= 0) {
            return false;
        }
        $p = $this->d->block($b->parent);
        if ($p->kind !== Block::ITEM || $p->parent < 0) {
            return false;
        }
        return $this->d->block($p->parent)->tight;
    }

    private function block(int $i): void
    {
        $d = $this->d;
        $b = $d->block($i);
        switch ($b->kind) {
            case Block::PARAGRAPH:
                if ($this->tight($i)) {
                    $this->inlines($i);
                    return;
                }
                $this->cr();
                $this->b .= '<p>';
                $this->inlines($i);
                $this->b .= "</p>\n";
                break;
            case Block::HEADING:
                $this->cr();
                $this->b .= '<h' . $b->level . '>';
                $this->inlines($i);
                $this->b .= '</h' . $b->level . ">\n";
                break;
            case Block::THEMATIC_BREAK:
                $this->cr();
                $this->b .= "<hr />\n";
                break;
            case Block::CODE_BLOCK:
                $this->cr();
                $this->b .= '<pre><code';
                $lang = $b->language();
                if ($lang !== '') {
                    $this->b .= ' class="language-' . self::escape($lang) . '"';
                }
                $this->b .= '>' . self::escape($d->lines($i)) . "</code></pre>\n";
                break;
            case Block::HTML_BLOCK:
                $this->cr();
                $this->b .= $d->lines($i);
                $this->cr();
                break;
            case Block::MATH_BLOCK:
                $this->cr();
                $lines = $d->lines($i);
                if (str_ends_with($lines, "\n")) {
                    $lines = substr($lines, 0, -1);
                }
                $this->b .= '<div class="math display">' . self::escape($lines) . "</div>\n";
                break;
            case Block::QUOTE:
                $this->cr();
                $this->b .= "<blockquote>\n";
                $this->blocks($i);
                $this->cr();
                $this->b .= "</blockquote>\n";
                break;
            case Block::LIST:
                $this->cr();
                if ($b->ordered) {
                    $this->b .= $b->number !== 1 ? '<ol start="' . $b->number . "\">\n" : "<ol>\n";
                } else {
                    $this->b .= "<ul>\n";
                }
                $this->blocks($i);
                $this->b .= $b->ordered ? "</ol>\n" : "</ul>\n";
                break;
            case Block::ITEM:
                $this->cr();
                $this->b .= '<li>';
                if ($b->task === Block::UNCHECKED) {
                    $this->b .= '<input type="checkbox" disabled="" /> ';
                } elseif ($b->task === Block::CHECKED) {
                    $this->b .= '<input type="checkbox" checked="" disabled="" /> ';
                }
                $this->blocks($i);
                $this->b .= "</li>\n";
                break;
            case Block::TABLE:
                $this->cr();
                $this->b .= "<table>\n";
                $body = false;
                foreach ($d->children($i) as $r) {
                    $row = $d->block($r);
                    if ($row->header) {
                        $this->b .= "<thead>\n";
                    } elseif (!$body) {
                        $this->b .= "<tbody>\n";
                        $body = true;
                    }
                    $this->b .= "<tr>\n";
                    $k = 0;
                    foreach ($d->children($r) as $c) {
                        $tag = $row->header ? 'th' : 'td';
                        $this->b .= '<' . $tag;
                        if ($k < count($b->align)) {
                            $this->b .= match ($b->align[$k]) {
                                Block::ALIGN_LEFT => ' align="left"',
                                Block::ALIGN_CENTER => ' align="center"',
                                Block::ALIGN_RIGHT => ' align="right"',
                                default => '',
                            };
                        }
                        $this->b .= '>';
                        $this->inlines($c);
                        $this->b .= '</' . $tag . ">\n";
                        $k++;
                    }
                    $this->b .= "</tr>\n";
                    if ($row->header) {
                        $this->b .= "</thead>\n";
                    }
                }
                if ($body) {
                    $this->b .= "</tbody>\n";
                }
                $this->b .= "</table>\n";
                break;
            case Block::FOOTNOTE_DEFINITION:
                // Listed at the end, in the order of their references.
                break;
        }
    }

    private function inlines(int $i): void
    {
        $in = $this->d->inlines($i) ?? [];
        $this->inline($in, 0, count($in));
    }

    /** @param list<Inline> $in */
    private function inline(array $in, int $from, int $to): void
    {
        for ($k = $from; $k < $to; $k = $in[$k]->next) {
            $n = $in[$k];
            switch ($n->kind) {
                case Inline::TEXT:
                    $this->b .= self::escape($n->text);
                    break;
                case Inline::SOFT_BREAK:
                    $this->b .= "\n";
                    break;
                case Inline::LINE_BREAK:
                    $this->b .= "<br />\n";
                    break;
                case Inline::CODE:
                    $this->b .= '<code>' . self::escape($n->text) . '</code>';
                    break;
                case Inline::EMPHASIS:
                    $this->b .= '<em>';
                    $this->inline($in, $k + 1, $n->next);
                    $this->b .= '</em>';
                    break;
                case Inline::STRONG:
                    $this->b .= '<strong>';
                    $this->inline($in, $k + 1, $n->next);
                    $this->b .= '</strong>';
                    break;
                case Inline::STRIKETHROUGH:
                    $this->b .= '<del>';
                    $this->inline($in, $k + 1, $n->next);
                    $this->b .= '</del>';
                    break;
                case Inline::LINK:
                    $this->b .= '<a href="' . self::escapeHref($n->dest) . '"';
                    if ($n->title !== '') {
                        $this->b .= ' title="' . self::escape($n->title) . '"';
                    }
                    $this->b .= '>';
                    $this->inline($in, $k + 1, $n->next);
                    $this->b .= '</a>';
                    break;
                case Inline::IMAGE:
                    $this->b .= '<img src="' . self::escapeHref($n->dest) . '" alt="' . self::escape(Inline::plainText(array_slice($in, $k + 1, $n->next - $k - 1))) . '"';
                    if ($n->title !== '') {
                        $this->b .= ' title="' . self::escape($n->title) . '"';
                    }
                    $this->b .= ' />';
                    break;
                case Inline::HTML:
                    $this->b .= $n->text;
                    break;
                case Inline::MATH:
                    $this->b .= ($n->display ? '<span class="math display">' : '<span class="math inline">') . self::escape($n->text) . '</span>';
                    break;
                case Inline::FOOTNOTE_REF:
                    $id = (string)$this->note($n->text);
                    $this->b .= '<sup class="footnote-ref"><a href="#fn-' . $id . '" id="fnref-' . $id . '" data-footnote-ref>' . $id . '</a></sup>';
                    break;
            }
        }
    }

    /** The number of footnote $label, numbering it at its first reference. */
    private function note(string $label): int
    {
        $key = Document::normalizeLabel($label);
        $i = array_search($key, $this->notes, true);
        if ($i !== false) {
            return $i + 1;
        }
        $this->notes[] = $key;
        return count($this->notes);
    }

    private function footnotes(): void
    {
        if ($this->notes === []) {
            return;
        }
        $this->cr();
        $this->b .= "<section class=\"footnotes\" data-footnotes>\n<ol>\n";
        for ($k = 0; $k < count($this->notes); $k++) {
            $i = $this->d->notes[$this->notes[$k]];
            $id = (string)($k + 1);
            $this->b .= '<li id="fn-' . $id . "\">\n";
            $this->blocks($i);
            $this->cr();
            $this->b .= '<a href="#fnref-' . $id . "\" class=\"footnote-backref\">↩</a>\n</li>\n";
        }
        $this->b .= "</ol>\n</section>\n";
    }

    private static function escape(string $s): string
    {
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "\0" => "\u{FFFD}"]);
    }

    /** A URL escaped as cmark's houdini does. */
    private static function escapeHref(string $s): string
    {
        $b = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if (Scan::isLetter($c) || Scan::isDigit($c) || str_contains('-_.+!*(),%#@?=;:/,+$~', $c)) {
                $b .= $c;
            } elseif ($c === '&') {
                $b .= '&amp;';
            } elseif ($c === "'") {
                $b .= '&#x27;';
            } else {
                $b .= sprintf('%%%02X', ord($c));
            }
        }
        return $b;
    }
}
