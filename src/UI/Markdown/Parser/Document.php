<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * Markdown parsed (mygo's parser.Document), which takes text appended to
 * it, or edits, and parses only what they change. CommonMark 0.31.2 with
 * the Extensions given; offsets are bytes of the source.
 */
final class Document
{
    /** @internal */
    public string $src = '';
    /** @internal */
    public int $ext;
    /** @var list<Block> @internal */
    public array $blocks = [];
    /** @var list<array{start: int, end: int, pad: int, cell: bool}> a leaf block's content lines: pad spaces (from a tab partly consumed by indentation), the source's bytes [start, end), and a newline unless a cell @internal */
    public array $segs = [];
    /** @var array<string, array{dest: string, title: string, at: int}> the link reference definitions by label @internal */
    public array $refs = [];
    /** @var list<array{key: string, dest: string, title: string, at: int}> the definitions in order, duplicates included, which take over as edits remove those before them */
    private array $defs = [];
    /** @var array<string, int> footnote label → definition block @internal */
    public array $notes = [];
    /**
     * The offset of the line where parsing restarts as text is appended:
     * the start of the outermost block open after the last complete line,
     * or that line's end; $restartAt the index of the first block parsed
     * from there. @internal
     */
    public int $restart = 0, $restartAt = 1;
    /** Completes unclosed syntax at the end of the source (setStreaming), and $tail is the block holding the end. @internal */
    public bool $streaming = false;
    /** @internal */
    public int $tail = -1;
    /** Where the source parsed for display ends: text held back while streaming lies past it. @internal */
    public int $cut = 0;
    /** @var list<array{at: int, rev: int, text: string, segs: list<array>, inl: ?list<Inline>, labels: ?list<string>, healed: bool}> the inlines of blocks parsed again as text is appended, to reuse @internal */
    public array $oldInl = [];
    /** The block whose inlines were parsed healed, or -1. @internal */
    public int $healedAt = -1;
    /** Counts the inlines parsed, to give each a revision of its own. */
    private int $revs = 0;
    /** @internal */
    public InlineParser $inp;

    /** An empty document reading $ext (Extensions' bits). */
    public function __construct(int $ext = Extensions::DEFAULT)
    {
        $this->ext = $ext;
        $this->inp = new InlineParser();
        $this->reset();
    }

    /** $src parsed with the default extensions. */
    public static function parse(string $src): self
    {
        $d = new self(Extensions::DEFAULT);
        $d->set($src);
        return $d;
    }

    private function reset(): void
    {
        $root = new Block(Block::ROOT, -1, 1);
        $root->parse->open = true;
        $root->parse->lastChild = -1;
        $this->blocks = [$root];
        $this->segs = [];
        $this->refs = [];
        $this->defs = [];
        $this->notes = [];
        $this->restart = 0; $this->restartAt = 1;
    }

    /** The text of the document. */
    public function source(): string { return $this->src; }

    /** The FOOTNOTE_DEFINITION block of a label, as a footnote reference's text holds it, or null. */
    public function footnote(string $label): ?int
    {
        return $this->notes[self::normalizeLabel($label)] ?? null;
    }

    /** What the document reads beyond CommonMark. */
    public function extensions(): int { return $this->ext; }

    /** The number of blocks, the root's included. */
    public function len(): int { return count($this->blocks); }

    /** Block $i, 0 being the root. */
    public function block(int $i): Block { return $this->blocks[$i]; }

    /** The indices of the children of block $i. @return \Generator<int> */
    public function children(int $i): \Generator
    {
        $end = $this->blocks[$i]->next;
        for ($j = $i + 1; $j < $end; $j = $this->blocks[$j]->next) {
            yield $j;
        }
    }

    /** Whether the document completes unclosed syntax at its end (setStreaming). */
    public function streaming(): bool { return $this->streaming; }

    /**
     * Sets whether the document is text still being written, as a language
     * model's reply: its end then shows what it will most likely become
     * rather than the half-written syntax it is. The last paragraph closes
     * its emphasis, code spans, strikethrough and math, a link whose
     * destination is being written is a link (Inline::$pending), a tag
     * being written is left out, and lines that could still turn into
     * something else are held back: a line of only a few markers, as - or
     * ==, which would make the paragraph above a heading, and a table's
     * header until its delimiter row comes. Finished blocks are never
     * changed. Turn it off once the text is complete, to parse its end
     * exactly as CommonMark does.
     */
    public function setStreaming(bool $on): void
    {
        if ($this->streaming === $on) {
            return;
        }
        $this->streaming = $on;
        $this->parseTail();
    }

    /**
     * Makes $src the text of the document, parsing only what changed since
     * the last: text appended to it, or else the blocks around the change
     * (edit()).
     */
    public function set(string $src): void
    {
        $old = $this->src;
        if ($src === $old) {
            return;
        }
        if (strlen($src) > strlen($old) && str_starts_with($src, $old)) {
            $this->src = $src;
            $this->parseTail();
            return;
        }
        // The common prefix and suffix bound the change.
        $p = 0; $lo = strlen($old); $ls = strlen($src);
        $max = min($lo, $ls);
        while ($p < $max && $old[$p] === $src[$p]) { $p++; }
        $s = 0;
        while ($s < $lo - $p && $s < $ls - $p && $old[$lo - 1 - $s] === $src[$ls - 1 - $s]) { $s++; }
        $this->edit($p, $lo - $s, substr($src, $p, $ls - $s - $p));
    }

    /** Adds $text to the end of the document, parsing again only the outermost block still open before it, or nothing but the new lines when no block is. */
    public function append(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->src .= $text;
        $this->parseTail();
    }

    /**
     * Replaces the source's bytes [$from, $to) with $text. Parsing restarts
     * at the top-level block before the change and goes on until a
     * top-level block starts where one started before it, from which the
     * blocks parsed before are kept.
     */
    public function edit(int $from, int $to, string $text): void
    {
        if ($from === $to && $text === '') {
            return;
        }
        if ($from === strlen($this->src) && $to === $from) {
            $this->append($text);
            return;
        }
        $this->editAt($from, $to, $text);
    }

    /** The source of the document. */
    public function __toString(): string { return $this->src; }

    /** Adds a definition, which defines its label unless one before it did. @internal */
    public function define(string $key, string $dest, string $title, int $at): void
    {
        $this->defs[] = ['key' => $key, 'dest' => $dest, 'title' => $title, 'at' => $at];
        if (!isset($this->refs[$key])) {
            $this->refs[$key] = ['dest' => $dest, 'title' => $title, 'at' => $at];
        }
    }

    // Parsing again (mygo's block.go, the Document's side).

    /** Parses the source again from the restart point. */
    private function parseTail(): void
    {
        $at = $this->restartAt; $off = $this->restart;
        $removed = $this->truncate($at, $off);
        $this->parseShown(null);
        $this->refsChanged($removed, $at, $off);
        $this->unheal();
    }

    /** Parses anew the inlines of a block parsed healed that is no longer the end of a streaming document. */
    private function unheal(): void
    {
        $h = $this->healedAt;
        if ($h < 0 || $h >= count($this->blocks) || $h === $this->tail && $this->streaming) {
            return;
        }
        $b = $this->blocks[$h];
        if ($b->healed) {
            $b->inlOK = false; $b->inl = null; $b->labels = null; $b->healed = false;
            $b->rev++;
        }
        $this->healedAt = -1;
    }

    /** Parses from the restart point to the end of what is shown. */
    private function parseShown(?\Closure $resync): bool
    {
        $cut = $this->displayCut();
        if ($this->parseRange($this->restart, $cut, $resync)) {
            return true;
        }
        if ($this->streaming && ($this->ext & Extensions::TABLES) !== 0) {
            // A table's header waits for its delimiter row.
            $c = $this->heldTableRow($cut);
            if ($c < $cut) {
                $this->truncate($this->restartAt, $this->restart);
                $this->parseRange($this->restart, $c, null);
                $cut = $c;
            }
        }
        $this->cut = $cut;
        return false;
    }

    /**
     * Replaces the source's [$from, $to) with $text, and parses again from
     * the top-level block before the one holding $from, until a top-level
     * block starts, with no block open, where one started after the change,
     * from which the blocks parsed before are kept.
     */
    private function editAt(int $from, int $to, string $text): void
    {
        $old = $this->src;
        $delta = strlen($text) - ($to - $from);
        $prev = 0;
        for ($j = 1, $k = 0, $n = count($this->blocks); $j < $n; $j = $this->blocks[$j]->next) {
            if ($this->blocks[$j]->parse->firstLine > $from) {
                break;
            }
            $prev = $k; $k = $j;
        }
        $at = 1; $off = 0;
        if ($prev > 0) {
            [$at, $off] = $this->restartOf($prev);
        }
        $tail = array_map(fn(Block $b) => clone $b, array_slice($this->blocks, $at));
        $oldSegs = $this->segs;
        $oldDefs = $this->defs;
        $oldRestart = $this->restart; $oldRestartAt = $this->restartAt; $oldTail = $this->tail; $oldCut = $this->cut;
        $removed = $this->truncate($at, $off);
        $this->src = substr($old, 0, $from) . $text . substr($old, $to);
        $this->restart = $off; $this->restartAt = $at;
        $editEnd = $from + strlen($text);
        $j = 0; $met = -1;
        $resync = function (int $line) use (&$j, &$met, $tail, $editEnd, $delta, $to): bool {
            if ($line < $editEnd) {
                return false;
            }
            $n = count($tail);
            while ($j < $n && ($tail[$j]->parent !== 0 || $tail[$j]->parse->firstLine + $delta < $line)) { $j++; }
            if ($j < $n && $tail[$j]->parse->quiet && $tail[$j]->parse->joined === 0 && $tail[$j]->parse->firstLine >= $to && $tail[$j]->parse->firstLine + $delta === $line) {
                $met = $j;
                return true;
            }
            return false;
        };
        if ($this->parseShown($resync)) {
            $splitAt = $tail[$met]->parse->firstLine;
            $shift = count($this->blocks) - ($at + $met);
            for ($m = $met, $n = count($tail); $m < $n; $m++) {
                $b = $tail[$m];
                if ($b->parent > 0) {
                    $b->parent += $shift;
                }
                $b->next += $shift;
                $b->start += $delta;
                $b->end += $delta;
                $b->parse->firstLine += $delta;
                if ($b->parse->joined > 0) {
                    $b->parse->joined += $shift;
                }
                $segs = array_slice($oldSegs, $b->segA, $b->segB - $b->segA);
                $b->segMark = count($this->segs);
                $b->segA = $b->segMark;
                foreach ($segs as $sg) {
                    $sg['start'] += $delta;
                    $sg['end'] += $delta;
                    $this->segs[] = $sg;
                }
                $b->segB = count($this->segs);
                if ($b->textS >= 0) {
                    $b->textS += $delta;
                }
                if ($b->inlOK && $b->inl !== null && $b->inl !== []) {
                    $b->inl = array_map(function (Inline $x) use ($delta) { $x = clone $x; $x->start += $delta; $x->end += $delta; return $x; }, $b->inl);
                }
                $b->parse->lastChild = -1; $b->parse->prev = -1;
                $i = count($this->blocks);
                $this->blocks[] = $b;
                if ($b->parent === 0) {
                    $this->blocks[0]->parse->lastChild = $i;
                }
                if ($b->kind === Block::FOOTNOTE_DEFINITION) {
                    $key = self::normalizeLabel($b->info);
                    if (!isset($this->notes[$key])) {
                        $this->notes[$key] = $i;
                    }
                }
            }
            $this->blocks[0]->next = count($this->blocks);
            // The definitions of the blocks kept, duplicates included, which take over from those gone.
            foreach ($oldDefs as $df) {
                if ($df['at'] >= $splitAt) {
                    $this->define($df['key'], $df['dest'], $df['title'], $df['at'] + $delta);
                }
            }
            if ($oldRestart >= $splitAt) {
                $this->restart = $oldRestart + $delta; $this->restartAt = $oldRestartAt + $shift;
            }
            if ($oldTail >= $at + $met) {
                $this->tail = $oldTail + $shift;
            }
            $this->cut = $oldCut + $delta;
        }
        $this->refsChanged($removed, $at, $off);
        $this->unheal();
    }

    /**
     * Drops the blocks from index $at, parsed from offset $off, and the
     * definitions made there, which it returns, keeping their inlines to
     * reuse. @internal
     *
     * @return array<string, array{dest: string, title: string, at: int}>
     */
    public function truncate(int $at, int $off): array
    {
        $this->oldInl = [];
        for ($i = $at, $n = count($this->blocks); $i < $n; $i++) {
            $b = $this->blocks[$i];
            if ($b->inlOK && $b->segB > $b->segA) {
                $this->oldInl[] = ['at' => $this->segs[$b->segA]['start'], 'rev' => $b->rev, 'text' => $b->text, 'segs' => $this->segsOf($b), 'inl' => $b->inl, 'labels' => $b->labels, 'healed' => $b->healed];
            }
        }
        $root = $this->blocks[0];
        $root->next = $at;
        if ($at < count($this->blocks)) {
            $root->parse->lastChild = $this->blocks[$at]->parse->prev;
            $this->segs = array_slice($this->segs, 0, $this->blocks[$at]->segMark);
        }
        $this->blocks = array_slice($this->blocks, 0, $at);
        $this->defs = array_values(array_filter($this->defs, fn($df) => $df['at'] < $off));
        $removed = [];
        foreach ($this->refs as $k => $r) {
            if ($r['at'] >= $off) {
                $removed[$k] = $r;
                unset($this->refs[$k]);
            }
        }
        foreach ($this->notes as $k => $i) {
            if ($i >= $at) {
                $removed['^' . $k] = ['dest' => '', 'title' => '', 'at' => -1];
                unset($this->notes[$k]);
            }
        }
        return $removed;
    }

    /** Parses anew the inlines of blocks that looked up a definition made or unmade by parsing from block $at, offset $off, which $removed held before. */
    private function refsChanged(array $removed, int $at, int $off): void
    {
        $changed = [];
        foreach ($removed as $k => $r) {
            $k = (string)$k;
            if (str_starts_with($k, '^')) {
                if (!isset($this->notes[substr($k, 1)])) {
                    $changed[] = $k;
                }
            } elseif (!isset($this->refs[$k]) || $this->refs[$k]['dest'] !== $r['dest'] || $this->refs[$k]['title'] !== $r['title']) {
                $changed[] = $k;
            }
        }
        foreach ($this->refs as $k => $r) {
            if ($r['at'] >= $off) {
                $o = $removed[$k] ?? null;
                if ($o === null || $o['dest'] !== $r['dest'] || $o['title'] !== $r['title']) {
                    $changed[] = (string)$k;
                }
            }
        }
        foreach ($this->notes as $k => $i) {
            if ($i >= $at && !isset($removed['^' . $k])) {
                $changed[] = '^' . $k;
            }
        }
        if ($changed === []) {
            return;
        }
        foreach ($this->oldInl as $k => $o) {
            foreach ($o['labels'] ?? [] as $l) {
                if (in_array($l, $changed, true)) {
                    $this->oldInl[$k]['text'] = ''; // matches no block
                    break;
                }
            }
        }
        foreach ($this->blocks as $b) {
            if (!$b->inlOK || !$b->labels) {
                continue;
            }
            foreach ($b->labels as $l) {
                if (in_array($l, $changed, true)) {
                    $b->inlOK = false; $b->inl = null; $b->labels = null;
                    $b->rev++;
                    break;
                }
            }
        }
    }

    /** The offset of the start of the line holding $off. @internal */
    public static function lineStartOf(string $src, int $off): int
    {
        while ($off > 0 && $src[$off - 1] !== "\n" && $src[$off - 1] !== "\r") {
            $off--;
        }
        return $off;
    }

    /** Where the source shown ends: while streaming, a rune half received, and a last line that is only a few markers, as - or ==, wait for what follows them. */
    private function displayCut(): int
    {
        $n = strlen($this->src);
        if (!$this->streaming) {
            return $n;
        }
        // A rune half received.
        for ($i = 1; $i <= 3 && $i <= $n; $i++) {
            $c = $this->src[$n - $i];
            if (ord($c) < 0x80) {
                break;
            }
            if (Scan::runeStart($c)) {
                if (!Scan::fullRune(substr($this->src, $n - $i))) {
                    $n -= $i;
                }
                break;
            }
        }
        $ls = self::lineStartOf($this->src, $n);
        $line = trim(substr($this->src, $ls, $n - $ls), " \t");
        if ($line !== '' && strlen($line) <= 3 && trim($line, '-=*+#>|_~`:.)0123456789') === '') {
            return $ls;
        }
        return $n;
    }

    /**
     * Where the source shown ends when the paragraph at its end ends in
     * lines starting with a pipe, which may be a table's header whose
     * delimiter row is coming: before those lines.
     */
    private function heldTableRow(int $cut): int
    {
        $t = $this->tail;
        if ($t <= 0 || $t >= count($this->blocks) || $this->blocks[$t]->kind !== Block::PARAGRAPH) {
            return $cut;
        }
        $c = $cut;
        $segs = $this->segsOf($this->blocks[$t]);
        for ($i = count($segs) - 1; $i >= 0; $i--) {
            $s = $segs[$i];
            $line = substr($this->src, $s['start'], $s['end'] - $s['start']);
            if (!str_starts_with(ltrim($line, " \t"), '|')) {
                break;
            }
            $c = self::lineStartOf($this->src, $s['start']);
        }
        return $c;
    }

    /** Parses the source from $off, a line's start, to $end, with no block open but the root, adding blocks after those there are. */
    private function parseRange(int $off, int $end, ?\Closure $resync): bool
    {
        return (new BlockParser($this, substr($this->src, 0, $end), $resync))->run($off);
    }

    /** The block parsing anew starts with to give top-level block $i again, and the offset of its first line: a table made of a paragraph's last line starts with the paragraph. @internal @return array{0: int, 1: int} */
    public function restartOf(int $i): array
    {
        $j = $this->blocks[$i]->parse->joined;
        if ($j > 0) {
            $i = $j;
        }
        return [$i, $this->blocks[$i]->parse->firstLine];
    }

    /** The content lines of $b. @internal */
    public function segsOf(Block $b): array
    {
        return array_slice($this->segs, $b->segA, $b->segB - $b->segA);
    }

    /** Adds a content line to block $i, moving its lines to the end of the document's first if others came after them. @internal */
    public function appendSeg(int $i, array $s): void
    {
        $b = $this->blocks[$i];
        if ($b->segB !== count($this->segs)) {
            $n = $b->segB - $b->segA;
            array_push($this->segs, ...$this->segsOf($b));
            $b->segB = count($this->segs);
            $b->segA = $b->segB - $n;
        }
        $this->segs[] = $s;
        $b->segB++;
    }

    /** The content of leaf block $i, as one string, and the source offset of its first byte when it is a slice of the source, else -1. @internal @return array{0: string, 1: int} */
    public function contentOf(int $i): array
    {
        $b = $this->blocks[$i];
        if ($b->text !== '' || $b->segB === $b->segA) {
            return [$b->text, $b->textS];
        }
        $segs = $this->segsOf($b);
        if ($segs[0]['cell']) {
            $s = substr($this->src, $segs[0]['start'], $segs[0]['end'] - $segs[0]['start']);
            if (str_contains($s, '\|')) {
                $b->text = str_replace('\|', '|', $s); $b->textS = -1;
            } else {
                $b->text = $s; $b->textS = $segs[0]['start'];
            }
            return [$b->text, $b->textS];
        }
        // A slice of the source when the lines follow each other with \n.
        $contiguous = true;
        $n = count($segs); $len = strlen($this->src);
        foreach ($segs as $k => $s) {
            if ($s['pad'] !== 0) {
                $contiguous = false;
                break;
            }
            if ($k < $n - 1 && ($s['end'] >= $len || $this->src[$s['end']] !== "\n" || $segs[$k + 1]['start'] !== $s['end'] + 1)) {
                $contiguous = false;
                break;
            }
        }
        $last = $segs[$n - 1];
        if ($contiguous) {
            // Without the final newline, which nothing needs.
            $b->text = substr($this->src, $segs[0]['start'], $last['end'] - $segs[0]['start']); $b->textS = $segs[0]['start'];
            return [$b->text, $b->textS];
        }
        $sb = '';
        foreach ($segs as $k => $s) {
            $sb .= str_repeat(' ', $s['pad']) . substr($this->src, $s['start'], $s['end'] - $s['start']);
            if ($k < $n - 1) {
                $sb .= "\n";
            }
        }
        $b->text = $sb; $b->textS = -1;
        return [$b->text, $b->textS];
    }

    /** The map of block $i's content to the source (mygo's sourceMap), valid until the block changes. */
    public function sourceMap(int $i): SourceMap
    {
        $b = $this->blocks[$i];
        if ($b->textS >= 0) {
            return new SourceMap($b->textS, $b->start, [], []);
        }
        $segs = $this->segsOf($b);
        $marks = [];
        if ($segs !== [] && $segs[0]['cell']) {
            // The offsets in the content of each | written \|.
            $src = substr($this->src, $segs[0]['start'], $segs[0]['end'] - $segs[0]['start']);
            for ($j = 0, $o = 0, $sl = strlen($src); $j < $sl; $j++, $o++) {
                if ($src[$j] === '\\' && $j + 1 < $sl && $src[$j + 1] === '|') {
                    $j++;
                    $marks[] = $o;
                }
            }
        } else {
            // The offset in the content where each line starts.
            $o = 0;
            foreach ($segs as $sg) {
                $marks[] = $o;
                $o += $sg['pad'] + $sg['end'] - $sg['start'] + 1;
            }
        }
        return new SourceMap(-1, $b->start, $segs, $marks);
    }

    /** The content of a code, HTML or math block, each line ending with a newline. */
    public function lines(int $i): string
    {
        $sb = '';
        foreach ($this->segsOf($this->blocks[$i]) as $s) {
            $sb .= str_repeat(' ', $s['pad']) . substr($this->src, $s['start'], $s['end'] - $s['start']) . "\n";
        }
        return $sb;
    }

    /** The number of lines of a code, HTML or math block. */
    public function lineCount(int $i): int
    {
        $b = $this->blocks[$i];
        return $b->segB - $b->segA;
    }

    /** Line $k of a code, HTML or math block, without its newline, and the offset in the source of its first byte. @return array{0: string, 1: int} */
    public function line(int $i, int $k): array
    {
        $s = $this->segs[$this->blocks[$i]->segA + $k];
        return [str_repeat(' ', $s['pad']) . substr($this->src, $s['start'], $s['end'] - $s['start']), $s['start']];
    }

    /** The inlines of block $i, a paragraph, a heading or a table cell, parsing them the first time; null for other blocks. @return ?list<Inline> */
    public function inlines(int $i): ?array
    {
        $b = $this->blocks[$i];
        if ($b->inlOK) {
            return $b->inl;
        }
        if ($b->kind !== Block::PARAGRAPH && $b->kind !== Block::HEADING && $b->kind !== Block::TABLE_CELL) {
            return null;
        }
        $b->inlOK = true;
        [$text] = $this->contentOf($i);
        if ($b->segB > $b->segA && $i !== $this->tail) {
            $at = $this->segs[$b->segA]['start'];
            $segs = $this->segsOf($b);
            foreach ($this->oldInl as $o) {
                if ($o['at'] === $at && $o['text'] === $text && !$o['healed'] && $o['segs'] === $segs) {
                    $b->inl = $o['inl']; $b->labels = $o['labels']; $b->rev = $o['rev'];
                    return $b->inl;
                }
            }
        }
        $b->healed = $this->streaming && $i === $this->tail;
        if ($b->healed) {
            $this->healedAt = $i;
        }
        [$b->inl, $b->labels] = $this->inp->parse($this, $i, $b->healed);
        $this->revs++;
        $b->rev = $this->revs;
        return $b->inl;
    }

    /** $s normalized as CommonMark matches link references and footnotes: whitespace collapsed and case folded (mygo's NormalizeLabel). */
    public static function normalizeLabel(string $s): string
    {
        $b = ''; $space = false;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $space = $b !== '';
                continue;
            }
            if ($space) {
                $b .= ' ';
                $space = false;
            }
            $b .= $c;
        }
        $b = mb_convert_case($b, MB_CASE_LOWER_SIMPLE, 'UTF-8');
        if (str_contains($b, 'ß')) {
            $b = str_replace('ß', 'ss', $b);
        }
        return mb_convert_case($b, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /** The document as HTML, as cmark-gfm renders it, raw HTML included as written. */
    public function html(): string
    {
        return (new Html($this))->render();
    }
}
