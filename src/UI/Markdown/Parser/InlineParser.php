<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * The inline parser (mygo's inlineParser, inline.go, autolink.go and
 * heal.go): CommonMark's delimiter algorithm over a tree of nodes, node
 * 0 its root, flattened into a block's inlines in source offsets.
 *
 * @internal
 */
final class InlineParser
{
    private const MAX_BACKTICKS = 1000;
    private const SPECIAL = "\n\\`*_[]!<&~\$:w";

    private Document $d;
    private int $ext = 0;
    private string $s = '';
    private int $pos = 0;
    /** @var list<InlineNode> */
    private array $nodes = [];
    /** @var list<Delimiter> */
    private array $dl = [];
    /** The last delimiter. */
    private int $last = -1;
    /** @var list<Bracket> */
    private array $br = [];
    /** The last bracket. */
    private int $lastB = -1;
    /** How many brackets there were when a link last closed: those before it, but for images, open no link, as links do not nest (mygo's linked), which a link closing marks in one step rather than walking them all. */
    private int $linked = 0;
    /** @var list<int> */
    private array $backticks;
    private bool $scannedBackticks = false;
    /** @var array{pi: int, comment: int, cdata: int, decl: int} */
    private array $html = ['pi' => -1, 'comment' => -1, 'cdata' => -1, 'decl' => -1];
    /** No closing $$ after this offset. */
    private int $noMath = -1;
    /** The offsets after which no $, \) or \] closes math, so that openers repeated stay linear. */
    private int $noDollar = -1, $noParen = -1, $noBracket = -1;
    /** Where $$ opens math that nothing closes, or -1, and a bracket healing leaves out, as the text of a link being written; where a single $ opens math (Extensions::DOLLAR_MATH) that nothing closes up to the end of the text, or -1. */
    private int $openMath = -1, $hide = -1, $openDollar = -1;
    /** @var list<string> */
    private array $labels = [];
    /** @var list<Delimiter> the openers no closer matched, for healing */
    private array $leftover = [];

    public function __construct()
    {
        $this->backticks = array_fill(0, self::MAX_BACKTICKS + 1, 0);
    }

    /** The inlines of block $i, and the labels they looked up; $heal completes what the end of the block leaves open, as the document streams. @return array{0: list<Inline>, 1: list<string>} */
    public function parse(Document $d, int $i, bool $heal): array
    {
        [$text] = $d->contentOf($i);
        // Paragraphs and headings are trimmed of spaces and tabs.
        $a = 0; $b = strlen($text);
        while ($a < $b && ($text[$a] === ' ' || $text[$a] === "\t" || $text[$a] === "\n")) { $a++; }
        while ($b > $a && ($text[$b - 1] === ' ' || $text[$b - 1] === "\t" || $text[$b - 1] === "\n")) { $b--; }
        $s = substr($text, $a, $b - $a);
        $this->d = $d; $this->ext = $d->ext;
        $this->labels = [];
        $this->hide = -1;
        $healFrom = strlen($s);
        $built = false;
        if ($heal) {
            [$s, $healFrom, $built] = $this->heal($s);
        }
        if (!$built) {
            $this->run($s);
        }
        $out = $this->flatten($d, $i, $a, $healFrom);
        $labels = $this->labels;
        $this->labels = [];
        return [$out, $labels];
    }

    /** Parses $s into the node tree, node 0 its root. */
    private function run(string $s): void
    {
        $this->s = $s; $this->pos = 0;
        $root = new InlineNode(Inline::TEXT, '', 0, strlen($s));
        $this->nodes = [$root];
        $this->dl = [];
        $this->br = [];
        $this->last = -1; $this->lastB = -1; $this->linked = 0;
        $this->scannedBackticks = false;
        $this->html = ['pi' => -1, 'comment' => -1, 'cdata' => -1, 'decl' => -1];
        $this->noMath = -1;
        $this->noDollar = -1; $this->noParen = -1; $this->noBracket = -1; $this->openMath = -1; $this->openDollar = -1;
        $this->leftover = [];
        $n = strlen($s);
        while ($this->pos < $n) {
            $this->parseInline();
        }
        $this->processEmphasis(0, true);
        $this->joinTexts(0);
        if (($this->ext & Extensions::AUTOLINKS) !== 0 && str_contains($s, '@')) {
            $this->emailLinks(0);
        }
    }

    private function newNode(int $kind, string $text, int $start, int $end): int
    {
        $this->nodes[] = new InlineNode($kind, $text, $start, $end);
        return count($this->nodes) - 1;
    }

    private function appendChild(int $parent, int $child): void
    {
        $n = $this->nodes[$child];
        $n->parent = $parent;
        $p = $this->nodes[$parent];
        $n->prev = $p->last; $n->next = -1;
        if ($p->last >= 0) {
            $this->nodes[$p->last]->next = $child;
        } else {
            $p->first = $child;
        }
        $p->last = $child;
    }

    private function unlink(int $c): void
    {
        $n = $this->nodes[$c];
        if ($n->prev >= 0) {
            $this->nodes[$n->prev]->next = $n->next;
        } elseif ($n->parent >= 0) {
            $this->nodes[$n->parent]->first = $n->next;
        }
        if ($n->next >= 0) {
            $this->nodes[$n->next]->prev = $n->prev;
        } elseif ($n->parent >= 0) {
            $this->nodes[$n->parent]->last = $n->prev;
        }
        $n->parent = -1; $n->prev = -1; $n->next = -1;
    }

    private function insertAfter(int $c, int $sib): void
    {
        $this->unlink($sib);
        $n = $this->nodes[$c];
        $s = $this->nodes[$sib];
        $s->parent = $n->parent; $s->prev = $c; $s->next = $n->next;
        if ($n->next >= 0) {
            $this->nodes[$n->next]->prev = $sib;
        } elseif ($n->parent >= 0) {
            $this->nodes[$n->parent]->last = $sib;
        }
        $n->next = $sib;
    }

    private function insertBefore(int $c, int $sib): void
    {
        $this->unlink($sib);
        $n = $this->nodes[$c];
        $s = $this->nodes[$sib];
        $s->parent = $n->parent; $s->next = $c; $s->prev = $n->prev;
        if ($n->prev >= 0) {
            $this->nodes[$n->prev]->next = $sib;
        } elseif ($n->parent >= 0) {
            $this->nodes[$n->parent]->first = $sib;
        }
        $n->prev = $sib;
    }

    /** Adds the subject's [$a, $b) as text, merged into the text before it when that ends at $a. */
    private function addText(int $a, int $b): void
    {
        if ($a >= $b) {
            return;
        }
        $l = $this->nodes[0]->last;
        if ($l >= 0) {
            $n = $this->nodes[$l];
            // A plain node's text is the subject's bytes it spans, which its length tells (Go compares the slice, the same bytes, at once).
            if ($n->kind === Inline::TEXT && $n->plain && $n->end === $a && ($n->lazy || strlen($n->text) === $n->end - $n->start)) {
                $n->end = $b;
                $n->lazy = true;
                return;
            }
        }
        $c = $this->newNode(Inline::TEXT, substr($this->s, $a, $b - $a), $a, $b);
        $this->nodes[$c]->plain = true;
        $this->appendChild(0, $c);
    }

    /** The text of node $c, made the subject's bytes it spans once more were merged into it (addText). */
    private function text(int $c): string
    {
        $n = $this->nodes[$c];
        if ($n->lazy) {
            $n->text = substr($this->s, $n->start, $n->end - $n->start);
            $n->lazy = false;
        }
        return $n->text;
    }

    /** Adds text that is not the subject's bytes [$a, $b), as an escaped character's. */
    private function addLiteral(string $text, int $a, int $b): int
    {
        $c = $this->newNode(Inline::TEXT, $text, $a, $b);
        $this->appendChild(0, $c);
        return $c;
    }

    private function parseInline(): void
    {
        $s = $this->s;
        $c = $s[$this->pos];
        switch ($c) {
            case "\n":
                $this->parseNewline();
                break;
            case '\\':
                $this->parseBackslash();
                break;
            case '`':
                $this->parseBackticks();
                break;
            case '*':
            case '_':
                $this->handleDelim($c);
                break;
            case '~':
                if (($this->ext & Extensions::STRIKETHROUGH) !== 0) {
                    $this->handleDelim($c);
                } else {
                    $this->addText($this->pos, $this->pos + 1);
                    $this->pos++;
                }
                break;
            case '[':
                $n = $this->addLiteral($this->pos === $this->hide ? '' : '[', $this->pos, $this->pos + 1);
                $this->addBracket($n, $this->pos + 1, false);
                $this->pos++;
                break;
            case '!':
                if ($this->pos + 1 < strlen($s) && $s[$this->pos + 1] === '[') {
                    $n = $this->addLiteral($this->pos === $this->hide ? '' : '![', $this->pos, $this->pos + 2);
                    $this->addBracket($n, $this->pos + 2, true);
                    $this->pos += 2;
                } else {
                    $this->addText($this->pos, $this->pos + 1);
                    $this->pos++;
                }
                break;
            case ']':
                $this->parseCloseBracket();
                break;
            case '<':
                $this->parseAngle();
                break;
            case '&':
                [$n, $text] = Scan::entity($s, $this->pos);
                if ($n > 0) {
                    $this->addLiteral($text, $this->pos, $this->pos + $n);
                    $this->pos += $n;
                } else {
                    $this->addText($this->pos, $this->pos + 1);
                    $this->pos++;
                }
                break;
            case '$':
                if (($this->ext & Extensions::MATH) === 0 || !$this->parseDollar()) {
                    $this->addText($this->pos, $this->pos + 1);
                    $this->pos++;
                }
                break;
            case ':':
                if (($this->ext & Extensions::AUTOLINKS) === 0 || !$this->urlLink()) {
                    $this->addText($this->pos, $this->pos + 1);
                    $this->pos++;
                }
                break;
            case 'w':
                if (($this->ext & Extensions::AUTOLINKS) === 0 || !$this->wwwLink()) {
                    $this->parseString();
                }
                break;
            default:
                $this->parseString();
        }
    }

    /** Adds the text up to the next byte that may start syntax. */
    private function parseString(): void
    {
        $s = $this->s; $n = strlen($s);
        $j = $this->pos + 1;
        while ($j < $n) {
            $j += strcspn($s, self::SPECIAL, $j);
            if ($j >= $n) {
                break;
            }
            $c = $s[$j];
            if ($c === 'w' && (substr($s, $j, 4) !== 'www.' || ($this->ext & Extensions::AUTOLINKS) === 0)
                || $c === ':' && (substr($s, $j, 3) !== '://' || ($this->ext & Extensions::AUTOLINKS) === 0)
                || $c === '$' && ($this->ext & Extensions::MATH) === 0
                || $c === '~' && ($this->ext & Extensions::STRIKETHROUGH) === 0) {
                $j++;
                continue;
            }
            break;
        }
        $j = min($j, $n);
        $this->addText($this->pos, $j);
        $this->pos = $j;
    }

    private function parseNewline(): void
    {
        $this->pos++;
        $hard = false;
        $l = $this->nodes[0]->last;
        if ($l >= 0) {
            $n = $this->nodes[$l];
            if ($n->kind === Inline::TEXT && str_ends_with($this->text($l), ' ')) {
                $t = rtrim($n->text, ' ');
                $hard = strlen($n->text) - strlen($t) >= 2;
                $n->text = $t;
                if ($n->plain) {
                    $n->end = $n->start + strlen($t);
                }
            }
        }
        $c = $this->newNode($hard ? Inline::LINE_BREAK : Inline::SOFT_BREAK, '', $this->pos - 1, $this->pos);
        $this->appendChild(0, $c);
        $len = strlen($this->s);
        while ($this->pos < $len && ($this->s[$this->pos] === ' ' || $this->s[$this->pos] === "\t")) { $this->pos++; }
    }

    private function parseBackslash(): void
    {
        $s = $this->s; $len = strlen($s);
        $this->pos++;
        if ($this->pos < $len) {
            $c = $s[$this->pos];
            if ($c === "\n") {
                $this->pos++;
                $n = $this->newNode(Inline::LINE_BREAK, '', $this->pos - 2, $this->pos);
                $this->appendChild(0, $n);
                while ($this->pos < $len && ($s[$this->pos] === ' ' || $s[$this->pos] === "\t")) { $this->pos++; }
                return;
            }
            if (($c === '(' || $c === '[') && ($this->ext & Extensions::MATH) !== 0) {
                $end = $c === '[' ? '\]' : '\)';
                $none = $c === '[' ? $this->noBracket : $this->noParen;
                $k = -1;
                if ($none < 0 || $this->pos < $none) {
                    $f = strpos($s, $end, $this->pos + 1);
                    if ($f === false) {
                        if ($c === '[') { $this->noBracket = $this->pos; } else { $this->noParen = $this->pos; }
                    } else {
                        $k = $f - ($this->pos + 1);
                    }
                }
                if ($k > 0) {
                    $tex = substr($s, $this->pos + 1, $k);
                    $n = $this->newNode(Inline::MATH, $tex, $this->pos - 1, $this->pos + 1 + $k + 2);
                    $this->nodes[$n]->display = $c === '[';
                    $this->appendChild(0, $n);
                    $this->pos += 1 + $k + 2;
                    return;
                }
                $this->addLiteral($c, $this->pos - 1, $this->pos + 1);
                $this->pos++;
                return;
            }
            if (Scan::isEscapable($c)) {
                $this->addLiteral($c, $this->pos - 1, $this->pos + 1);
                $this->pos++;
                return;
            }
        }
        $this->addText($this->pos - 1, $this->pos);
    }

    private function parseBackticks(): void
    {
        $s = $this->s; $len = strlen($s);
        $start = $this->pos;
        while ($this->pos < $len && $s[$this->pos] === '`') { $this->pos++; }
        $n = $this->pos - $start;
        $after = $this->pos;
        $end = $this->scanCloseBackticks($n);
        if ($end > 0) {
            $content = substr($s, $after, $end - $n - $after);
            if (str_contains($content, "\n")) {
                $content = str_replace("\n", ' ', $content);
            }
            if (strlen($content) >= 2 && $content[0] === ' ' && $content[strlen($content) - 1] === ' ' && trim($content, ' ') !== '') {
                $content = substr($content, 1, -1);
            }
            $c = $this->newNode(Inline::CODE, $content, $start, $end);
            $this->appendChild(0, $c);
            $this->pos = $end;
            return;
        }
        $this->pos = $after;
        $this->addText($start, $after);
    }

    /** The offset after a run of $n backticks from pos, or 0, remembering the last run of each length so that unmatched openers do not take quadratic time, as cmark does. */
    private function scanCloseBackticks(int $n): int
    {
        if ($n > self::MAX_BACKTICKS) {
            return 0;
        }
        if ($this->scannedBackticks && $this->backticks[$n] <= $this->pos) {
            return 0;
        }
        $s = $this->s; $len = strlen($s);
        $i = $this->pos;
        while (($k = strpos($s, '`', $i)) !== false) {
            $i = $k;
            $j = $i;
            while ($j < $len && $s[$j] === '`') { $j++; }
            $m = $j - $i;
            if ($m <= self::MAX_BACKTICKS) {
                $this->backticks[$m] = $i;
            }
            if ($m === $n) {
                return $j;
            }
            $i = $j;
        }
        $this->scannedBackticks = true;
        return 0;
    }

    /** Reads math between $$ (or $ with DOLLAR_MATH) at pos. */
    private function parseDollar(): bool
    {
        $s = $this->s; $len = strlen($s);
        $start = $this->pos;
        if (substr($s, $start, 2) === '$$') {
            if ($this->noMath >= 0 && $start >= $this->noMath) {
                return false;
            }
            $f = strpos($s, '$$', $start + 2);
            if ($f === false) {
                $this->noMath = $start;
                if ($this->openMath < 0) {
                    $this->openMath = $start;
                }
                return false;
            }
            $k = $f - ($start + 2);
            if (trim(substr($s, $start + 2, $k), " \t\n") === '') {
                return false;
            }
            $n = $this->newNode(Inline::MATH, substr($s, $start + 2, $k), $start, $start + 2 + $k + 2);
            $this->nodes[$n]->display = true;
            $this->appendChild(0, $n);
            $this->pos = $start + 2 + $k + 2;
            return true;
        }
        if (($this->ext & Extensions::DOLLAR_MATH) === 0) {
            return false;
        }
        // A dollar followed by a space or a digit is money, not math.
        if ($start + 1 >= $len || $s[$start + 1] === ' ' || $s[$start + 1] === "\t" || $s[$start + 1] === "\n" || Scan::isDigit($s[$start + 1])) {
            return false;
        }
        if ($this->noDollar >= 0 && $start >= $this->noDollar) {
            return false;
        }
        for ($j = $start + 1; $j < $len; $j++) {
            $c = $s[$j];
            if ($c === '\\') {
                $j++;
            } elseif ($c === "\n") {
                if ($j + 1 < $len && $s[$j + 1] === "\n") {
                    $this->noDollar = $start;
                    return false;
                }
            } elseif ($c === '$') {
                $p = $s[$j - 1];
                if ($p === ' ' || $p === "\t" || $p === "\n" || $j + 1 < $len && Scan::isDigit($s[$j + 1])) {
                    continue;
                }
                $n = $this->newNode(Inline::MATH, substr($s, $start + 1, $j - $start - 1), $start, $j + 1);
                $this->appendChild(0, $n);
                $this->pos = $j + 1;
                return true;
            }
        }
        $this->noDollar = $start;
        if ($this->openDollar < 0) {
            $this->openDollar = $start;
        }
        return false;
    }

    /** Reads an autolink or raw HTML at <. */
    private function parseAngle(): void
    {
        $s = $this->s;
        [$n, $dest] = self::scanAutolink($s, $this->pos);
        if ($n > 0) {
            $inner = substr($s, $this->pos + 1, $n - 2);
            $l = $this->newNode(Inline::LINK, '', $this->pos, $this->pos + $n);
            $this->nodes[$l]->dest = $dest;
            $this->nodes[$l]->auto = true;
            $this->appendChild(0, $l);
            $t = $this->newNode(Inline::TEXT, $inner, $this->pos + 1, $this->pos + $n - 1);
            $this->appendChild($l, $t);
            $this->pos += $n;
            return;
        }
        $n = Scan::htmlTag($s, $this->pos, $this->html);
        if ($n > 0) {
            $h = $this->newNode(Inline::HTML, substr($s, $this->pos, $n), $this->pos, $this->pos + $n);
            $this->appendChild(0, $h);
            $this->pos += $n;
            return;
        }
        $this->addText($this->pos, $this->pos + 1);
        $this->pos++;
    }

    /** <scheme:…> or <email> at $i: its length, and its destination. @return array{0: int, 1: string} */
    private static function scanAutolink(string $s, int $i): array
    {
        $len = strlen($s);
        $j = $i + 1;
        // A URI: a scheme of 2 to 32 characters, a colon, no spaces or <>.
        $k = $j;
        while ($k < $len && $k - $j < 33 && (Scan::isLetter($s[$k]) || $k > $j && (Scan::isDigit($s[$k]) || $s[$k] === '+' || $s[$k] === '.' || $s[$k] === '-'))) { $k++; }
        $n = $k - $j;
        if ($n >= 2 && $n <= 32 && $k < $len && $s[$k] === ':') {
            $e = $k + 1;
            while ($e < $len && ord($s[$e]) > 0x20 && $s[$e] !== '<' && $s[$e] !== '>') { $e++; }
            if ($e < $len && $s[$e] === '>') {
                return [$e + 1 - $i, substr($s, $j, $e - $j)];
            }
        }
        // An email address.
        $k = $j;
        while ($k < $len && (Scan::isLetter($s[$k]) || Scan::isDigit($s[$k]) || str_contains(".!#\$%&'*+/=?^_`{|}~-", $s[$k]))) { $k++; }
        if ($k === $j || $k >= $len || $s[$k] !== '@') {
            return [0, ''];
        }
        $e = $k + 1;
        while (true) {
            // A label: alphanumerics and inner hyphens, at most 63.
            $l = $e;
            while ($e < $len && (Scan::isLetter($s[$e]) || Scan::isDigit($s[$e]) || $s[$e] === '-') && $e - $l < 64) { $e++; }
            if ($e === $l || $e - $l > 63 || $s[$l] === '-' || $s[$e - 1] === '-') {
                return [0, ''];
            }
            if ($e < $len && $s[$e] === '.') {
                $e++;
                continue;
            }
            break;
        }
        if ($e < $len && $s[$e] === '>') {
            return [$e + 1 - $i, 'mailto:' . substr($s, $j, $e - $j)];
        }
        return [0, ''];
    }

    /** A run of delimiter $c at pos: its length, and whether it can open and close emphasis. @return array{0: int, 1: bool, 2: bool} */
    private function scanDelims(string $c): array
    {
        $s = $this->s; $len = strlen($s);
        $i = $this->pos;
        while ($i < $len && $s[$i] === $c) { $i++; }
        $n = $i - $this->pos;
        $before = $this->pos > 0 ? Scan::lastRune($s, $this->pos) : 0x0A;
        $after = $i < $len ? Scan::rune($s, $i)[0] : 0x0A;
        $afterSpace = Scan::isUnicodeSpace($after); $afterPunct = Scan::isPunct($after);
        $beforeSpace = Scan::isUnicodeSpace($before); $beforePunct = Scan::isPunct($before);
        $left = !$afterSpace && (!$afterPunct || $beforeSpace || $beforePunct);
        $right = !$beforeSpace && (!$beforePunct || $afterSpace || $afterPunct);
        if ($c === '_') {
            $canOpen = $left && (!$right || $beforePunct);
            $canClose = $right && (!$left || $afterPunct);
        } elseif ($c === '~') {
            $canOpen = $left; $canClose = $right;
            // A tilde between letters or digits is not strikethrough, as 20~25, which language models write for ranges.
            if ($n === 1 && Scan::isAlnum($before) && Scan::isAlnum($after)) {
                $canOpen = false; $canClose = false;
            }
        } else {
            $canOpen = $left; $canClose = $right;
        }
        return [$n, $canOpen, $canClose];
    }

    private function handleDelim(string $c): void
    {
        [$n, $canOpen, $canClose] = $this->scanDelims($c);
        $start = $this->pos;
        $this->pos += $n;
        $node = $this->addLiteral(substr($this->s, $start, $n), $start, $this->pos);
        if ($c === '~' && $n > 2) {
            return;
        }
        if ($canOpen || $canClose) {
            $this->dl[] = new Delimiter($c, $n, $n, $node, $this->last, -1, $canOpen, $canClose, $start);
            $k = count($this->dl) - 1;
            if ($this->last >= 0) {
                $this->dl[$this->last]->next = $k;
            }
            $this->last = $k;
        }
    }

    private function removeDelim(int $k): void
    {
        $d = $this->dl[$k];
        if ($d->prev >= 0) {
            $this->dl[$d->prev]->next = $d->next;
        }
        if ($d->next >= 0) {
            $this->dl[$d->next]->prev = $d->prev;
        } else {
            $this->last = $d->prev;
        }
        $d->prev = -1; $d->next = -1;
        $d->n = -1; // removed
    }

    /** Matches the delimiters at or after offset $bottom, as CommonMark's algorithm does, openersBottom keeping it linear; $final notes the openers left unmatched, for healing. */
    private function processEmphasis(int $bottom, bool $final): void
    {
        $openersBottom = array_fill(0, 3, array_fill(0, 6, $bottom));
        $closer = -1;
        for ($c = $this->last; $c >= 0 && $this->dl[$c]->at >= $bottom; $c = $this->dl[$c]->prev) {
            $closer = $c;
        }
        while ($closer >= 0) {
            $cd = $this->dl[$closer];
            if (!$cd->canClose) {
                $closer = $cd->next;
                continue;
            }
            $ci = self::delimClass($cd->c, $cd->canOpen);
            $opener = $cd->prev;
            $found = false;
            while ($opener >= 0 && $this->dl[$opener]->at >= $openersBottom[$cd->orig % 3][$ci]) {
                $od = $this->dl[$opener];
                $odd = ($cd->canOpen || $od->canClose) && $cd->orig % 3 !== 0 && ($od->orig + $cd->orig) % 3 === 0;
                if ($od->c === $cd->c && $od->canOpen && !$odd) {
                    $found = true;
                    break;
                }
                $opener = $od->prev;
            }
            if (!$found) {
                $old = $closer;
                $closer = $cd->next;
                $openersBottom[$this->dl[$old]->orig % 3][$ci] = $this->dl[$old]->at;
                if (!$this->dl[$old]->canOpen) {
                    $this->removeDelim($old);
                }
                continue;
            }
            if ($cd->c === '~') {
                $closer = $this->insertStrike($opener, $closer);
                continue;
            }
            $closer = $this->insertEmph($opener, $closer);
        }
        // What remains matched nothing.
        while ($this->last >= 0 && $this->dl[$this->last]->at >= $bottom) {
            $d = $this->dl[$this->last];
            if ($final && $d->canOpen && $d->n > 0) {
                $this->leftover[] = clone $d;
            }
            $this->removeDelim($this->last);
        }
    }

    private static function delimClass(string $c, bool $canOpen): int
    {
        $k = $c === '_' ? 2 : ($c === '~' ? 4 : 0);
        return $canOpen ? $k + 1 : $k;
    }

    /** Wraps what is between $opener and $closer in emphasis, one or two delimiters of each, and returns the next closer to look at. */
    private function insertEmph(int $opener, int $closer): int
    {
        $od = $this->dl[$opener]; $cd = $this->dl[$closer];
        $use = $cd->n >= 2 && $od->n >= 2 ? 2 : 1;
        $on = $od->node; $cn = $cd->node;
        $od->n -= $use;
        $cd->n -= $use;
        $o = $this->nodes[$on]; $c = $this->nodes[$cn];
        $o->text = substr($o->text, 0, strlen($o->text) - $use);
        $o->end -= $use;
        $c->text = substr($c->text, 0, strlen($c->text) - $use);
        $c->start += $use;
        $emph = $this->newNode($use === 2 ? Inline::STRONG : Inline::EMPHASIS, '', $o->end, $c->start);
        for ($t = $o->next; $t >= 0 && $t !== $cn;) {
            $next = $this->nodes[$t]->next;
            $this->unlink($t);
            $this->appendChild($emph, $t);
            $t = $next;
        }
        $this->insertAfter($on, $emph);
        // The delimiters between are gone.
        for ($k = $this->dl[$closer]->prev; $k >= 0 && $k !== $opener;) {
            $prev = $this->dl[$k]->prev;
            $this->removeDelim($k);
            $k = $prev;
        }
        if ($this->dl[$opener]->n === 0) {
            $this->unlink($on);
            $this->removeDelim($opener);
        }
        if ($this->dl[$closer]->n === 0) {
            $this->unlink($cn);
            $next = $this->dl[$closer]->next;
            $this->removeDelim($closer);
            return $next;
        }
        return $closer;
    }

    /** Makes strikethrough of what is between two runs of tildes of the same length. */
    private function insertStrike(int $opener, int $closer): int
    {
        $next = $this->dl[$closer]->next;
        $odn = $this->dl[$opener]->n; $cdn = $this->dl[$closer]->n;
        if ($odn === $cdn) {
            $on = $this->dl[$opener]->node; $cn = $this->dl[$closer]->node;
            $st = $this->newNode(Inline::STRIKETHROUGH, '', $this->nodes[$on]->start, $this->nodes[$cn]->end);
            for ($t = $this->nodes[$on]->next; $t >= 0 && $t !== $cn;) {
                $n = $this->nodes[$t]->next;
                $this->unlink($t);
                $this->appendChild($st, $t);
                $t = $n;
            }
            $this->insertAfter($on, $st);
            $this->unlink($on);
            $this->unlink($cn);
        }
        for ($k = $closer; $k >= 0 && $k !== $opener;) {
            $prev = $this->dl[$k]->prev;
            $this->removeDelim($k);
            $k = $prev;
        }
        $this->removeDelim($opener);
        return $next;
    }

    private function addBracket(int $node, int $index, bool $image): void
    {
        if ($this->lastB >= 0) {
            $this->br[$this->lastB]->bracketAfter = true;
        }
        $this->br[] = new Bracket($node, $this->lastB, $this->last, $index, $image);
        $this->lastB = count($this->br) - 1;
    }

    private function popBracket(): void
    {
        $this->lastB = $this->br[$this->lastB]->prev;
    }

    private function parseCloseBracket(): void
    {
        $s = $this->s; $len = strlen($s);
        $this->pos++;
        $start = $this->pos; // after ]
        if ($this->lastB < 0) {
            $this->addText($start - 1, $start);
            return;
        }
        $op = $this->br[$this->lastB];
        if (!$op->image && $this->lastB < $this->linked) {
            $this->addText($start - 1, $start);
            $this->popBracket();
            return;
        }
        $dest = ''; $title = '';
        $matched = false;
        // An inline link.
        if ($this->pos < $len && $s[$this->pos] === '(') {
            $save = $this->pos;
            $this->pos++;
            $this->spnl();
            $d = $this->parseLinkDestination();
            if ($d !== null) {
                $dest = $d;
                $before = $this->pos;
                $this->spnl();
                if ($this->pos > $before) {
                    $t = $this->parseLinkTitle();
                    if ($t !== null) {
                        $title = $t;
                    } else {
                        $this->pos = $before;
                        $this->spnl();
                    }
                }
                $this->spnl();
                if ($this->pos < $len && $s[$this->pos] === ')') {
                    $this->pos++;
                    $matched = true;
                }
            }
            if (!$matched) {
                $this->pos = $save;
            }
        }
        if (!$matched) {
            // A reference: [text][label], [text][] or [text].
            $before = $this->pos;
            $n = $this->parseLinkLabel();
            $label = ''; $has = false;
            if ($n > 2) {
                $label = substr($s, $before, $n); $has = true;
            } elseif (!$op->bracketAfter) {
                $label = substr($s, $op->index - 1, $start - $op->index + 1); $has = true;
            }
            if ($n === 0) {
                $this->pos = $start;
            }
            if ($has) {
                $key = Document::normalizeLabel(substr($label, 1, -1));
                if ($key !== '') {
                    $this->noteLabel($key);
                    $r = $this->d->refs[$key] ?? null;
                    if ($r !== null) {
                        $dest = $r['dest']; $title = $r['title']; $matched = true;
                    }
                }
            }
        }
        if ($matched) {
            $openStart = $this->nodes[$op->node]->start;
            $l = $this->newNode($op->image ? Inline::IMAGE : Inline::LINK, '', $openStart, $this->pos);
            $this->nodes[$l]->dest = $dest; $this->nodes[$l]->title = $title;
            for ($t = $this->nodes[$op->node]->next; $t >= 0;) {
                $next = $this->nodes[$t]->next;
                $this->unlink($t);
                $this->appendChild($l, $t);
                $t = $next;
            }
            $this->appendChild(0, $l);
            $this->processEmphasis($op->index, false);
            $this->popBracket();
            $this->unlink($op->node);
            if (!$op->image) {
                $this->linked = count($this->br); // the brackets open before it are below: brackets are added at the end
            }
            return;
        }
        // A footnote reference: [^label], when the footnote is defined.
        if (($this->ext & Extensions::FOOTNOTES) !== 0 && !$op->image && !$op->bracketAfter && $op->index < $start - 1 && $s[$op->index] === '^' && $start - 1 > $op->index + 1 && $start - 1 - $op->index <= 1000) {
            $key = Document::normalizeLabel(substr($s, $op->index + 1, $start - 2 - $op->index));
            $this->noteLabel('^' . $key);
            if (isset($this->d->notes[$key])) {
                $this->pos = $start;
                $f = $this->newNode(Inline::FOOTNOTE_REF, substr($s, $op->index + 1, $start - 2 - $op->index), $this->nodes[$op->node]->start, $start);
                $this->processEmphasis($op->index, false);
                for ($t = $this->nodes[$op->node]->next; $t >= 0;) {
                    $next = $this->nodes[$t]->next;
                    $this->unlink($t);
                    $t = $next;
                }
                $this->insertBefore($op->node, $f);
                $this->unlink($op->node);
                $this->popBracket();
                return;
            }
        }
        $this->popBracket();
        $this->pos = $start;
        $this->addText($start - 1, $start);
    }

    private function noteLabel(string $key): void
    {
        if (!in_array($key, $this->labels, true)) {
            $this->labels[] = $key;
        }
    }

    /** Skips spaces and tabs, and one line ending with those around it. */
    private function spnl(): void
    {
        $s = $this->s; $len = strlen($s);
        while ($this->pos < $len && ($s[$this->pos] === ' ' || $s[$this->pos] === "\t")) { $this->pos++; }
        if ($this->pos < $len && $s[$this->pos] === "\n") {
            $this->pos++;
            while ($this->pos < $len && ($s[$this->pos] === ' ' || $s[$this->pos] === "\t")) { $this->pos++; }
        }
    }

    /** The length of a link label at pos, [ and ] included, or 0. */
    private function parseLinkLabel(): int
    {
        $s = $this->s; $len = strlen($s);
        $i = $this->pos;
        if ($i >= $len || $s[$i] !== '[') {
            return 0;
        }
        for ($j = $i + 1; $j < $len && $j - $i <= 1001; $j++) {
            $c = $s[$j];
            if ($c === '\\') {
                $j++;
            } elseif ($c === '[') {
                return 0;
            } elseif ($c === ']') {
                if ($j - $i > 1000) {
                    return 0;
                }
                $this->pos = $j + 1;
                return $j + 1 - $i;
            }
        }
        return 0;
    }

    /** A link destination at pos, <…> or bare with balanced parentheses; null for none. */
    private function parseLinkDestination(): ?string
    {
        $s = $this->s; $len = strlen($s);
        $i = $this->pos;
        if ($i < $len && $s[$i] === '<') {
            for ($j = $i + 1; $j < $len; $j++) {
                $c = $s[$j];
                if ($c === '\\') {
                    if ($j + 1 < $len && Scan::isEscapable($s[$j + 1])) {
                        $j++;
                    }
                } elseif ($c === "\n" || $c === '<') {
                    return null;
                } elseif ($c === '>') {
                    $this->pos = $j + 1;
                    return Scan::unescape(substr($s, $i + 1, $j - $i - 1));
                }
            }
            return null;
        }
        $parens = 0;
        $j = $i;
        while ($j < $len) {
            $c = $s[$j];
            if ($c === '\\' && $j + 1 < $len && Scan::isEscapable($s[$j + 1])) {
                $j += 2;
            } elseif ($c === '(') {
                $parens++;
                if ($parens > 32) {
                    return null;
                }
                $j++;
            } elseif ($c === ')') {
                if ($parens < 1) {
                    break;
                }
                $parens--;
                $j++;
            } elseif (ord($c) <= 0x20 || ord($c) === 0x7f) {
                break;
            } else {
                $j++;
            }
        }
        if ($j === $i && ($j >= $len || $s[$j] !== ')')) {
            return null;
        }
        if ($parens !== 0) {
            return null;
        }
        $this->pos = $j;
        return Scan::unescape(substr($s, $i, $j - $i));
    }

    /** A link title at pos: "…", '…' or (…); null for none. */
    private function parseLinkTitle(): ?string
    {
        $s = $this->s; $len = strlen($s);
        $i = $this->pos;
        if ($i >= $len) {
            return null;
        }
        $end = $s[$i];
        if ($end === '(') {
            $end = ')';
        } elseif ($end !== '"' && $end !== "'") {
            return null;
        }
        for ($j = $i + 1; $j < $len; $j++) {
            $c = $s[$j];
            if ($c === '\\' && $j + 1 < $len) {
                $j++;
            } elseif ($c === $end) {
                $this->pos = $j + 1;
                return Scan::unescape(substr($s, $i + 1, $j - $i - 1));
            } elseif ($c === '(' && $end === ')') {
                return null;
            }
        }
        return null;
    }

    /** Reads a link reference definition at the start of $s, adding it to the document unless its label is defined, and returns its length, or 0; $at is the offset of $s in the source. */
    public function parseReference(Document $d, string $s, int $at): int
    {
        $saveS = $this->s; $savePos = $this->pos;
        try {
            $this->s = $s; $this->pos = 0;
            $len = strlen($s);
            $n = $this->parseLinkLabel();
            if ($n === 0) {
                return 0;
            }
            $label = substr($s, 1, $n - 2);
            if ($this->pos >= $len || $s[$this->pos] !== ':') {
                return 0;
            }
            $this->pos++;
            $this->spnl();
            $dest = $this->parseLinkDestination();
            if ($dest === null) {
                return 0;
            }
            $beforeTitle = $this->pos;
            $this->spnl();
            $title = null;
            if ($this->pos !== $beforeTitle) {
                $title = $this->parseLinkTitle();
            }
            if ($title === null) {
                $this->pos = $beforeTitle;
            }
            $atEnd = function () use ($s, $len): bool {
                $j = $this->pos;
                while ($j < $len && ($s[$j] === ' ' || $s[$j] === "\t")) { $j++; }
                if ($j === $len || $s[$j] === "\n") {
                    if ($j < $len) {
                        $j++;
                    }
                    $this->pos = $j;
                    return true;
                }
                return false;
            };
            if (!$atEnd()) {
                if ($title === null) {
                    return 0;
                }
                $title = null;
                $this->pos = $beforeTitle;
                if (!$atEnd()) {
                    return 0;
                }
            }
            $key = Document::normalizeLabel($label);
            if ($key === '') {
                return 0;
            }
            $d->define($key, $dest, $title ?? '', $at);
            return $this->pos;
        } finally {
            $this->s = $saveS; $this->pos = $savePos;
        }
    }

    /** The tree as the block's inlines, in source offsets: content offsets from $base, those past $healFrom (text healing added) at $healFrom. @return list<Inline> */
    private function flatten(Document $d, int $block, int $base, int $healFrom): array
    {
        if ($this->nodes[0]->first < 0) {
            return [];
        }
        $out = [];
        $m = $d->sourceMap($block);
        $walk = function (int $n) use (&$walk, &$out, $m, $base, $healFrom): void {
            for ($c = $this->nodes[$n]->first; $c >= 0; $c = $this->nodes[$c]->next) {
                $this->text($c);
                $nd = $this->nodes[$c];
                if ($nd->kind === Inline::TEXT && $nd->text === '') {
                    continue;
                }
                $i = count($out);
                $in = new Inline($nd->kind, $nd->text, $nd->dest, $nd->title,
                    $m->source($base + min($nd->start, $healFrom)), $m->source($base + min($nd->end, $healFrom)),
                    auto: $nd->auto, display: $nd->display);
                if ($nd->end > $healFrom && ($nd->kind === Inline::LINK || $nd->kind === Inline::IMAGE)) {
                    $in->pending = true;
                }
                $out[] = $in;
                $walk($c);
                $out[$i]->next = count($out);
            }
        };
        $walk(0);
        return $out;
    }

    /**
     * Joins the runs of text among the children of node $n, and below it,
     * into one node each: a slice of the subject when their texts are its
     * bytes one after another, else the texts joined once, so that a run
     * of many, as delimiters left unmatched, takes linear time.
     */
    private function joinTexts(int $n): void
    {
        $sl = strlen($this->s);
        for ($c = $this->nodes[$n]->first; $c >= 0; $c = $this->nodes[$c]->next) {
            if ($this->nodes[$c]->kind !== Inline::TEXT) {
                $this->joinTexts($c);
                continue;
            }
            $last = $c;
            for ($nx = $this->nodes[$c]->next; $nx >= 0 && $this->nodes[$nx]->kind === Inline::TEXT; $nx = $this->nodes[$nx]->next) {
                $last = $nx;
            }
            if ($last === $c) {
                continue;
            }
            $first = $this->nodes[$c];
            $exact = true;
            for ($k = $c; ; $k = $this->nodes[$k]->next) {
                $this->text($k);
                $nd = $this->nodes[$k];
                $e = min($nd->end, $sl);
                if ($nd->text !== substr($this->s, $nd->start, max(0, $e - $nd->start)) || $k !== $c && $nd->start !== $this->nodes[$nd->prev]->end) {
                    $exact = false;
                }
                if ($k === $last) {
                    break;
                }
            }
            $end = $this->nodes[$last]->end;
            if ($exact) {
                $first->text = substr($this->s, $first->start, $end - $first->start);
            } else {
                $b = '';
                for ($k = $c; ; $k = $this->nodes[$k]->next) {
                    $b .= $this->nodes[$k]->text;
                    if ($k === $last) {
                        break;
                    }
                }
                $first->text = $b;
            }
            $first->end = $end; $first->plain = false;
            for ($k = $this->nodes[$c]->next; $k >= 0;) {
                $nx = $this->nodes[$k]->next;
                $this->unlink($k);
                if ($k === $last) {
                    break;
                }
                $k = $nx;
            }
        }
    }

    // GitHub's extended autolinks, as cmark-gfm finds them (autolink.c): www. and http(s):// links as the
    // inline parser comes to their w and :, email addresses in the text once parsed.

    private static function isHostChar(string $s, int $i): bool
    {
        [$r, $n] = Scan::rune($s, $i);
        if ($n === 0 || $r === 0xFFFD && $n === 1) {
            return false;
        }
        if (Scan::isUnicodeSpace($r)) {
            return false;
        }
        if ($r < 0x80) {
            return !Scan::isEscapable(chr($r));
        }
        return !Scan::isUnicodePunct($r);
    }

    /** Where a link ends once trailing punctuation, an entity-like ending and unbalanced parentheses are left out. */
    private static function autolinkDelim(string $data, int $end): int
    {
        $open = 0; $closing = 0;
        for ($i = 0; $i < $end; $i++) {
            $c = $data[$i];
            if ($c === '<') {
                $end = $i;
            } elseif ($c === '(') {
                $open++;
            } elseif ($c === ')') {
                $closing++;
            }
        }
        while ($end > 0) {
            $c = $data[$end - 1];
            if ($c === ')') {
                if ($closing <= $open) {
                    return $end;
                }
                $closing--;
                $end--;
            } elseif (str_contains("?!.,:*_~'\"", $c)) {
                $end--;
            } elseif ($c === ';') {
                $k = $end - 2;
                while ($k > 0 && Scan::isLetter($data[$k])) { $k--; }
                if ($k < $end - 2 && $data[$k] === '&') {
                    $end = $k;
                } else {
                    $end--;
                }
            } else {
                return $end;
            }
        }
        return $end;
    }

    /** The length of the domain at the start of $data, 0 if it has an underscore in its last two segments, or, unless $short, no dot. */
    private static function checkDomain(string $data, bool $short): int
    {
        $np = 0; $us1 = 0; $us2 = 0;
        $len = strlen($data);
        for ($i = 1; $i < $len - 1; $i++) {
            if ($data[$i] === '\\' && $i < $len - 2) {
                $i++;
            }
            if ($data[$i] === '_') {
                $us2++;
            } elseif ($data[$i] === '.') {
                $us1 = $us2; $us2 = 0;
                $np++;
            } elseif (!self::isHostChar($data, $i) && $data[$i] !== '-') {
                break;
            }
        }
        if (($us1 > 0 || $us2 > 0) && $np <= 10) {
            return 0;
        }
        return $short || $np > 0 ? $i : 0;
    }

    private static function isSpaceByte(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v";
    }

    /** Reads a link starting with www. at pos. */
    private function wwwLink(): bool
    {
        $s = $this->s;
        $pos = $this->pos;
        if ($this->lastB >= 0 || substr($s, $pos, 4) !== 'www.') {
            return false;
        }
        if ($pos > 0 && !str_contains('*_~(', $s[$pos - 1]) && !self::isSpaceByte($s[$pos - 1])) {
            return false;
        }
        $data = substr($s, $pos);
        $end = self::checkDomain($data, false);
        if ($end === 0) {
            return false;
        }
        $dl = strlen($data);
        while ($end < $dl && !self::isSpaceByte($data[$end]) && $data[$end] !== '<') { $end++; }
        $end = self::autolinkDelim($data, $end);
        if ($end === 0) {
            return false;
        }
        $this->addAutolink($pos, $pos + $end, 'http://' . substr($data, 0, $end));
        $this->pos = $pos + $end;
        return true;
    }

    /** Reads a link of http, https or ftp at the colon at pos, taking back the scheme from the text before it. */
    private function urlLink(): bool
    {
        $s = $this->s;
        $pos = $this->pos;
        if ($this->lastB >= 0 || substr($s, $pos, 3) !== '://') {
            return false;
        }
        // The scheme, from the text just added.
        $last = $this->nodes[0]->last;
        if ($last < 0) {
            return false;
        }
        $ln = $this->nodes[$last];
        if ($ln->kind !== Inline::TEXT || !$ln->plain || $ln->end !== $pos) {
            return false;
        }
        $this->text($last);
        $rewind = 0; $tl = strlen($ln->text);
        while ($rewind < $tl && Scan::isLetter($ln->text[$tl - 1 - $rewind])) { $rewind++; }
        $start = $pos - $rewind;
        $scheme = strtolower(substr($s, $start, $pos - $start));
        if ($scheme !== 'http' && $scheme !== 'https' && $scheme !== 'ftp') {
            return false;
        }
        if (strlen($s) <= $pos + 3 || !self::isHostChar($s, $pos + 3)) {
            return false;
        }
        $data = substr($s, $pos);
        $n = self::checkDomain(substr($data, 3), true);
        if ($n === 0) {
            return false;
        }
        $end = 3 + $n;
        $dl = strlen($data);
        while ($end < $dl && !self::isSpaceByte($data[$end]) && $data[$end] !== '<') { $end++; }
        $end = self::autolinkDelim($data, $end);
        if ($end === 0) {
            return false;
        }
        // Take the scheme off the text.
        $ln->end = $start;
        $ln->text = substr($s, $ln->start, $start - $ln->start);
        $this->addAutolink($start, $pos + $end, substr($s, $start, $pos + $end - $start));
        $this->pos = $pos + $end;
        return true;
    }

    private function addAutolink(int $a, int $b, string $dest): void
    {
        $l = $this->newNode(Inline::LINK, '', $a, $b);
        $this->nodes[$l]->dest = $dest;
        $this->nodes[$l]->auto = true;
        $this->appendChild(0, $l);
        $t = $this->newNode(Inline::TEXT, substr($this->s, $a, $b - $a), $a, $b);
        $this->appendChild($l, $t);
    }

    /** Finds email addresses in the text below node $n, outside links. */
    private function emailLinks(int $n): void
    {
        for ($c = $this->nodes[$n]->first; $c >= 0; $c = $this->nodes[$c]->next) {
            $kind = $this->nodes[$c]->kind;
            if ($kind === Inline::LINK || $kind === Inline::IMAGE) {
                continue;
            }
            if ($kind === Inline::TEXT) {
                if (str_contains($this->text($c), '@')) {
                    $this->splitEmails($c);
                }
            } else {
                $this->emailLinks($c);
            }
        }
    }

    /** Turns the email addresses in text node $c into links. */
    private function splitEmails(int $c): void
    {
        while (true) {
            $nd = $this->nodes[$c];
            $data = $nd->text;
            $found = self::findEmail($data);
            if ($found === null) {
                return;
            }
            [$a, $b, $mailto] = $found;
            $source = substr($this->s, $nd->start, max(0, min($nd->end, strlen($this->s)) - $nd->start));
            $start = $nd->start; $end = $nd->end;
            $la = $nd->start + Scan::literalToSource($source, $data, $a);
            $lb = $nd->start + Scan::literalToSource($source, $data, $b);
            $dest = substr($data, $a, $b - $a);
            if ($mailto) {
                $dest = 'mailto:' . $dest;
            }
            $l = $this->newNode(Inline::LINK, '', $la, $lb);
            $this->nodes[$l]->dest = $dest;
            $this->nodes[$l]->auto = true;
            $t = $this->newNode(Inline::TEXT, substr($data, $a, $b - $a), $la, $lb);
            $this->appendChild($l, $t);
            $post = $this->newNode(Inline::TEXT, substr($data, $b), $lb, $end);
            $nd->text = substr($data, 0, $a); $nd->start = $start; $nd->end = $la;
            $this->insertAfter($c, $l);
            $this->insertAfter($l, $post);
            $c = $post;
        }
    }

    /** The first email address in $data, as cmark-gfm finds it: its bytes, and whether its link needs mailto: (not when written with mailto: or xmpp:); null for none. @return ?array{0: int, 1: int, 2: bool} */
    private static function findEmail(string $data): ?array
    {
        $len = strlen($data);
        $offset = 0;
        while ($offset < $len) {
            $at = strpos($data, '@', $offset);
            if ($at === false) {
                return null;
            }
            $maxRewind = $at - $offset;
            $auto = true; $xmpp = false;
            while (true) { // foundAt
                $base = $offset + $maxRewind; // the @
                $rewind = 0;
                for (; $rewind < $maxRewind; $rewind++) {
                    $ch = $data[$base - $rewind - 1];
                    if (Scan::isLetter($ch) || Scan::isDigit($ch) || str_contains('.+-_', $ch)) {
                        continue;
                    }
                    if ($ch === ':') {
                        if (self::validProtocol('mailto:', $data, $base, $rewind)) {
                            $auto = false;
                            continue;
                        }
                        if (self::validProtocol('xmpp:', $data, $base, $rewind)) {
                            $auto = false; $xmpp = true;
                            continue;
                        }
                    }
                    break;
                }
                if ($rewind === 0) {
                    $offset += $maxRewind + 1;
                    continue 2;
                }
                $np = 0;
                $linkEnd = 1;
                $again = false;
                for (; $linkEnd < $len - $base; $linkEnd++) {
                    $ch = $data[$base + $linkEnd];
                    if (Scan::isLetter($ch) || Scan::isDigit($ch)) {
                    } elseif ($ch === '@') {
                        $offset += $maxRewind + 1;
                        $maxRewind = $linkEnd - 1;
                        $again = true;
                        break;
                    } elseif ($ch === '.' && $linkEnd < $len - $base - 1 && (Scan::isLetter($data[$base + $linkEnd + 1]) || Scan::isDigit($data[$base + $linkEnd + 1]))) {
                        $np++;
                    } elseif ($ch === '/' && $xmpp) {
                    } elseif ($ch !== '-' && $ch !== '_') {
                        break;
                    }
                }
                if ($again) {
                    continue;
                }
                $lastc = $data[$base + $linkEnd - 1];
                if ($linkEnd < 2 || $np === 0 || !Scan::isLetter($lastc) && $lastc !== '.') {
                    $offset += $maxRewind + $linkEnd;
                    continue 2;
                }
                $linkEnd = self::autolinkDelim(substr($data, $base), $linkEnd);
                if ($linkEnd === 0) {
                    $offset += $maxRewind + 1;
                    continue 2;
                }
                return [$base - $rewind, $base + $linkEnd, $auto];
            }
        }
        return null;
    }

    /** Whether $protocol ends $rewind bytes before the @ at $base, after something other than a letter or a digit. */
    private static function validProtocol(string $protocol, string $data, int $base, int $rewind): bool
    {
        $end = $base - $rewind;
        $start = $end - strlen($protocol);
        if ($start < 0 || substr($data, $start, strlen($protocol)) !== $protocol) {
            return false;
        }
        if ($start === 0) {
            return true;
        }
        $c = $data[$start - 1];
        return !Scan::isLetter($c) && !Scan::isDigit($c);
    }

    // Healing (mygo's heal.go) completes what the end of a streaming document leaves open in the inline content
    // of its last block, as Vercel's remend does to the text, but from the parser's own delimiters, and only for
    // that block: the source never changes.

    /** $s completed, the length of what came from the source, past which the text was added, and whether the tree the parser holds is already that of the text returned. @return array{0: string, 1: int, 2: bool} */
    private function heal(string $s): array
    {
        $s = self::trimTrailingMarkers($s);
        $from = strlen($s);
        // A link whose destination is being written: [text](http…
        $s .= self::pendingLink($s);
        // A code span not closed.
        $ticks = self::openCodeSpan($s);
        if ($ticks > 0) {
            $run = 0; $sl = strlen($s);
            while ($run < $sl && $s[$sl - 1 - $run] === '`') { $run++; }
            if ($run >= $ticks || $run === 0) {
                $run = 0;
            }
            $s .= str_repeat('`', $ticks - $run);
        }
        // The closing tildes of strikethrough half written: ~~struck~.
        if (str_ends_with($s, '~') && !str_ends_with($s, '~~') && str_contains($s, '~~')) {
            $s .= '~';
        }
        // Emphasis, strikethrough and math not closed: the openers the parser leaves, innermost first; and the
        // text of a link being written shows without its bracket.
        $this->run($s);
        $open = [];
        // Math not closed, by $$ or a single $ (Extensions::DOLLAR_MATH), runs to the end: what follows is TeX, whose _ and * open no emphasis (mygo's 610df69).
        [$math, $closer] = [strlen($s), ''];
        if (($this->ext & Extensions::MATH) !== 0 && $this->openMath >= 0) {
            [$math, $closer] = [$this->openMath, '$$'];
        }
        if (($this->ext & Extensions::DOLLAR_MATH) !== 0 && $this->openDollar >= 0 && $this->openDollar < $math) {
            [$math, $closer] = [$this->openDollar, '$'];
        }
        if ($closer !== '') {
            $open[] = [$math, $closer];
        }
        foreach ($this->leftover as $d) {
            if ($d->c === '*' && self::wordInside($s, $d->at, $d->orig) || $d->c === '~' && $d->orig === 1 || $d->at > $math) {
                continue; // 2*3, a tilde for "about", as ~200, or TeX
            }
            $open[] = [$d->at, str_repeat($d->c, $d->n)];
        }
        if ($this->lastB >= 0 && $this->nodes[$this->br[$this->lastB]->node]->start < $math) {
            $this->hide = $this->nodes[$this->br[$this->lastB]->node]->start;
        }
        if ($open === [] && $this->hide < 0) {
            return [$s, $from, true];
        }
        usort($open, fn($a, $b) => $b[0] <=> $a[0]); // stable
        foreach ($open as $o) {
            $s .= $o[1];
        }
        return [$s, $from, false];
    }

    /** Whether a run of $n delimiters at $i has letters or digits of ASCII on both sides, as 2*3, which is not emphasis being written. */
    private static function wordInside(string $s, int $i, int $n): bool
    {
        if ($i === 0 || $i + $n >= strlen($s)) {
            return false;
        }
        $isWord = fn(string $c) => Scan::isLetter($c) || Scan::isDigit($c);
        return $isWord($s[$i - 1]) && $isWord($s[$i + $n]);
    }

    /** $s without what its end may be starting, with nothing after it yet: a run of delimiters opening emphasis, a code span or math, a bracket, or a backslash. */
    private static function trimTrailingMarkers(string $s): string
    {
        while (true) {
            $t = rtrim($s, " \t");
            if ($t === '') {
                return $t;
            }
            $tl = strlen($t);
            $c = $t[$tl - 1];
            $k = $tl;
            while ($k > 0 && $t[$k - 1] === $c) { $k--; }
            $run = $tl - $k;
            $opening = $k === 0 || $t[$k - 1] === ' ' || $t[$k - 1] === "\t" || $t[$k - 1] === "\n";
            if (str_ends_with($t, '![')) {
                $t = substr($t, 0, -2);
            } elseif ($c === '[') {
                $t = substr($t, 0, $k);
            } elseif (($c === '*' || $c === '_' || $c === '~' || $c === '$') && $opening) {
                $t = substr($t, 0, $k);
            } elseif ($c === '`' && self::openCodeSpan(substr($t, 0, $k)) !== $run) {
                $t = substr($t, 0, $k);
            } elseif ($c === '\\' && $run % 2 === 1) {
                $t = substr($t, 0, -1);
            } else {
                return $t;
            }
            $s = $t;
        }
    }

    /** What closes the destination of a link being written at the end of $s, after ](, or nothing: the parentheses it opened, and the link's, or > and ) for one in angle brackets. */
    private static function pendingLink(string $s): string
    {
        $k = strrpos($s, '](');
        if ($k === false || !str_contains(substr($s, 0, $k), '[')) {
            return '';
        }
        // Not inside a code span.
        if (substr_count(substr($s, 0, $k), '`') % 2 === 1) {
            return '';
        }
        $rest = substr($s, $k + 2);
        if (str_starts_with($rest, '<')) {
            return strpbrk($rest, ">\n") !== false ? '' : '>)';
        }
        $depth = 0;
        for ($i = 0, $n = strlen($rest); $i < $n; $i++) {
            $c = $rest[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    return ''; // the link is closed
                }
                $depth--;
            } elseif ($c === ' ' || $c === "\t" || $c === "\n") {
                return '';
            }
        }
        return str_repeat(')', $depth + 1);
    }

    /** The length of the run of backticks opening a code span that $s leaves open, or 0. */
    private static function openCodeSpan(string $s): int
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '`') {
                $j = $i;
                while ($j < $len && $s[$j] === '`') { $j++; }
                $n = $j - $i;
                // Its closer: the next run of as many.
                $k = $j;
                $found = false;
                while ($k < $len) {
                    $m = strpos($s, '`', $k);
                    if ($m === false) {
                        break;
                    }
                    $k = $m;
                    $e = $k;
                    while ($e < $len && $s[$e] === '`') { $e++; }
                    if ($e - $k === $n) {
                        $found = true;
                        $i = $e - 1;
                        break;
                    }
                    $k = $e;
                }
                if (!$found) {
                    return $j === $len ? 0 : $n; // a run at the very end opens nothing yet
                }
            }
        }
        return 0;
    }
}
