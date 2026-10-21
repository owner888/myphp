<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Params;

/**
 * Reads a document in Rich Text Format (mygo's richtext rtfReader), for
 * RichText::parseRTF().
 *
 * @internal
 */
final class RtfReader
{
    private const SKIP = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'footnote',
        'listtable', 'listoverridetable', 'expandedcolortbl', 'themedata', 'colorschememapping', 'latentstyles',
        'datastore', 'xmlnstbl', 'rsidtbl', 'generator', 'object', 'shppict', 'nonshppict'];
    private const CP1252 = ['€', "\u{81}", '‚', 'ƒ', '„', '…', '†', '‡', 'ˆ', '‰', 'Š', '‹', 'Œ', "\u{8d}", 'Ž', "\u{8f}",
        "\u{90}", '‘', '’', '“', '”', '•', '–', '—', '˜', '™', 'š', '›', 'œ', "\u{9d}", 'ž', 'Ÿ'];

    private int $i = 0;
    /** What the group sets: the formats of characters, the style of the paragraph, and what the group holds. */
    private RtfState $state;
    /** @var list<RtfState> */
    private array $stack = [];
    /** @var list<Paragraph> */
    private array $paras = [];
    private Paragraph $cur;
    /** The run being read, and its attributes. */
    private string $text = '';
    private Attributes $runA;
    /** A field's instruction, and a paragraph's list text. */
    private string $inst = '';
    private string $marker = '';
    private bool $listed = false;
    private int $level = 0;

    private function __construct(private readonly string $s)
    {
        $this->state = new RtfState;
        $this->cur = new Paragraph;
        $this->runA = new Attributes;
    }

    public static function parse(string $data): Text
    {
        $r = new self(rtrim($data, "\x00"));
        $r->read();
        $r->endParagraph(false);
        while (count($r->paras) > 1 && $r->paras[count($r->paras) - 1]->runs === []) { array_pop($r->paras); }
        return $r->paras === [] ? new Text : Text::of(...$r->paras);
    }

    /** Ends the run being read. */
    private function flush(): void
    {
        if ($this->text !== '') {
            $this->cur->runs[] = new Run($this->text, $this->runA);
            $this->text = '';
        }
    }

    /** Adds text in the current attributes. */
    private function emit(string $s): void
    {
        $st = $this->state;
        if ($st->list) { $this->marker .= $s; return; }
        if ($st->field === 'inst') { $this->inst .= $s; return; }
        if ($st->skip || $st->hide) { return; }
        $a = $st->a;
        if ($st->field === 'result' && $st->link !== '') {
            $a = clone $a;
            $a->link = $st->link;
        }
        if (!$a->equals($this->runA)) {
            $this->flush();
            $this->runA = clone $a;
        }
        $this->text .= $s;
    }

    /** Ends the paragraph being read, in the style of the group. */
    private function endParagraph(bool $always): void
    {
        $this->flush();
        if (!$always && $this->cur->runs === [] && !$this->listed) { return; }
        $p = $this->cur;
        $p->style->align = $this->state->para->align;
        if ($this->listed) {
            $m = trim($this->marker);
            $p->style->list = ListKind::Bullets;
            if ($m !== '' && (ctype_digit($m[0]) || (strlen($m) > 1 && str_ends_with($m, '.')))) {
                $p->style->list = ListKind::Numbers;
            }
            $p->style->indent = $this->level;
        }
        $this->paras[] = $p;
        $this->cur = new Paragraph;
        $this->marker = '';
        $this->listed = false;
        $this->level = 0;
    }

    /** Reads the document's groups, control words and text. */
    private function read(): void
    {
        $s = $this->s;
        $n = strlen($s);
        $pendingSkip = false; // after \*: the group is a destination to skip
        while ($this->i < $n) {
            $c = $s[$this->i];
            if ($c === '{') {
                $this->stack[] = clone $this->state;
                $this->i++;
            } elseif ($c === '}') {
                $wasInst = $this->state->field === 'inst';
                if ($this->stack !== []) { $this->state = array_pop($this->stack); }
                if ($wasInst && $this->state->field !== 'inst') {
                    // The field's group: its result links to the URL of its instruction.
                    $this->state->link = HtmlWriter::safeLink(self::hyperlink($this->inst));
                    $this->inst = '';
                }
                $this->i++;
            } elseif ($c === '\\') {
                $this->i++;
                if ($this->i >= $n) { return; }
                $c = $s[$this->i];
                if ($c === "'" && $this->i + 2 < $n) {
                    // A character of the code page, as Windows-1252.
                    $hex = substr($s, $this->i + 1, 2);
                    if (ctype_xdigit($hex)) { $this->emit(self::cp1252(hexdec($hex))); }
                    $this->i += 3;
                } elseif ($c === '*') {
                    $pendingSkip = true;
                    $this->i++;
                } elseif ($c === '\\' || $c === '{' || $c === '}') {
                    $this->emit($c);
                    $this->i++;
                } elseif ($c === '~') {
                    $this->emit("\u{A0}");
                    $this->i++;
                } elseif ($c === '_') {
                    $this->emit("\u{2011}"); // a hyphen lines don't break at
                    $this->i++;
                } elseif ($c === "\n" || $c === "\r") {
                    $this->endParagraph(true); // \ and a newline is a \par
                    $this->i++;
                } elseif (ctype_alpha($c)) {
                    [$word, $num, $hasNum] = $this->word();
                    if ($pendingSkip) {
                        $pendingSkip = false;
                        if ($word !== 'fldinst') { $this->state->skip = true; }
                    }
                    $this->control($word, $num, $hasNum);
                } else {
                    $this->i++;
                }
            } elseif ($c === "\r" || $c === "\n") {
                $this->i++;
            } else {
                // Text up to the next special character.
                $j = $this->i + strcspn($s, "\\{}\r\n", $this->i);
                $this->emit(substr($s, $this->i, $j - $this->i));
                $this->i = $j;
            }
        }
    }

    /** Reads a control word and its number, and the space ending them. @return array{0: string, 1: int, 2: bool} */
    private function word(): array
    {
        $s = $this->s;
        preg_match('/\G([a-zA-Z]+)(-?\d*) ?/', $s, $m, 0, $this->i);
        $this->i += strlen($m[0]);
        $hasNum = $m[2] !== '' && $m[2] !== '-';
        return [$m[1], $hasNum ? (int)$m[2] : 0, $hasNum];
    }

    /** Applies a control word. */
    private function control(string $word, int $num, bool $hasNum): void
    {
        $st = $this->state;
        $on = !$hasNum || $num !== 0;
        if (in_array($word, self::SKIP, true)) {
            $st->skip = true;
            return;
        }
        $body = !$st->skip && !$st->list && $st->field !== 'inst';
        switch ($word) {
            case 'field': $st->field = ''; $st->link = ''; break;
            case 'fldinst': $st->field = 'inst'; $st->skip = false; break;
            case 'fldrslt': $st->field = 'result'; break;
            case 'listtext': case 'pntext':
                // The marker of a list's item, as Word and WordPad write it.
                $st->list = true;
                $this->listed = true;
                break;
            case 'ls': $this->listed = true; break;
            case 'ilvl': $this->level = max(0, $num); break;
            case 'par': case 'line': case 'sect': case 'page': case 'row':
                if ($body) { $this->endParagraph(true); }
                break;
            case 'pard': $st->para = new ParagraphStyle; break;
            case 'ql': case 'qj': $st->para = clone $st->para; $st->para->align = Params::START; break;
            case 'qc': $st->para = clone $st->para; $st->para->align = Params::CENTER; break;
            case 'qr': $st->para = clone $st->para; $st->para->align = Params::END; break;
            case 'plain': $st->a = new Attributes; $st->hide = false; break;
            case 'b': $st->a = clone $st->a; $st->a->weight = $on ? 700 : 0; break;
            case 'i': $st->a = clone $st->a; $st->a->italic = $on; break;
            case 'ul': case 'uld': case 'uldb': case 'ulw': case 'ulth': $st->a = clone $st->a; $st->a->underline = $on; break;
            case 'ulnone': $st->a = clone $st->a; $st->a->underline = false; break;
            case 'strike': case 'striked': $st->a = clone $st->a; $st->a->strikethrough = $on; break;
            case 'tab': case 'cell': $this->emit("\t"); break;
            case 'v': $st->hide = $on; break;
            case 'emdash': $this->emit('—'); break;
            case 'endash': $this->emit('–'); break;
            case 'bullet': $this->emit('•'); break;
            case 'lquote': $this->emit('‘'); break;
            case 'rquote': $this->emit('’'); break;
            case 'ldblquote': $this->emit('“'); break;
            case 'rdblquote': $this->emit('”'); break;
            case 'uc': $st->uc = $num; break;
            case 'u':
                if ($num < 0) { $num += 65536; }
                $rn = $num;
                // A surrogate pair comes as two \u.
                if ($rn >= 0xD800 && $rn <= 0xDBFF) {
                    $this->skipFallback();
                    if (substr_compare($this->s, '\\u', $this->i, 2) === 0) {
                        $this->i++;
                        [, $low] = $this->word();
                        if ($low < 0) { $low += 65536; }
                        $rn = 0x10000 + (($rn - 0xD800) << 10) + ($low - 0xDC00);
                    }
                }
                if ($rn >= 0 && $rn <= 0x10FFFF && !($rn >= 0xD800 && $rn <= 0xDFFF)) { $this->emit(mb_chr($rn, 'UTF-8')); }
                $this->skipFallback();
                break;
        }
    }

    /** Skips the characters after a \u that stand for it where Unicode is not read. */
    private function skipFallback(): void
    {
        $s = $this->s;
        $n = strlen($s);
        for ($k = $this->state->uc; $k > 0 && $this->i < $n; $k--) {
            if ($s[$this->i] === '\\' && $this->i + 3 < $n && $s[$this->i + 1] === "'") {
                $this->i += 4;
            } elseif ($s[$this->i] === '\\' || $s[$this->i] === '{' || $s[$this->i] === '}') {
                return;
            } else {
                $this->i++;
            }
        }
    }

    /** The URL of a field's instruction, HYPERLINK "url". */
    private static function hyperlink(string $inst): string
    {
        $inst = trim($inst);
        if (!str_starts_with($inst, 'HYPERLINK')) { return ''; }
        $rest = trim(substr($inst, 9));
        // \l "name" links to a bookmark of the document.
        if (str_starts_with($rest, '\\l')) {
            $u = self::hyperlink('HYPERLINK ' . trim(substr($rest, 2)));
            return $u !== '' ? '#' . $u : '';
        }
        if (str_starts_with($rest, '"') && ($end = strpos($rest, '"', 1)) !== false) {
            return substr($rest, 1, $end - 1);
        }
        $f = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY);
        return $f[0] ?? '';
    }

    /** The character of a byte of Windows-1252. */
    private static function cp1252(int $b): string
    {
        if ($b >= 0x80 && $b < 0xA0) { return self::CP1252[$b - 0x80]; }
        return mb_chr($b, 'UTF-8');
    }
}
