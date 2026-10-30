<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * The block parser (mygo's blockParser), following cmark's (blocks.c),
 * line by line with a stack of open blocks, over flat blocks with byte
 * offsets: a block is open while lines may still go into it, and only
 * the last child of an open block can be open. Blocks go in document
 * order as they are added, as only open blocks take children.
 *
 * @internal
 */
final class BlockParser
{
    private const TAB_STOP = 4, CODE_INDENT = 4;
    /** How deep lists and quotes nest, as cmark bounds them, so that a hostile input cannot make the parser recurse without end. */
    private const MAX_DEPTH = 100;

    private int $ext;
    /** The line: its text without its line ending, from $lineStart. */
    private string $ln = '';
    private int $lineStart = 0, $lineNum = 0;
    private int $offset = 0, $column = 0, $firstNonspace = 0, $firstNonspaceColumn = 0, $indent = 0;
    private bool $blank = false, $partiallyConsumedTab = false;
    private int $thematicBreakKillPos = 0;
    private int $current = 0, $lastMatchedContainer = 0;
    private bool $lineQuiet = false;

    public function __construct(private Document $d, private string $src, private ?\Closure $resync)
    {
        $this->ext = $d->ext;
    }

    /** The byte of the line at $i, "\n" past its end. */
    private function peek(int $i): string
    {
        return $this->ln[$i] ?? "\n";
    }

    private function b(int $i): Block { return $this->d->blocks[$i]; }

    /** Parses from $off, a line's start, to the end of the source given (mygo's Document.parse); whether it met the blocks parsed before ($resync). */
    public function run(int $off): bool
    {
        $d = $this->d;
        $d->tail = -1;
        $d->restart = $off; $d->restartAt = count($d->blocks);
        $src = $this->src; $n = strlen($src); $full = strlen($d->src);
        $pos = $off;
        // The next \n and \r, found once each, so that lines ending in one of them alone take linear time.
        $nl = -1; $cr = -1;
        while ($pos < $n) {
            // The line ends at the first \n, \r or \r\n.
            if ($nl < $pos) {
                $nl = strpos($src, "\n", $pos);
                if ($nl === false) { $nl = $n; }
            }
            if ($cr < $pos) {
                $cr = strpos($src, "\r", $pos);
                if ($cr === false) { $cr = $n; }
            }
            $lineEnd = $nl; $next = $nl + 1;
            if ($cr < $nl) {
                $lineEnd = $cr;
                $next = $lineEnd + 1;
                if ($next < $n && $src[$next] === "\n") {
                    $next++;
                }
            }
            if ($next > $n) {
                $next = $n;
            }
            // A line ending in \r at the very end may yet be \r\n.
            $complete = $next > $lineEnd && !($next === $full && $d->src[$next - 1] === "\r");
            if ($this->resync !== null && $this->quiet() && ($this->resync)($pos)) {
                while ($this->current > 0) {
                    $this->current = $this->finalize($this->current);
                }
                return true;
            }
            $this->processLine($pos, $lineEnd);
            $pos = $next;
            if ($complete) {
                $this->noteRestart($next);
            }
        }
        // What is still open at the end is open: finalize it for showing.
        $last = $this->current;
        while ($this->current > 0) {
            $b = $this->b($this->current);
            $b->open = !($b->kind === Block::MATH_BLOCK && $b->parse->mathDone);
            $this->current = $this->finalize($this->current);
        }
        if ($last > 0 && $last < count($d->blocks)) {
            switch ($d->blocks[$last]->kind) {
                case Block::PARAGRAPH:
                case Block::HEADING:
                    $d->tail = $last;
                    break;
                case Block::TABLE:
                case Block::TABLE_ROW:
                    $k = count($d->blocks) - 1;
                    if ($d->blocks[$k]->kind === Block::TABLE_CELL) {
                        $d->tail = $k;
                    }
                    break;
            }
        }
        $d->blocks[0]->next = count($d->blocks);
        return false;
    }

    /** Notes, after a complete line ending at $next, where parsing restarts as text is appended. */
    private function noteRestart(int $next): void
    {
        $d = $this->d;
        $top = $d->blocks[0]->parse->lastChild;
        if ($top > 0 && $d->blocks[$top]->parse->open) {
            [$d->restartAt, $d->restart] = $d->restartOf($top);
        } else {
            $d->restart = $next;
            $d->restartAt = count($d->blocks);
        }
    }

    /** Whether no block is open that a line could go into: none, or a heading, which no line continues. */
    private function quiet(): bool
    {
        $top = $this->d->blocks[0]->parse->lastChild;
        if ($top <= 0 || !$this->d->blocks[$top]->parse->open) {
            return true;
        }
        $b = $this->d->blocks[$top];
        return $b->kind === Block::HEADING && $b->parse->lastChild < 0;
    }

    private function processLine(int $start, int $end): void
    {
        $this->ln = substr($this->src, $start, $end - $start);
        $this->lineStart = $start;
        $this->offset = 0; $this->column = 0;
        $this->firstNonspace = 0; $this->firstNonspaceColumn = 0;
        $this->thematicBreakKillPos = 0;
        $this->indent = 0;
        $this->blank = false;
        $this->partiallyConsumedTab = false;
        if ($start === 0 && str_starts_with($this->ln, "\xef\xbb\xbf")) {
            $this->offset = 3;
        }
        $this->lineNum++;
        $this->lineQuiet = $this->quiet();
        $allMatched = false;
        $container = $this->checkOpenBlocks($allMatched);
        if ($container !== null) {
            $this->lastMatchedContainer = $container;
            $this->openNewBlocks($container, $allMatched);
            $this->addTextToContainer($container);
        }
    }

    /** Adds a block of $kind as the last child of $parent, closing the blocks that cannot hold it. */
    private function addChild(int $parent, int $kind, int $start): int
    {
        // The blocks the line did not match close first, as a block starting leaves no lazy continuation: blocks stay in document order.
        while ($this->current !== $parent && $this->b($this->current)->parse->open && $this->isBelow($this->current, $parent)) {
            $this->current = $this->finalize($this->current);
        }
        while (!self::canContain($this->b($parent)->kind, $kind)) {
            $parent = $this->finalize($parent);
        }
        $d = $this->d;
        $i = count($d->blocks);
        $n = count($d->segs);
        $b = new Block($kind, $parent, $i + 1, $start, $start);
        $b->segA = $n; $b->segB = $n; $b->segMark = $n;
        $d->blocks[] = $b;
        $b->parse->open = true;
        $b->parse->lastChild = -1;
        $b->parse->startLine = $this->lineNum;
        $b->parse->firstLine = $this->lineStart;
        $pb = $d->blocks[$parent];
        $b->parse->prev = $pb->parse->lastChild;
        $pb->parse->lastChild = $i;
        if ($parent === 0) {
            $b->parse->quiet = $this->lineQuiet && Document::lineStartOf($d->src, $start) === $this->lineStart;
        }
        return $i;
    }

    /** Whether block $c is a descendant of block $parent. */
    private function isBelow(int $c, int $parent): bool
    {
        while ($c >= 0) {
            $c = $this->d->blocks[$c]->parent;
            if ($c === $parent) {
                return true;
            }
        }
        return false;
    }

    private static function canContain(int $parent, int $child): bool
    {
        switch ($parent) {
            case Block::ROOT:
            case Block::QUOTE:
            case Block::ITEM:
            case Block::FOOTNOTE_DEFINITION:
                return $child !== Block::ITEM && $child !== Block::TABLE_ROW && $child !== Block::TABLE_CELL;
            case Block::LIST: return $child === Block::ITEM;
            case Block::TABLE: return $child === Block::TABLE_ROW;
            case Block::TABLE_ROW: return $child === Block::TABLE_CELL;
        }
        return false;
    }

    private static function acceptsLines(int $k): bool
    {
        return $k === Block::PARAGRAPH || $k === Block::HEADING || $k === Block::CODE_BLOCK || $k === Block::MATH_BLOCK;
    }

    /** Removes block $i, the last, an empty paragraph. */
    private function removeLast(int $i): void
    {
        $d = $this->d;
        $b = $d->blocks[$i];
        $d->blocks[$b->parent]->parse->lastChild = $b->parse->prev;
        array_splice($d->blocks, $i);
    }

    /**
     * Closes block $i and returns its parent. The line it ends on is the
     * one before the line being parsed, unless it is closed by that line,
     * as a fence by its closing fence, or by the end.
     */
    private function finalize(int $i): int
    {
        $d = $this->d;
        $b = $d->blocks[$i];
        $parent = $b->parent;
        $b->parse->open = false;
        $b->next = count($d->blocks);
        if ($b->segB > $b->segA) {
            $b->end = $d->segs[$b->segB - 1]['end'];
        } elseif ($b->parse->lastChild >= 0) {
            $b->end = max($b->end, $d->blocks[$b->parse->lastChild]->end);
        }
        switch ($b->kind) {
            case Block::PARAGRAPH:
                if (!$this->resolveDefinitions($i)) {
                    $this->removeLast($i);
                    return $parent;
                }
                break;
            case Block::CODE_BLOCK:
                if (!$b->fenced) {
                    // Blank lines at the end are not the code's.
                    while ($b->segB > $b->segA && trim(substr($d->src, $d->segs[$b->segB - 1]['start'], $d->segs[$b->segB - 1]['end'] - $d->segs[$b->segB - 1]['start']), " \t") === '') {
                        $b->segB--;
                    }
                    if ($b->segB > $b->segA) {
                        $b->end = $d->segs[$b->segB - 1]['end'];
                    }
                }
                break;
            case Block::LIST:
                $b->tight = true;
                for ($item = $this->firstChild($i); $item >= 0 && $b->tight; $item = $this->nextSibling($item)) {
                    if ($d->blocks[$item]->parse->lastLineBlank && $this->nextSibling($item) >= 0) {
                        $b->tight = false;
                        break;
                    }
                    for ($sub = $this->firstChild($item); $sub >= 0; $sub = $this->nextSibling($sub)) {
                        if (($this->nextSibling($item) >= 0 || $this->nextSibling($sub) >= 0) && $this->endsWithBlankLine($sub)) {
                            $b->tight = false;
                            break;
                        }
                    }
                }
                break;
            case Block::ITEM:
                if ($b->parse->lastChild < 0) {
                    $b->end = $b->start + 1;
                }
                break;
        }
        if ($parent >= 0) {
            $pb = $d->blocks[$parent];
            $pb->end = max($pb->end, $b->end);
        }
        return $parent;
    }

    private function firstChild(int $i): int
    {
        return $this->d->blocks[$i]->parse->lastChild < 0 ? -1 : $i + 1;
    }

    private function nextSibling(int $i): int
    {
        $b = $this->d->blocks[$i];
        $n = $b->next;
        return $n < count($this->d->blocks) && $this->d->blocks[$n]->parent === $b->parent ? $n : -1;
    }

    /** Whether a block ends with a blank line, looking into the last items of lists. */
    private function endsWithBlankLine(int $i): bool
    {
        while (true) {
            $b = $this->d->blocks[$i];
            if ($b->parse->lineChecked) {
                return $b->parse->lastLineBlank;
            }
            $b->parse->lineChecked = true;
            if (($b->kind === Block::LIST || $b->kind === Block::ITEM) && $b->parse->lastChild >= 0) {
                $i = $b->parse->lastChild;
                continue;
            }
            return $b->parse->lastLineBlank;
        }
    }

    /** Takes the link reference definitions off the start of paragraph $i, and reports whether text remains. */
    private function resolveDefinitions(int $i): bool
    {
        $d = $this->d;
        $b = $d->blocks[$i];
        if ($b->segB === $b->segA) {
            return false;
        }
        $first = $d->segs[$b->segA];
        if ($d->src[$first['start']] !== '[' || $first['pad'] !== 0) {
            return true;
        }
        [$text] = $d->contentOf($i);
        $pos = 0; $tn = strlen($text);
        $m = $d->sourceMap($i);
        while ($pos < $tn && $text[$pos] === '[') {
            $n = $d->inp->parseReference($d, substr($text, $pos), $m->source($pos));
            if ($n === 0) {
                break;
            }
            $pos += $n;
        }
        if ($pos === 0) {
            return true;
        }
        // Drop the lines the definitions took.
        $consumed = $pos;
        while ($b->segA < $b->segB) {
            $s = $d->segs[$b->segA];
            $l = $s['pad'] + $s['end'] - $s['start'] + 1;
            if ($consumed < $l) {
                break;
            }
            $consumed -= $l;
            $b->segA++;
        }
        if ($b->segA < $b->segB && $consumed > 0) {
            $take = $consumed - $d->segs[$b->segA]['pad'];
            $d->segs[$b->segA]['pad'] = 0;
            $d->segs[$b->segA]['start'] += max($take, 0);
        }
        $b->text = ''; $b->textS = -1;
        if (!$this->hasText($d->segsOf($b))) {
            $b->segA = $b->segB;
            return false;
        }
        $b->start = $d->segs[$b->segA]['start'];
        return true;
    }

    private function hasText(array $segs): bool
    {
        foreach ($segs as $s) {
            if (trim(substr($this->d->src, $s['start'], $s['end'] - $s['start']), " \t") !== '') {
                return true;
            }
        }
        return false;
    }

    /** Adds the rest of the line to the content of block $i. */
    private function addLine(int $i): void
    {
        $s = ['start' => $this->lineStart + $this->offset, 'end' => $this->lineStart + strlen($this->ln), 'pad' => 0, 'cell' => false];
        if ($this->partiallyConsumedTab) {
            $this->offset++;
            $s['start']++;
            $s['pad'] = self::TAB_STOP - $this->column % self::TAB_STOP;
        }
        if ($s['start'] > $s['end']) {
            $s['start'] = $s['end'];
        }
        $this->d->appendSeg($i, $s);
        $this->b($i)->end = $s['end'];
    }

    private function findFirstNonspace(): void
    {
        $charsToTab = self::TAB_STOP - $this->column % self::TAB_STOP;
        if ($this->firstNonspace <= $this->offset) {
            $this->firstNonspace = $this->offset;
            $this->firstNonspaceColumn = $this->column;
            while (true) {
                $c = $this->peek($this->firstNonspace);
                if ($c === ' ') {
                    $this->firstNonspace++;
                    $this->firstNonspaceColumn++;
                    $charsToTab--;
                    if ($charsToTab === 0) {
                        $charsToTab = self::TAB_STOP;
                    }
                } elseif ($c === "\t") {
                    $this->firstNonspace++;
                    $this->firstNonspaceColumn += $charsToTab;
                    $charsToTab = self::TAB_STOP;
                } else {
                    break;
                }
            }
        }
        $this->indent = $this->firstNonspaceColumn - $this->column;
        $this->blank = Scan::isLineEnd($this->peek($this->firstNonspace));
    }

    /** Advances by $count columns (with $columns) or bytes. A tab partly consumed sets partiallyConsumedTab. */
    private function advanceOffset(int $count, bool $columns): void
    {
        $n = strlen($this->ln);
        while ($count > 0 && $this->offset < $n) {
            $c = $this->ln[$this->offset];
            if ($c === "\t") {
                $charsToTab = self::TAB_STOP - $this->column % self::TAB_STOP;
                if ($columns) {
                    $this->partiallyConsumedTab = $charsToTab > $count;
                    $adv = min($count, $charsToTab);
                    $this->column += $adv;
                    if (!$this->partiallyConsumedTab) {
                        $this->offset++;
                    }
                    $count -= $adv;
                } else {
                    $this->partiallyConsumedTab = false;
                    $this->column += $charsToTab;
                    $this->offset++;
                    $count--;
                }
            } else {
                $this->partiallyConsumedTab = false;
                $this->offset++;
                $this->column++;
                $count--;
            }
        }
    }

    /** Matches the line against the open blocks' prefixes, returning the last that matched, or null when the line is done (a closing fence). */
    private function checkOpenBlocks(bool &$allMatched): ?int
    {
        $d = $this->d;
        $container = 0;
        while (true) {
            $lc = $d->blocks[$container]->parse->lastChild;
            if ($lc < 0 || !$d->blocks[$lc]->parse->open) {
                break;
            }
            $container = $lc;
            $b = $d->blocks[$container];
            $this->findFirstNonspace();
            $matched = true;
            switch ($b->kind) {
                case Block::QUOTE:
                    $matched = $this->blockQuotePrefix();
                    break;
                case Block::ITEM:
                    $w = $b->parse->markerOffset + $b->parse->padding;
                    if ($this->indent >= $w) {
                        $this->advanceOffset($w, true);
                    } elseif ($this->blank && $b->parse->lastChild >= 0) {
                        $this->advanceOffset($this->firstNonspace - $this->offset, false);
                    } else {
                        $matched = false;
                    }
                    break;
                case Block::FOOTNOTE_DEFINITION:
                    if ($this->indent >= 4) {
                        $this->advanceOffset(4, true);
                    } elseif (!$this->blank) {
                        $matched = false;
                    }
                    break;
                case Block::CODE_BLOCK:
                    if (!$b->fenced) {
                        if ($this->indent >= self::CODE_INDENT) {
                            $this->advanceOffset(self::CODE_INDENT, true);
                        } elseif ($this->blank) {
                            $this->advanceOffset($this->firstNonspace - $this->offset, false);
                        } else {
                            $matched = false;
                        }
                    } else {
                        $n = 0;
                        if ($this->indent <= 3 && $this->peek($this->firstNonspace) === $b->parse->fenceChar) {
                            $n = Scan::closeFence($this->ln, $this->firstNonspace);
                        }
                        if ($n >= $b->parse->fenceLength) {
                            // The closing fence ends the block and the line.
                            $this->advanceOffset($n, false);
                            $b->end = $this->lineStart + strlen($this->ln);
                            $this->current = $this->finalize($container);
                            $d->blocks[$container]->end = $this->lineStart + strlen($this->ln);
                            return null;
                        }
                        for ($k = $b->parse->fenceOffset; $k > 0 && Scan::isSpaceOrTab($this->peek($this->offset)); $k--) {
                            $this->advanceOffset(1, true);
                        }
                    }
                    break;
                case Block::MATH_BLOCK:
                    // Lines go into it until its closing line; one that ended is closed by the next line, as a thematic break.
                    break;
                case Block::HEADING:
                    $matched = false;
                    break;
                case Block::HTML_BLOCK:
                    if ($b->parse->htmlType >= 6) {
                        $matched = !$this->blank;
                    }
                    break;
                case Block::PARAGRAPH:
                    $matched = !$this->blank;
                    break;
                case Block::TABLE:
                    $matched = !$this->blank && Scan::splitRow(substr($this->ln, $this->firstNonspace)) !== [];
                    break;
            }
            if (!$matched) {
                return $b->parent;
            }
        }
        $allMatched = true;
        return $container;
    }

    private function blockQuotePrefix(): bool
    {
        if ($this->indent <= 3 && $this->peek($this->firstNonspace) === '>') {
            $this->advanceOffset($this->indent + 1, true);
            if (Scan::isSpaceOrTab($this->peek($this->offset))) {
                $this->advanceOffset(1, true);
            }
            return true;
        }
        return false;
    }

    /** Opens the blocks the line starts inside $container. */
    private function openNewBlocks(int &$container, bool $allMatched): void
    {
        $d = $this->d;
        $maybeLazy = $d->blocks[$this->current]->kind === Block::PARAGRAPH;
        $depth = 0;
        while ($this->takesBlocks($container)) {
            $depth++;
            $this->findFirstNonspace();
            $indented = $this->indent >= self::CODE_INDENT;
            $c = $this->peek($this->firstNonspace);
            $cont = $container;
            $contKind = $d->blocks[$cont]->kind;
            $n = 0;
            if (!$indented && $c === '>' && $depth < self::MAX_DEPTH) {
                $start = $this->lineStart + $this->firstNonspace;
                $this->advanceOffset($this->firstNonspace + 1 - $this->offset, false);
                if (Scan::isSpaceOrTab($this->peek($this->offset))) {
                    $this->advanceOffset(1, true);
                }
                $container = $this->addChild($cont, Block::QUOTE, $start);
            } elseif (!$indented && $c === '#' && ($n = Scan::atxStart($this->ln, $this->firstNonspace)) > 0) {
                $start = $this->lineStart + $this->firstNonspace;
                $level = 0;
                while ($this->peek($this->firstNonspace + $level) === '#') { $level++; }
                $this->advanceOffset($this->firstNonspace + $n - $this->offset, false);
                $container = $this->addChild($cont, Block::HEADING, $start);
                $d->blocks[$container]->level = $level;
            } elseif (!$indented && ($c === '`' || $c === '~') && ($n = Scan::openFence($this->ln, $this->firstNonspace)) > 0) {
                $start = $this->lineStart + $this->firstNonspace;
                $container = $this->addChild($cont, Block::CODE_BLOCK, $start);
                $b = $d->blocks[$container];
                $b->fenced = true;
                $b->parse->fenceChar = $c;
                $b->parse->fenceLength = $n;
                $b->parse->fenceOffset = $this->firstNonspace - $this->offset;
                $b->info = Scan::unescape(trim(substr($this->ln, $this->firstNonspace + $n), " \t"));
                $b->end = $this->lineStart + strlen($this->ln);
                $this->advanceOffset(strlen($this->ln) - $this->offset, false);
            } elseif (!$indented && $c === '<' && ($n = $this->htmlStart($contKind, $allMatched)) > 0) {
                $container = $this->addChild($cont, Block::HTML_BLOCK, $this->lineStart + $this->firstNonspace);
                $d->blocks[$container]->parse->htmlType = $n;
            } elseif (!$indented && $contKind === Block::PARAGRAPH && ($c === '=' || $c === '-') && ($n = Scan::setextLine($this->ln, $this->firstNonspace)) > 0) {
                if ($this->resolveDefinitionsOpen($cont)) {
                    $b = $d->blocks[$cont];
                    $b->kind = Block::HEADING;
                    $b->level = $n;
                    $b->end = $this->lineStart + strlen($this->ln);
                    $this->advanceOffset(strlen($this->ln) - $this->offset, false);
                }
            } elseif (!$indented && !($contKind === Block::PARAGRAPH && !$allMatched) && $this->thematicBreakKillPos <= $this->firstNonspace && ($n = $this->scanThematicBreak($this->firstNonspace)) > 0) {
                $container = $this->addChild($cont, Block::THEMATIC_BREAK, $this->lineStart + $this->firstNonspace);
                $d->blocks[$container]->end = $this->lineStart + strlen($this->ln);
                $this->advanceOffset(strlen($this->ln) - $this->offset, false);
            } elseif (!$indented && ($this->ext & Extensions::MATH) !== 0 && ($c === '$' || $c === '\\') && $this->mathStart($container)) {
            } elseif (!$indented && ($this->ext & Extensions::FOOTNOTES) !== 0 && $c === '[' && $depth < self::MAX_DEPTH && ($n = Scan::footnoteDef($this->ln, $this->firstNonspace)) > 0) {
                $label = substr($this->ln, $this->firstNonspace + 2);
                $label = substr($label, 0, strpos($label, ']'));
                $start = $this->lineStart + $this->firstNonspace;
                $this->advanceOffset($this->firstNonspace + $n - $this->offset, false);
                $container = $this->addChild($cont, Block::FOOTNOTE_DEFINITION, $start);
                $d->blocks[$container]->info = $label;
                $key = Document::normalizeLabel($label);
                if (($d->notes[$key] ?? 0) === 0) {
                    $d->notes[$key] = $container;
                }
            } elseif ((!$indented || $contKind === Block::LIST) && $this->indent < 4 && $depth < self::MAX_DEPTH && $this->listItemStart($container)) {
            } elseif ($indented && !$maybeLazy && !$this->blank) {
                $this->advanceOffset(self::CODE_INDENT, true);
                $container = $this->addChild($cont, Block::CODE_BLOCK, $this->lineStart + $this->offset);
            } elseif (!$indented && ($this->ext & Extensions::TABLES) !== 0 && $contKind === Block::PARAGRAPH && $this->tableStart($container)) {
            } elseif (!$indented && ($this->ext & Extensions::TABLES) !== 0 && $contKind === Block::TABLE && !$this->blank) {
                $this->tableRow($cont);
                $container = $cont;
            } else {
                return;
            }
            $b = $d->blocks[$container];
            if (self::acceptsLines($b->kind) && !$b->parse->mathDone || $b->kind === Block::TABLE) {
                return;
            }
            $maybeLazy = false;
        }
    }

    private function htmlStart(int $contKind, bool $allMatched): int
    {
        $n = Scan::htmlBlockStart($this->ln, $this->firstNonspace);
        if ($n === 0 && $contKind !== Block::PARAGRAPH && !(!$allMatched && $this->d->blocks[$this->current]->kind === Block::PARAGRAPH)) {
            $n = Scan::htmlBlockStart7($this->ln, $this->firstNonspace);
        }
        return $n;
    }

    /** Whether new blocks may start in block $i: not in code, HTML or math still taking lines. */
    private function takesBlocks(int $i): bool
    {
        $b = $this->d->blocks[$i];
        return match ($b->kind) {
            Block::CODE_BLOCK, Block::HTML_BLOCK => false,
            Block::MATH_BLOCK => $b->parse->mathDone,
            default => true,
        };
    }

    /** Takes the definitions off the start of the open paragraph $i before it becomes a setext heading, and reports whether text remains. */
    private function resolveDefinitionsOpen(int $i): bool
    {
        $b = $this->d->blocks[$i];
        if ($b->segB === $b->segA) {
            return false;
        }
        if ($this->d->src[$this->d->segs[$b->segA]['start']] !== '[') {
            return true;
        }
        return $this->resolveDefinitions($i);
    }

    /** Opens a list item, and its list unless the container is a list it continues. */
    private function listItemStart(int &$container): bool
    {
        $d = $this->d;
        $cont = $container;
        $interrupts = $d->blocks[$cont]->kind === Block::PARAGRAPH;
        $pos = $this->firstNonspace;
        $c = $this->peek($pos);
        $ordered = false;
        $number = 0;
        if ($c === '*' || $c === '-' || $c === '+') {
            $pos++;
            if (!Scan::isSpaceOrTab($this->peek($pos)) && !Scan::isLineEnd($this->peek($pos))) {
                return false;
            }
            if ($interrupts) {
                $i = $pos;
                while (Scan::isSpaceOrTab($this->peek($i))) { $i++; }
                if (Scan::isLineEnd($this->peek($i))) {
                    return false;
                }
            }
            $marker = $c;
        } elseif (Scan::isDigit($c)) {
            $digits = 0;
            while ($digits < 9 && Scan::isDigit($this->peek($pos))) {
                $number = $number * 10 + (ord($this->peek($pos)) - 0x30);
                $pos++;
                $digits++;
            }
            if ($interrupts && $number !== 1) {
                return false;
            }
            $c = $this->peek($pos);
            if ($c !== '.' && $c !== ')') {
                return false;
            }
            $pos++;
            if (!Scan::isSpaceOrTab($this->peek($pos)) && !Scan::isLineEnd($this->peek($pos))) {
                return false;
            }
            if ($interrupts) {
                $i = $pos;
                while (Scan::isSpaceOrTab($this->peek($i))) { $i++; }
                if (Scan::isLineEnd($this->peek($i))) {
                    return false;
                }
            }
            $ordered = true; $marker = $c;
        } else {
            return false;
        }
        $matched = $pos - $this->firstNonspace;
        $start = $this->lineStart + $this->firstNonspace;
        $markerOffset = $this->indent;
        $this->advanceOffset($this->firstNonspace + $matched - $this->offset, false);
        $savedTab = $this->partiallyConsumedTab; $savedOffset = $this->offset; $savedColumn = $this->column;
        while ($this->column - $savedColumn <= 5 && Scan::isSpaceOrTab($this->peek($this->offset))) {
            $this->advanceOffset(1, true);
        }
        $i = $this->column - $savedColumn;
        $padding = $matched + $i;
        if ($i >= 5 || $i < 1 || Scan::isLineEnd($this->peek($this->offset))) {
            $padding = $matched + 1;
            $this->offset = $savedOffset; $this->column = $savedColumn; $this->partiallyConsumedTab = $savedTab;
            if ($i > 0) {
                $this->advanceOffset(1, true);
            }
        }
        $cb = $d->blocks[$cont];
        if ($cb->kind !== Block::LIST || $cb->ordered !== $ordered || $cb->marker !== $marker) {
            $cont = $this->addChild($cont, Block::LIST, $start);
            $l = $d->blocks[$cont];
            $l->ordered = $ordered; $l->marker = $marker; $l->number = $number;
            $l->parse->markerOffset = $markerOffset; $l->parse->padding = $padding;
        }
        $item = $this->addChild($cont, Block::ITEM, $start);
        $it = $d->blocks[$item];
        $it->ordered = $ordered; $it->marker = $marker; $it->number = $number;
        $it->parse->markerOffset = $markerOffset; $it->parse->padding = $padding;
        $it->end = $this->lineStart + $this->offset;
        if (($this->ext & Extensions::TASK_LISTS) !== 0) {
            $this->taskMarker($item);
        }
        $container = $item;
        return true;
    }

    /** Reads [ ] or [x] at the start of an item's first line, followed by a space or a tab, as its task state. */
    private function taskMarker(int $item): void
    {
        $i = $this->offset;
        if ($this->partiallyConsumedTab) {
            return;
        }
        if ($this->peek($i) !== '[' || $this->peek($i + 2) !== ']') {
            return;
        }
        $c = $this->peek($i + 1);
        if ($c !== ' ' && $c !== 'x' && $c !== 'X' || !Scan::isSpaceOrTab($this->peek($i + 3))) {
            return;
        }
        // Text must follow, as GitHub asks.
        $j = $i + 3;
        while (Scan::isSpaceOrTab($this->peek($j))) { $j++; }
        if (Scan::isLineEnd($this->peek($j))) {
            return;
        }
        $this->b($item)->task = $c === ' ' ? Block::UNCHECKED : Block::CHECKED;
        $this->advanceOffset(4, true);
    }

    /**
     * Opens a block of display math at a line starting with $$ or \[,
     * unless its closing delimiter comes before the end of the line with
     * text after it, when it is math within a paragraph.
     */
    private function mathStart(int &$container): bool
    {
        $rest = substr($this->ln, $this->firstNonspace);
        if (str_starts_with($rest, '$$')) {
            $open = '$$'; $close = '$$';
        } elseif (str_starts_with($rest, '\[')) {
            $open = '\['; $close = '\]';
        } else {
            return false;
        }
        $body = substr($rest, strlen($open));
        $done = false;
        $j = strpos($body, $close);
        if ($j !== false) {
            if (trim(substr($body, $j + strlen($close)), " \t") !== '') {
                return false;
            }
            $done = true;
            $body = substr($body, 0, $j);
        }
        $start = $this->lineStart + $this->firstNonspace;
        $m = $this->addChild($container, Block::MATH_BLOCK, $start);
        $b = $this->b($m);
        $b->parse->mathBracket = $close === '\]';
        $b->parse->mathDone = $done;
        $bs = $start + strlen($open);
        while ($body !== '' && ($body[0] === ' ' || $body[0] === "\t")) {
            $body = substr($body, 1); $bs++;
        }
        $body = rtrim($body, " \t");
        if ($body !== '') {
            $this->d->appendSeg($m, ['start' => $bs, 'end' => $bs + strlen($body), 'pad' => 0, 'cell' => false]);
        }
        $b->end = $this->lineStart + strlen($this->ln);
        $this->advanceOffset(strlen($this->ln) - $this->offset, false);
        $container = $m;
        return true;
    }

    /** Turns the last line of the paragraph $container into a table's header when the line is a delimiter row of as many cells. */
    private function tableStart(int &$container): bool
    {
        $d = $this->d;
        $cont = $container;
        $b = $d->blocks[$cont];
        if ($b->parse->tableTried || $b->segB === $b->segA) {
            return false;
        }
        $aligns = Scan::delimiterRow(substr($this->ln, $this->firstNonspace));
        if ($aligns === null) {
            return false;
        }
        $h = $d->segs[$b->segB - 1];
        $header = substr($d->src, $h['start'], $h['end'] - $h['start']);
        if ($h['pad'] !== 0) {
            return false;
        }
        $cells = Scan::splitRow($header);
        if (count($cells) !== count($aligns)) {
            $b->parse->tableTried = true;
            return false;
        }
        $parent = $b->parent;
        if ($b->segB - $b->segA > 1) {
            $b->segB--;
            $first = $b->parse->firstLine;
            $this->current = $this->finalize($cont);
            $kept = count($d->blocks) > $cont;
            $table = $this->addChild($parent, Block::TABLE, $h['start']);
            // Its header was the paragraph's last line: parsing anew starts with the paragraph.
            $t = $d->blocks[$table];
            $t->parse->firstLine = $first; $t->parse->quiet = false;
            if ($kept) {
                $t->parse->joined = $cont;
            }
        } else {
            $table = $cont;
            $b->kind = Block::TABLE;
            $b->segA = $b->segB;
            $b->text = ''; $b->textS = -1;
        }
        $t = $d->blocks[$table];
        $t->align = $aligns;
        $t->parse->columns = count($aligns);
        $row = $this->addChild($table, Block::TABLE_ROW, $h['start']);
        $d->blocks[$row]->header = true;
        $this->addCells($row, $h['start'], $header, $cells, count($aligns));
        $this->finalize($row);
        $d->blocks[$table]->end = $this->lineStart + strlen($this->ln);
        $this->advanceOffset(strlen($this->ln) - $this->offset, false);
        $this->current = $table;
        $this->lastMatchedContainer = $table;
        $container = $table;
        return true;
    }

    /** Adds the line as a row of table $t. */
    private function tableRow(int $t): void
    {
        $start = $this->lineStart + $this->firstNonspace;
        $line = substr($this->ln, $this->firstNonspace);
        $cells = Scan::splitRow($line);
        $row = $this->addChild($t, Block::TABLE_ROW, $start);
        $this->addCells($row, $start, $line, $cells, $this->d->blocks[$t]->parse->columns);
        $this->finalize($row);
        $this->advanceOffset(strlen($this->ln) - $this->offset, false);
    }

    /** Adds the cells of a row, as many as the table has columns, those missing empty. */
    private function addCells(int $row, int $base, string $line, array $cells, int $columns): void
    {
        for ($k = 0; $k < $columns; $k++) {
            $at = $base + strlen($line);
            if ($k < count($cells)) {
                $at = $base + $cells[$k][0];
            }
            $c = $this->addChild($row, Block::TABLE_CELL, $at);
            if ($k < count($cells) && $cells[$k][1] > $cells[$k][0]) {
                $this->d->appendSeg($c, ['start' => $base + $cells[$k][0], 'end' => $base + $cells[$k][1], 'pad' => 0, 'cell' => true]);
                $this->b($c)->end = $base + $cells[$k][1];
            }
            $this->finalize($c);
        }
    }

    /** Adds what remains of the line to $container, or to the paragraph it lazily continues. */
    private function addTextToContainer(int $container): void
    {
        $d = $this->d;
        $this->findFirstNonspace();
        $cb = $d->blocks[$container];
        if ($this->blank && $cb->parse->lastChild >= 0) {
            $d->blocks[$cb->parse->lastChild]->parse->lastLineBlank = true;
        }
        $ck = $cb->kind;
        $lastLineBlank = $this->blank && $ck !== Block::QUOTE && $ck !== Block::HEADING && $ck !== Block::THEMATIC_BREAK
            && !($ck === Block::CODE_BLOCK && $cb->fenced) && $ck !== Block::MATH_BLOCK
            && !($ck === Block::ITEM && $cb->parse->lastChild < 0 && $cb->parse->startLine === $this->lineNum);
        $cb->parse->lastLineBlank = $lastLineBlank;
        for ($t = $cb->parent; $t >= 0; $t = $d->blocks[$t]->parent) {
            $d->blocks[$t]->parse->lastLineBlank = false;
        }
        if ($this->current !== $this->lastMatchedContainer && $container === $this->lastMatchedContainer && !$this->blank && $d->blocks[$this->current]->kind === Block::PARAGRAPH) {
            // A lazy continuation line.
            $this->addLine($this->current);
            return;
        }
        while ($this->current !== $this->lastMatchedContainer) {
            $this->current = $this->finalize($this->current);
        }
        $cb = $d->blocks[$container];
        if ($cb->kind === Block::CODE_BLOCK) {
            // A fence's opening line holds its info string, not code.
            if (!$cb->fenced || $cb->parse->startLine !== $this->lineNum) {
                $this->addLine($container);
            }
        } elseif ($cb->kind === Block::MATH_BLOCK && $cb->parse->startLine === $this->lineNum) {
            // Its opening line, which mathStart took.
        } elseif ($cb->kind === Block::MATH_BLOCK && !$cb->parse->mathDone) {
            $rest = substr($this->ln, $this->offset);
            $cl = $cb->parse->mathClose();
            $j = strrpos($rest, $cl);
            if ($j !== false && trim(substr($rest, $j + strlen($cl)), " \t") === '') {
                if (trim(substr($rest, 0, $j), " \t") !== '') {
                    $this->addLine($container);
                    $d->segs[$cb->segB - 1]['end'] = $this->lineStart + $this->offset + $j;
                }
                $cb->parse->mathDone = true;
                $cb->end = $this->lineStart + strlen($this->ln);
            } else {
                $this->addLine($container);
            }
        } elseif ($cb->kind === Block::HTML_BLOCK) {
            $this->addLine($container);
            if (Scan::htmlBlockEnds($cb->parse->htmlType, substr($this->ln, $this->firstNonspace))) {
                $container = $this->finalize($container);
            }
        } elseif ($this->blank) {
        } elseif (self::acceptsLines($cb->kind) && $cb->kind !== Block::MATH_BLOCK) {
            if ($cb->kind === Block::HEADING) {
                $this->addHeadingText($container);
            } else {
                $this->advanceOffset($this->firstNonspace - $this->offset, false);
                $this->addLine($container);
            }
        } else {
            $container = $this->addChild($container, Block::PARAGRAPH, $this->lineStart + $this->firstNonspace);
            $this->advanceOffset($this->firstNonspace - $this->offset, false);
            $this->addLine($container);
        }
        $this->current = $container;
    }

    /** Adds the text of an ATX heading, without its closing sequence of #. */
    private function addHeadingText(int $h): void
    {
        $this->advanceOffset($this->firstNonspace - $this->offset, false);
        $start = $this->offset; $end = strlen($this->ln);
        while ($end > $start && Scan::isSpaceOrTab($this->ln[$end - 1])) { $end--; }
        $k = $end;
        while ($k > $start && $this->ln[$k - 1] === '#') { $k--; }
        if ($k === $start) {
            $end = $start;
        } elseif ($k < $end && Scan::isSpaceOrTab($this->ln[$k - 1])) {
            $end = $k;
            while ($end > $start && Scan::isSpaceOrTab($this->ln[$end - 1])) { $end--; }
        }
        $this->d->appendSeg($h, ['start' => $this->lineStart + $start, 'end' => $this->lineStart + $end, 'pad' => 0, 'cell' => false]);
        $this->b($h)->end = $this->lineStart + strlen($this->ln);
    }

    /** The length of a thematic break at $i, or 0, noting where scanning failed so that later tries on the line stop there. */
    private function scanThematicBreak(int $i): int
    {
        $c = $this->peek($i);
        if ($c !== '*' && $c !== '_' && $c !== '-') {
            $this->thematicBreakKillPos = $i;
            return 0;
        }
        $count = 1;
        $j = $i + 1;
        for ($n = strlen($this->ln); $j < $n; $j++) {
            if ($this->ln[$j] === $c) {
                $count++;
            } elseif ($this->ln[$j] !== ' ' && $this->ln[$j] !== "\t") {
                break;
            }
        }
        if ($count >= 3 && $j === strlen($this->ln)) {
            return $j - $i + 1;
        }
        $this->thematicBreakKillPos = $j;
        return 0;
    }
}
