<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use Closure;
use MyPHP\App;
use MyPHP\UI\{Context, Key, Menu, Node, Role, Router, SelectedText, Span, SVG, Painter, Theme as UITheme, TextPainter, Path};
use MyPHP\UI\Markdown\Parser\{Block, Document, Inline};
use MyPHP\Transfer\{Data, Format, Item, Representation};

/** What a Markdown view keeps from frame to frame, and builds its blocks with (mygo's viewState). */
final class ViewState
{
    public Options $o;
    public ?Document $doc = null;
    public int $ext = 0;
    public string $uiTh = '';
    public ?Theme $def = null;
    public Theme $th;
    public string $thKey = '';
    /** @var array<int, array{start: int, end: int, kind: int, rev: int, base: string, p: Para}> the paragraphs made, by block (mygo's paraCache) */
    public array $paras = [];
    /** @var list<array{0: int, 1: int, 2: int}> for copying, the block each paragraph's node shows, and for a code block's chunk its first line: node id, block, line (mygo's elemInfo) */
    public array $elems = [];
    public RevealState $reveal;
    /** @var array<int, CodeState> by the block's start */
    public array $codes = [];
    /** @var array<int, array{widths: list<float>}> the tables' column widths, by the block's start, which only grow while a table is being written */
    public array $tables = [];
    /** A block to scroll into view, -1 for none. */
    public int $scrollTo = -1;
    /** @var array<string, int> the footnotes' numbers, by their normalized labels */
    public array $notes = [];
    public SpanBuilder $sb;
    /** @var ?Closure(int): float */
    public ?Closure $fade = null;
    private Context $c;

    public function __construct()
    {
        $this->reveal = new RevealState();
        $this->sb = new SpanBuilder();
    }

    /** Builds the view of $src in $c (mygo's build). */
    public static function build(Context $c, string $src, ?Options $o, bool $streaming): Node
    {
        static $defaults = null;
        $o ??= $defaults ??= new Options();
        $root = $c->column();
        $st = $c->runtime?->local($root->id, fn() => new self()) ?? new self();
        $st->c = $c;
        $st->o = $o;
        $ext = $o->ext();
        if ($st->doc === null || $st->ext !== $ext) {
            $st->doc = new Document($ext); $st->ext = $ext;
            $st->paras = [];
        }
        $st->theme($c);
        $th = $st->th;
        $now = $c->runtime?->now ?? hrtime(true);
        [$shown, $revealing] = $st->reveal->step($now, $src, $streaming, $o->reveal);
        $st->doc->setStreaming($streaming || $revealing);
        if (!str_starts_with(substr($src, 0, $shown), $st->doc->source())) {
            $st->forget(); // another text, not more of it: blocks where blocks were may show other text
        }
        $st->doc->set(substr($src, 0, $shown));
        $st->fade = $st->reveal->fadeAt($now, $c->runtime?->reduceMotion ?? false, $o->reveal);

        $root->fontSize($th->fontSize)->lineHeight($th->lineHeight)->textColor($th->text[0], $th->text[1]);
        if ($th->font !== '') {
            $root->font($th->font);
        }
        if (!$o->shareSelection) {
            $root->selectable();
            $root->copyData($st->copyData(...));
            $root->contextMenu(function (Menu $m) use ($c, $st, $root) {
                $m->editItems();
                if ($m->item($c->texts()->get('copyAsMarkdown'))->chosen()) {
                    ($c->runtime->writeClipboardData)(self::markdownData($st->copyMarkdown($root->selectedTexts())));
                }
            });
            if ($c->focusWithin($root) && $c->shortcut('c', Key::COMMAND | Key::SHIFT)) {
                ($c->runtime->writeClipboardData)(self::markdownData($st->copyMarkdown($root->selectedTexts())));
            }
        }
        $st->elems = [];
        $st->numberNotes();
        $st->blocks(0, false, new SpanStyle());
        $st->footnotes();
        $c->end();
        if ($revealing || $st->reveal->fading()) {
            $c->runtime?->animationFrame();
        }
        return $root;
    }

    /** Resolves the theme of the frame (mygo's theme). */
    private function theme(Context $c): void
    {
        $key = serialize($c->theme);
        if ($this->def === null || $this->uiTh !== $key) {
            $this->uiTh = $key;
            $this->def = Theme::defaultTheme($c->theme);
        }
        $th = Theme::resolve($this->o->theme, $this->def);
        $k = $th->key();
        if ($k !== $this->thKey) {
            $this->th = $th; $this->thKey = $k;
            $this->paras = [];
            foreach ($this->codes as $cs) { $cs->chunks = []; }
        }
        $this->sb->d = $this->doc; $this->sb->th = $this->th;
    }

    /** Drops what the view made of its blocks (mygo's forget). */
    private function forget(): void
    {
        $this->paras = []; $this->codes = []; $this->tables = [];
    }

    public function note(int $id, int $block, int $line): void
    {
        $this->elems[] = [$id, $block, $line];
    }

    /** Builds the children of block $parent, spaced; $tight tells the items of a tight list (mygo's blocks). */
    public function blocks(int $parent, bool $tight, SpanStyle $base): void
    {
        $d = $this->doc; $th = $this->th;
        $first = true; $prevHeading = 0;
        for ($j = $parent + 1; $j < $d->block($parent)->next; $j = $d->block($j)->next) {
            $b = $d->block($j);
            if ($b->kind === Block::FOOTNOTE_DEFINITION) {
                continue;
            }
            $top = 0.0;
            if (!$first) {
                $top = $th->em($tight ? $th->itemSpacing : $th->blockSpacing);
                if ($prevHeading > 0) { $top = $th->em($th->headings[$prevHeading - 1]->bottom); }
                if ($b->kind === Block::HEADING) { $top = max($top, $th->em($th->headings[$b->level - 1]->top)); }
            }
            $el = $this->block($j, $tight, $base);
            $this->fadeIn($el, $b);
            if ($top > 0) {
                $el->margin($top, 0, 0, 0);
            }
            if ($this->scrollTo === $j) {
                $el->scrollIntoView();
                $this->scrollTo = -1;
            }
            $prevHeading = $b->kind === Block::HEADING ? $b->level : 0;
            $first = false;
        }
    }

    /** Builds block $i, through Options::$block (mygo's block). */
    private function block(int $i, bool $tight, SpanStyle $base): Node
    {
        $c = $this->c;
        if ($this->o->block !== null) {
            $box = $c->column();
            $done = (bool)($this->o->block)($c, $this->doc, $i);
            if (!$done) {
                $this->defaultBlock($i, $tight, $base);
            }
            $c->end();
            return $box;
        }
        return $this->defaultBlock($i, $tight, $base);
    }

    private function defaultBlock(int $i, bool $tight, SpanStyle $base): Node
    {
        $c = $this->c; $d = $this->doc; $th = $this->th;
        $b = $d->block($i);
        switch ($b->kind) {
            case Block::PARAGRAPH:
                if (self::imagesOnly($d->inlines($i) ?? [])) {
                    return $this->images($i);
                }
                return $this->paragraph($i, $base);
            case Block::HEADING:
                $h = $th->headings[$b->level - 1];
                $s = clone $base;
                $s->weight = $h->weight; $s->size = $th->em($h->size); $s->color = $h->color;
                if ($h->rule) { // text takes no border here: a box draws the rule below it
                    $box = $c->column()->borderWidth(0, 0, 1, 0)->borderColor($th->rule[0])->padding(0, 0, $th->em(0.3), 0);
                    $this->paragraph($i, $s)->role(Role::HEADING)->level($b->level);
                    $c->end();
                    return $box;
                }
                return $this->paragraph($i, $s)->role(Role::HEADING)->level($b->level);
            case Block::THEMATIC_BREAK:
                $hr = $c->column()->size(0, 1)->background($th->rule[0], $th->rule[1])->alignSelf(Node::STRETCH)->margin($th->em(0.25), 0);
                $c->end();
                return $hr;
            case Block::CODE_BLOCK:
                return $this->codeBlock($i);
            case Block::HTML_BLOCK:
                $html = rtrim($d->lines($i), "\n");
                if ($this->o->html !== null) {
                    $box = $c->column();
                    ($this->o->html)($c, $html);
                    $c->end();
                    return $box;
                }
                $el = $c->text($html)->font($th->codeFont)->fontSize($th->em($th->codeSize))->textColor($th->muted[0], $th->muted[1]);
                $this->note($el->id, $i, 0);
                return $el;
            case Block::MATH_BLOCK:
                $tex = rtrim($d->lines($i), "\n");
                if ($this->o->math !== null) {
                    $box = $c->column();
                    ($this->o->math)($c, $tex);
                    $c->end();
                    return $box;
                }
                $box = $this->scrollHorizontal()->alignSelf(Node::STRETCH);
                $el = $c->text($tex)->font($th->codeFont)->fontSize($th->em($th->codeSize))->textColor($th->muted[0], $th->muted[1])->noWrap()->margin(0, Node::AUTO);
                $this->note($el->id, $i, 0);
                $c->end();
                return $box;
            case Block::QUOTE:
                $s = clone $base;
                $s->color = $th->quoteText;
                // The bar on the side lines start.
                $q = $c->column()->borderStartWidth($th->quoteBarWidth)->borderColor($th->quoteBar[0])->paddingStart($th->em(0.85))->textColor($th->quoteText[0], $th->quoteText[1]);
                $this->blocks($i, false, $s);
                $c->end();
                return $q;
            case Block::LIST:
                return $this->list($i, $base);
            case Block::TABLE:
                return $this->table($i, $base);
        }
        $box = $c->column();
        $c->end();
        return $box;
    }

    /** A row scrolling sideways, its scroll state kept by the node (mygo's ScrollHorizontal); built in until end(). */
    private function scrollHorizontal(): Node
    {
        $c = $this->c;
        $n = $c->row()->alignItems(Node::START);
        $state = $c->runtime?->local($n->id, fn() => new \MyPHP\UI\ScrollState()) ?? new \MyPHP\UI\ScrollState();
        return $n->scroll($state, true, false);
    }

    /** Fades in a block revealed a moment ago: text fades by its spans, the rest of blocks, as a code block's box or a table's lines, by their opacity (mygo's fadeIn). */
    private function fadeIn(Node $el, Block $b): void
    {
        if ($this->fade === null || in_array($b->kind, [Block::PARAGRAPH, Block::HEADING, Block::LIST], true)) {
            return;
        }
        if (($a = ($this->fade)($b->start)) < 1) {
            $el->opacity($a);
        }
    }

    /** The paragraph of block $i in style $base, built anew only when it changed (mygo's para). */
    public function para(int $i, SpanStyle $base): Para
    {
        $b = $this->doc->block($i);
        $this->doc->inlines($i); // their revision
        $pc = $this->paras[$i] ?? null;
        $fading = $this->fade !== null && ($this->fade)(max($b->end - 1, $b->start)) < 1;
        $bk = $base->key();
        if ($pc !== null && $pc['start'] === $b->start && $pc['end'] === $b->end && $pc['kind'] === $b->kind && $pc['rev'] === $b->revision() && $pc['base'] === $bk && !$pc['p']->fading && !$fading && !$b->healed()) {
            return $pc['p'];
        }
        $p = $pc['p'] ?? new Para();
        $this->paras[$i] = ['start' => $b->start, 'end' => $b->end, 'kind' => $b->kind, 'rev' => $b->revision(), 'base' => $bk, 'p' => $p];
        $sb = $this->sb;
        $sb->block = $i;
        $sb->fade = $this->fade;
        $sb->notes = $this->noteNumber(...);
        $sb->math = $this->o->inlineMath;
        $sb->build($p, $base);
        return $p;
    }

    /** Builds the text of block $i: a paragraph, a heading or a cell (mygo's paragraph). */
    public function paragraph(int $i, SpanStyle $base): Node
    {
        $c = $this->c;
        $para = $this->para($i, $base);
        $ps = $para->pieces;
        if ($ps === []) {
            $el = $c->text('');
        } elseif ($ps[0][0] === -1) {
            $el = $c->richText($ps[0][1], wrap: true);
            array_shift($ps);
        } else {
            $el = $c->richText([], wrap: true);
        }
        if ($ps !== []) {
            $el->children(function () use ($ps, $i) {
                foreach ($ps as $pc) {
                    $this->piece($i, $pc);
                }
            });
        }
        if (($objects = $para->objects) !== []) { // its math drawn by the app (Options::$inlineMath): a list of this build's own, which the drawing keeps
            $el->drawObjects(function (\MyPHP\UI\Painter $p, int $at, array $r) use ($objects): void {
                $k = self::objectAt($objects, $at);
                if ($k !== null && $objects[$k][3] !== null) { ($objects[$k][3])($p, $r); }
            });
        }
        $this->note($el->id, $i, -1);
        return $el;
    }

    /** The index of the object of rune $at among $objects, in order of their runes, or null. @param list<array{0: int, 1: int, 2: int, 3: ?Closure}> $objects */
    private static function objectAt(array $objects, int $at): ?int
    {
        [$lo, $hi] = [0, count($objects) - 1];
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($objects[$mid][0] === $at) { return $mid; }
            if ($objects[$mid][0] < $at) { $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        return null;
    }

    /** Builds a link, or text after one, within a paragraph (mygo's piece). @param array{0: int, 1: list<Span>} $pc */
    private function piece(int $block, array $pc): void
    {
        $c = $this->c;
        [$link, $spans] = $pc;
        if ($link === -1) {
            $c->richText($spans);
        } elseif ($link < -1) { // a footnote reference: it scrolls to the footnote
            $in = ($this->doc->inlines($block) ?? [])[-2 - $link];
            $l = $c->link('', '');
            $l->children(fn() => $c->richText($spans));
            if ($c->clicked($l) && ($i = $this->doc->footnote($in->text)) !== null) {
                $this->scrollTo = $i;
            }
        } else {
            $in = ($this->doc->inlines($block) ?? [])[$link];
            $this->link($in, fn() => $c->richText($spans));
        }
    }

    /** Builds a link to $in's destination whose text $children builds (mygo's link). */
    private function link(Inline $in, Closure $children): void
    {
        $c = $this->c;
        $dest = $in->dest;
        if ($in->pending) {
            $l = $c->link('', '');
        } elseif ($this->o->onLink !== null) {
            $l = $c->link('', '');
            if ($c->clicked($l)) { ($this->o->onLink)($dest); }
        } elseif (str_starts_with($dest, '#')) {
            $l = $c->link('', '');
            if ($c->clicked($l)) { $this->scrollTo = $this->headingNamed(substr($dest, 1)); }
        } elseif (self::linkAllowed($dest, $c->router() !== null)) {
            $l = $c->link('', $dest);
        } else {
            $l = $c->link('', '');
        }
        $l->children($children);
        if ($in->title !== '') {
            $l->tooltip($in->title);
        } elseif (!$in->auto && !$in->pending && $dest !== '' && !str_starts_with($dest, '#')) {
            $l->tooltip($dest);
        }
    }

    /** Whether a link's destination opens without Options::$onLink: one Context::safeToOpen allows, or, in a router, a path without a scheme, which goes there (mygo's linkAllowed). */
    public static function linkAllowed(string $dest, bool $router): bool
    {
        if (strpbrk($dest, "\\\0") !== false || str_starts_with($dest, '//')) {
            return false;
        }
        $i = strcspn($dest, ':/?#');
        if ($i === strlen($dest) || $dest[$i] !== ':') {
            return $router;
        }
        return Context::safeToOpen($dest);
    }

    /** The heading whose slug, as GitHub makes it, is $name, or -1 (mygo's headingNamed). */
    private function headingNamed(string $name): int
    {
        $d = $this->doc;
        for ($i = 1; $i < $d->len(); $i++) {
            if ($d->block($i)->kind === Block::HEADING && Markdown::slug(Inline::plainText($d->inlines($i) ?? [])) === mb_strtolower($name, 'UTF-8')) {
                return $i;
            }
        }
        return -1;
    }

    /** Builds a list (mygo's list). */
    private function list(int $i, SpanStyle $base): Node
    {
        $c = $this->c; $d = $this->doc; $th = $this->th;
        $b = $d->block($i);
        $depth = 0;
        for ($p = $b->parent; $p > 0; $p = $d->block($p)->parent) {
            if ($d->block($p)->kind === Block::LIST) { $depth++; }
        }
        $width = $th->em($th->listIndent);
        if ($b->ordered) {
            $last = $b->number + max(0, $this->countChildren($i) - 1);
            [$w] = $c->measureText(0, new Span($last . $b->marker, $th->fontSize, font: $th->font !== '' ? $th->font : null));
            $width = max($width, $w + $th->em(0.5));
        }
        $col = $c->column()->role(Role::LIST);
        $n = $b->number;
        $first = true;
        for ($j = $i + 1; $j < $b->next; $j = $d->block($j)->next) {
            $item = $d->block($j);
            $row = $c->row()->alignItems(Node::START)->role(Role::LIST_ITEM);
            if (!$first) {
                $row->margin($th->em($b->tight ? $th->itemSpacing : $th->blockSpacing), 0, 0, 0);
            }
            $this->marker($b, $item, $n, $depth, $width);
            $content = $c->column()->shrink(1)->minWidth(0)->minHeight(0);
            $content->grow = 1;
            $this->blocks($j, $b->tight, $base);
            $c->end();
            $c->end();
            $n++;
            $first = false;
        }
        $c->end();
        return $col;
    }

    private function countChildren(int $i): int
    {
        $n = 0;
        for ($j = $i + 1; $j < $this->doc->block($i)->next; $j = $this->doc->block($j)->next) { $n++; }
        return $n;
    }

    /** Builds an item's marker: a bullet, its number, or its check box (mygo's marker). */
    private function marker(Block $list, Block $item, int $n, int $depth, float $width): void
    {
        $c = $this->c; $th = $this->th;
        $box = $c->row()->size($width, 0)->shrink(0)->unselectable();
        if ($this->fade !== null && ($a = ($this->fade)($item->start)) < 1) {
            $box->opacity($a);
        }
        if ($item->task !== Block::NO_TASK) {
            $size = $th->em(0.95);
            $lineH = $th->fontSize * $th->lineHeight * 1.2;
            $checked = $item->task === Block::CHECKED;
            $cb = $c->column()->size($size, $size)->radius(max(3, $size / 5))->margin(max(0, ($lineH - $size) / 2 - 1), 0, 0, 0)->role(Role::CHECK_BOX)->checked($checked);
            if ($checked) {
                $cb->background($th->checkbox[0], $th->checkbox[1])->draw(function (Painter $p, array $r) {
                    [$x, $y, $w, $h] = $r;
                    $path = (new Path())->moveTo($x + $w * 0.25, $y + $h * 0.52)->lineTo($x + $w * 0.43, $y + $h * 0.7)->lineTo($x + $w * 0.76, $y + $h * 0.32);
                    $p->strokePath($path, max(1.5, $w / 9), 0xffffff);
                });
            } else {
                $cb->borderWidth(1.5)->borderColor($th->muted[0]);
            }
            $c->end();
        } elseif ($list->ordered) {
            $c->text($n . $list->marker)->textColor($th->muted[0], $th->muted[1])->size($width - $th->em(0.4), 0)->textAlign(Node::END)->fontFeatures('tnum');
        } else {
            $c->text(['•', '◦', '▪'][min($depth, 2)])->textColor($th->muted[0], $th->muted[1])->size($width - $th->em(0.5), 0)->textAlign(Node::CENTER);
        }
        $c->end();
    }

    /** Numbers the footnotes by the order of their definitions (mygo's numberNotes). */
    private function numberNotes(): void
    {
        $this->notes = [];
        $n = 0;
        for ($i = 1; $i < $this->doc->len(); $i++) {
            $b = $this->doc->block($i);
            if ($b->kind === Block::FOOTNOTE_DEFINITION) {
                $key = Document::normalizeLabel($b->label());
                if (!isset($this->notes[$key])) {
                    $this->notes[$key] = ++$n;
                }
            }
        }
    }

    public function noteNumber(string $label): int { return $this->notes[Document::normalizeLabel($label)] ?? 0; }

    /** Builds the footnotes, below a rule, in order (mygo's footnotes). */
    private function footnotes(): void
    {
        if ($this->notes === []) {
            return;
        }
        $c = $this->c; $d = $this->doc; $th = $this->th;
        $c->column()->size(0, 1)->background($th->rule[0], $th->rule[1])->alignSelf(Node::STRETCH)->margin($th->em(1.2), 0, $th->em(0.6), 0);
        $c->end();
        $base = new SpanStyle(color: $th->muted, size: $th->em(0.875));
        for ($i = 1; $i < $d->len(); $i++) {
            $b = $d->block($i);
            if ($b->kind !== Block::FOOTNOTE_DEFINITION) {
                continue;
            }
            $num = $this->notes[Document::normalizeLabel($b->label())];
            $row = $c->row()->alignItems(Node::START)->margin($th->em(0.25), 0, 0, 0);
            if ($this->scrollTo === $i) {
                $row->scrollIntoView();
                $this->scrollTo = -1;
            }
            $c->text($num . '.')->textColor($th->muted[0], $th->muted[1])->fontSize($th->em(0.875))->size($th->em(1.6), 0)->shrink(0)->unselectable();
            $col = $c->column()->shrink(1)->minWidth(0)->minHeight(0)->textColor($th->muted[0], $th->muted[1]);
            $col->grow = 1;
            $this->blocks($i, false, $base);
            $c->end();
            $c->end();
        }
    }

    // Code blocks (mygo's code.go).

    public function codeState(int $i): CodeState
    {
        $b = $this->doc->block($i);
        $lang = $b->language();
        $cs = $this->codes[$b->start] ?? null;
        if ($cs === null || $cs->lang !== $lang) {
            $cs = $this->codes[$b->start] = new CodeState($lang);
        }
        return $cs;
    }

    /** Builds code block $i, through the hooks of the options (mygo's codeBlock). */
    private function codeBlock(int $i): Node
    {
        $c = $this->c;
        $b = $this->doc->block($i);
        $cb = new CodeBlockView($b->language(), $b->info(), $b->open, $i, $this);
        $f = $this->o->code[$cb->language] ?? $this->o->codeBlock;
        if ($f !== null) {
            $box = $c->column();
            $f($c, $cb);
            $c->end();
            return $box;
        }
        return Markdown::defaultCodeBlock($c, $cb);
    }

    /** Tokenizes the lines of code block $i that changed, and makes the spans of the chunks they are in anew (mygo's highlight). */
    public function highlight(CodeState $cs, int $i): void
    {
        $d = $this->doc;
        $n = $d->lineCount($i);
        $k0 = 0; // the first line that differs from those tokenized
        while ($k0 < $n && $k0 < count($cs->lines) && $d->line($i, $k0)[0] === $cs->lines[$k0]) { $k0++; }
        if ($k0 === $n && count($cs->lines) === $n && count($cs->chunks) === intdiv($n + CodeState::CHUNK_LINES - 1, CodeState::CHUNK_LINES)) {
            return;
        }
        $cs->lines = array_slice($cs->lines, 0, $k0); $cs->ends = array_slice($cs->ends, 0, $k0); $cs->toks = array_slice($cs->toks, 0, $k0);
        $state = $k0 > 0 ? $cs->ends[$k0 - 1] : 0;
        for ($k = $k0; $k < $n; $k++) {
            [$line] = $d->line($i, $k);
            $toks = [];
            if ($cs->tok !== null) {
                [$toks, $state] = $cs->tok->tokenize($line, $state, []);
            }
            $cs->lines[] = $line; $cs->ends[] = $state; $cs->toks[] = $toks;
        }
        $chunks = $n === 0 ? 1 : intdiv($n + CodeState::CHUNK_LINES - 1, CodeState::CHUNK_LINES);
        while (count($cs->chunks) < $chunks) { $cs->chunks[] = ['spans' => [], 'n' => 0, 'ok' => false]; }
        $cs->chunks = array_slice($cs->chunks, 0, $chunks);
        for ($k = intdiv($k0, CodeState::CHUNK_LINES); $k < $chunks; $k++) { $cs->chunks[$k]['ok'] = false; }
        $ct = $this->th->codeColors;
        foreach ($cs->chunks as $k => &$ch) {
            if ($ch['ok']) {
                continue;
            }
            $ch['spans'] = [];
            $first = $k * CodeState::CHUNK_LINES;
            $last = min($first + CodeState::CHUNK_LINES, $n);
            for ($l = $first; $l < $last; $l++) {
                if ($l > $first) { $ch['spans'][] = new Span("\n"); }
                $line = $cs->lines[$l];
                $start = 0;
                foreach ($cs->toks[$l] as [$end, $kind]) {
                    $end = min($end, strlen($line));
                    if ($end > $start) {
                        [$rgb, $a] = $ct->tokenColor($kind);
                        $sp = new Span(substr($line, $start, $end - $start), color: $rgb, alpha: $a);
                        $style = $ct->tokens[$kind] ?? ['bold' => false, 'italic' => false];
                        if ($style['bold']) { $sp->weight = 600; } elseif ($style['italic']) { $sp->italic = true; }
                        $ch['spans'][] = $sp;
                    }
                    $start = max($start, $end);
                }
                if ($start < strlen($line)) {
                    [$rgb, $a] = $ct->c['text'];
                    $ch['spans'][] = new Span(substr($line, $start), color: $rgb, alpha: $a);
                }
            }
            if ($ch['spans'] === []) { $ch['spans'][] = new Span(' '); }
            $ch['n'] = $last - $first;
            $ch['ok'] = true;
        }
        unset($ch);
    }

    // Tables (mygo's table.go).

    /** Builds a table: rows of cells as wide as their column's widest, up to Theme::$tableMaxColumn, which wrap beyond, scrolling sideways when wider than the view (mygo's table). */
    private function table(int $i, SpanStyle $base): Node
    {
        $c = $this->c; $d = $this->doc; $th = $this->th;
        $b = $d->block($i);
        $ts = $this->tables[$b->start] ?? ['widths' => []];
        $cols = count($b->align);
        $padX = $th->em($th->tableCellPadding[0]); $padY = $th->em($th->tableCellPadding[1]);
        $maxW = $th->em($th->tableMaxColumn);
        $widths = array_fill(0, $cols, 0.0); // the widths of the columns, from their cells' text
        if ($b->open) {
            foreach ($ts['widths'] as $k => $w) { if ($k < $cols) { $widths[$k] = $w; } }
        }
        for ($r = $i + 1; $r < $b->next; $r = $d->block($r)->next) {
            $row = $d->block($r);
            $k = 0;
            for ($cell = $r + 1; $cell < $row->next; $cell = $d->block($cell)->next) {
                if ($k >= $cols) { break; }
                $s = clone $base;
                if ($row->header) { $s->weight = $th->strongWeight; }
                $p = $this->para($cell, $s);
                if (!$p->measured) {
                    $p->width = 0; $p->measured = true;
                    foreach ($p->pieces as [, $spans]) {
                        [$pw] = $c->measureText(0, ...$spans);
                        $p->width += $pw;
                    }
                }
                $widths[$k] = max($widths[$k], min($p->width, $maxW) + 2 * $padX + 1);
                $k++;
            }
        }
        $this->tables[$b->start] = ['widths' => $widths];
        $scroll = $this->scrollHorizontal()->alignSelf(Node::STRETCH);
        $grid = $c->column()->borderWidth(1)->borderColor($th->tableBorder[0])->radius($th->em(0.3))->clip()->shrink(0)->role(Role::TABLE);
        $n = 0;
        for ($r = $i + 1; $r < $b->next; $r = $d->block($r)->next) {
            $row = $d->block($r);
            $rowEl = $c->row()->role(Role::ROW)->alignItems(Node::STRETCH);
            if ($row->header) {
                $rowEl->background($th->tableHeader[0], $th->tableHeader[1]);
            } elseif ($n % 2 === 1 && $th->tableStripe !== null && $th->tableStripe[1] > 0) {
                $rowEl->background($th->tableStripe[0], $th->tableStripe[1]);
            }
            if ($n > 0) {
                $rowEl->borderWidth(1, 0, 0, 0)->borderColor($th->tableBorder[0]);
            }
            if ($this->fade !== null && $b->open && ($a = ($this->fade)($row->start)) < 1) {
                $rowEl->opacity($a);
            }
            $k = 0;
            for ($cell = $r + 1; $cell < $row->next; $cell = $d->block($cell)->next) {
                if ($k >= $cols) { break; }
                $s = clone $base;
                $role = Role::CELL;
                if ($row->header) { $s->weight = $th->strongWeight; $role = Role::COLUMN_HEADER; }
                $box = $c->column()->size($widths[$k], 0)->padding($padY, $padX)->role($role);
                if ($k > 0) {
                    $box->borderWidth(0, 0, 0, 1)->borderColor($th->tableBorder[0]);
                }
                $align = match ($b->align[$k]) { Block::ALIGN_CENTER => Node::CENTER, Block::ALIGN_RIGHT => Node::END, default => Node::START };
                $this->paragraph($cell, $s)->textAlign($align);
                $c->end();
                $k++;
            }
            $c->end();
            $n++;
        }
        $c->end();
        $c->end();
        return $scroll;
    }

    // Images (mygo's image.go).

    /**
     * The image at $url once loaded, starting to load it the first time
     * (mygo's image): in a fiber of its own (App::go), where Image's
     * decoding waits off the main thread; the window draws again once it
     * is done.
     */
    private function image(string $url): \MyPHP\UI\Image|SVG|null
    {
        $o = $this->o;
        if ($o->loadImage === null || $url === '') {
            return null;
        }
        if (!isset($o->images[$url])) {
            $o->images[$url] = [false, null];
            $frame = $this->c->runtime?->requestFrame;
            App::go(function () use ($o, $url, $frame) {
                try {
                    $img = ($o->loadImage)($url);
                } catch (\Throwable) {
                    $img = null;
                }
                $o->images[$url] = [true, $img];
                if ($frame !== null) {
                    $frame();
                }
            });
        }
        return $o->images[$url][1];
    }

    /** Builds a paragraph of images alone: each as large as it is, at most as wide as the view, its description showing until it loads (mygo's images). */
    private function images(int $i): Node
    {
        $c = $this->c; $th = $this->th;
        $in = $this->doc->inlines($i) ?? [];
        $row = $c->row(0, $th->em(0.5))->alignSelf(Node::STRETCH);
        $room = $c->runtime?->widthOf($row->id) ?? $c->width;
        $row->wrap($room);
        for ($k = 0; $k < count($in); $k = $in[$k]->next) {
            $n = $in[$k];
            if ($n->kind !== Inline::IMAGE) {
                continue;
            }
            $alt = Inline::plainText(array_slice($in, $k + 1, $n->next - $k - 1));
            $src = $this->image($n->dest);
            if ($src === null || $n->pending) {
                $ph = $c->row()->padding($th->em(0.5), $th->em(0.75))->radius($th->em(0.3))->background($th->codeBackground[0], $th->codeBackground[1])->role(Role::IMAGE)->label($alt);
                $c->text($alt !== '' ? $alt : $c->texts()->get('image'))->textColor($th->muted[0], $th->muted[1])->fontSize($th->em(0.875));
                $c->end();
                continue;
            }
            [$w, $h] = $src instanceof SVG ? [$src->w, $src->h] : [$src->w / $c->scale, $src->h / $c->scale];
            $img = $c->image($src, $w, $h, $th->em(0.3))->maxWidthPercent(100)->label($alt); // at most as wide as the view, keeping its aspect (mygo's)
            if ($w > 0 && $h > 0) {
                $img->aspectRatio($w / $h);
                $img->fh = 0.0; // its height follows its width
            }
            if ($n->title !== '') {
                $img->tooltip($n->title);
            }
        }
        $c->end();
        return $row;
    }

    /** Whether the inlines are images, and the spaces between them, alone: a paragraph showing them as images (mygo's imagesOnly). @param list<Inline> $in */
    public static function imagesOnly(array $in): bool
    {
        $n = 0;
        for ($k = 0; $k < count($in); $k = $in[$k]->next) {
            switch ($in[$k]->kind) {
                case Inline::IMAGE: $n++; break;
                case Inline::SOFT_BREAK: case Inline::LINE_BREAK: break;
                case Inline::TEXT:
                    if (trim($in[$k]->text) !== '') { return false; }
                    break;
                default:
                    return false;
            }
        }
        return $n > 0;
    }

    // Copying (mygo's copy.go): as text, a view joins its paragraphs with blank lines, a code block's lines and a tight list's items with newlines and a table's cells with tabs; as Markdown, it copies the source between the selection's ends, with the syntax of emphasis, code and links cut by them completed.

    /** @return ?array{0: int, 1: int, 2: int} */
    private function elem(int $id): ?array
    {
        foreach ($this->elems as $e) {
            if ($e[0] === $id) { return $e; }
        }
        return null;
    }

    /** The text of the selected parts (mygo's copyText). @param list<SelectedText> $parts */
    public function copyText(array $parts): string
    {
        $out = ''; $prev = null;
        foreach ($parts as $k => $p) {
            $inf = $this->elem($p->id);
            if ($k > 0) {
                $out .= $inf !== null && $prev !== null ? $this->separator($prev, $inf) : "\n";
            }
            $out .= $inf !== null ? $this->selectedText($inf, $p) : mb_substr($p->text, $p->start, max(0, $p->end - $p->start), 'UTF-8');
            $prev = $inf;
        }
        return $out;
    }

    /** The text of selected part $p of a node $inf tells of, with the source of the math drawn as objects, as $x_i$, in place of their characters (mygo's writeText). @param array{0: int, 1: int, 2: int} $inf */
    private function selectedText(array $inf, SelectedText $p): string
    {
        $text = mb_substr($p->text, $p->start, max(0, $p->end - $p->start), 'UTF-8');
        $objects = $inf[2] === -1 ? ($this->paras[$inf[1]]['p'] ?? null)?->objects ?? [] : [];
        if ($objects === [] || !str_contains($text, "\u{FFFC}")) {
            return $text;
        }
        $src = $this->doc->source();
        $out = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $k => $ch) {
            $o = $ch === "\u{FFFC}" ? self::objectAt($objects, $p->start + $k) : null;
            $out .= $o !== null && $objects[$o][2] <= strlen($src) ? substr($src, $objects[$o][1], $objects[$o][2] - $objects[$o][1]) : $ch;
        }
        return $out;
    }

    /** The selection as Copy puts it on the clipboard: as text, as HTML and as Markdown (mygo's copyData). @param list<SelectedText> $parts */
    public function copyData(array $parts): Data
    {
        $md = $this->copyMarkdown($parts);
        $d = new Document($this->doc->extensions());
        $d->set($md);
        return new Data(new Item(
            Representation::bytes(Format::TEXT, $this->copyText($parts)),
            Representation::bytes(Format::HTML, '<meta charset="utf-8">' . $d->html()),
            Representation::bytes(Format::MARKDOWN, $md),
        ));
    }

    /** Markdown as Copy as Markdown puts it on the clipboard: as text, and as Markdown (mygo's markdownData). */
    public static function markdownData(string $md): Data
    {
        return new Data(new Item(Representation::bytes(Format::TEXT, $md), Representation::bytes(Format::MARKDOWN, $md)));
    }

    /** What goes between the text of two blocks copied (mygo's separator). @param array{0: int, 1: int, 2: int} $a @param array{0: int, 1: int, 2: int} $b */
    private function separator(array $a, array $b): string
    {
        $d = $this->doc;
        if ($a[1] === $b[1]) {
            return "\n";
        }
        $ab = $d->block($a[1]); $bb = $d->block($b[1]);
        if ($ab->kind === Block::TABLE_CELL && $bb->kind === Block::TABLE_CELL) {
            return $ab->parent === $bb->parent ? "\t" : "\n";
        }
        $l = $this->listOf($b[1]); $la = $this->listOf($a[1]);
        if (($l > 0 && $d->block($l)->tight && $la === $l) || ($la > 0 && $d->block($la)->tight && $this->within($b[1], $la))) { // items of the same tight list
            return "\n";
        }
        return "\n\n";
    }

    /** The nearest list around block $i, or 0. */
    private function listOf(int $i): int
    {
        for ($p = $this->doc->block($i)->parent; $p > 0; $p = $this->doc->block($p)->parent) {
            if ($this->doc->block($p)->kind === Block::LIST) { return $p; }
        }
        return 0;
    }

    private function within(int $i, int $anc): bool
    {
        for ($p = $this->doc->block($i)->parent; $p >= 0; $p = $this->doc->block($p)->parent) {
            if ($p === $anc) { return true; }
        }
        return false;
    }

    /** The source offset of rune $r of a node's text, for the end of a selection when $end (mygo's srcAt). @param array{0: int, 1: int, 2: int} $inf */
    private function srcAt(array $inf, int $r, bool $end): int
    {
        $d = $this->doc;
        $b = $d->block($inf[1]);
        $src = $d->source();
        if (in_array($b->kind, [Block::CODE_BLOCK, Block::HTML_BLOCK, Block::MATH_BLOCK], true)) { // lines from the node's on, each followed by a newline
            $n = $d->lineCount($inf[1]);
            for ($k = max($inf[2], 0); $k < $n; $k++) {
                [$line, $at] = $d->line($inf[1], $k);
                $cnt = mb_strlen($line, 'UTF-8');
                if ($r <= $cnt || $k === $n - 1) {
                    $pad = self::linePad($src, $line, $at); // spaces standing for a tab partly taken by indentation are not the source's
                    $off = $at;
                    for ($j = $pad; $j < $r && $off < strlen($src); $j++) {
                        $c = ord($src[$off]);
                        $off += $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
                    }
                    return $off;
                }
                $r -= $cnt + 1;
            }
            return $b->start;
        }
        if (isset($this->paras[$inf[1]])) {
            return $this->paras[$inf[1]]['p']->sourceAt($src, $r, $end);
        }
        return $b->start;
    }

    /** The Markdown of the selected parts, or of the whole document when none is selected (mygo's copyMarkdown). @param list<SelectedText> $parts */
    public function copyMarkdown(array $parts): string
    {
        $d = $this->doc;
        $src = $d->source();
        if ($parts === []) {
            return $src;
        }
        $first = $parts[0]; $last = $parts[count($parts) - 1];
        $fi = $this->elem($first->id); $li = $this->elem($last->id);
        if ($fi === null || $li === null) {
            return $this->copyText($parts);
        }
        $fb = $d->block($fi[1]); $lb = $d->block($li[1]);
        $a = $this->srcAt($fi, $first->start, false);
        $b = $this->srcAt($li, $last->end, true);
        $atStart = $first->start === 0 && $fi[2] <= 0;
        $atEnd = $last->end >= mb_strlen($last->text, 'UTF-8');
        if ($lb->kind === Block::CODE_BLOCK && $atEnd) { // only the last chunk of lines ends the block
            $atEnd = $li[2] + CodeState::CHUNK_LINES >= $d->lineCount($li[1]);
        }
        // From a block's start, or to its end, its syntax too: a heading's #, a list's marker, a quote's >, a table row's pipes, a code block's fences when it is selected whole.
        if ($atStart) {
            if ($fb->kind === Block::TABLE_CELL) {
                if ($fi[1] === $fb->parent + 1 && $li[1] !== $fi[1]) { $a = self::lineStartOf($src, $fb->start); } // a row's pipes go with it when cells after it are selected too
            } elseif ($fb->kind === Block::CODE_BLOCK) {
                if ($fb->fenced && $atEnd && $fi[1] === $li[1]) { $a = self::lineStartOf($src, $fb->start); }
            } else {
                $a = self::lineStartOf($src, $fb->start);
            }
        }
        if ($atEnd) {
            if ($lb->kind === Block::TABLE_CELL) {
                if ($lb->next === $d->block($lb->parent)->next && $li[1] !== $fi[1]) { $b = self::lineEndOf($src, $lb->end); }
            } elseif ($lb->kind === Block::CODE_BLOCK) {
                if ($lb->fenced && !$lb->open && $atStart && $fi[1] === $li[1]) { $b = $lb->end; }
            } else {
                $b = self::lineEndOf($src, $lb->end);
            }
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }
        return $this->openAt($fi[1], $a) . substr($src, $a, $b - $a) . $this->closeAt($li[1], $b);
    }

    private static function lineEndOf(string $src, int $off): int
    {
        while ($off < strlen($src) && $src[$off] !== "\n" && $src[$off] !== "\r") { $off++; }
        return $off;
    }

    private static function lineStartOf(string $src, int $off): int
    {
        while ($off > 0 && $src[$off - 1] !== "\n" && $src[$off - 1] !== "\r") { $off--; }
        return $off;
    }

    /** The opening syntax of the inline spans of block $i that offset $at falls inside, outermost first (mygo's openAt). */
    private function openAt(int $i, int $at): string
    {
        $in = $this->doc->inlines($i) ?? [];
        $out = '';
        foreach ($in as $k => $n) {
            if ($n->start < $at && $at < $n->end) { $out .= $this->openSyntax($in, $k); }
        }
        return $out;
    }

    /** The closing syntax of the inline spans of block $i that offset $at falls inside, innermost first (mygo's closeAt). */
    private function closeAt(int $i, int $at): string
    {
        $in = $this->doc->inlines($i) ?? [];
        $closes = [];
        foreach ($in as $k => $n) {
            if ($n->start < $at && $at < $n->end && ($c = $this->closeSyntax($in, $k)) !== '') { $closes[] = $c; }
        }
        return implode('', array_reverse($closes));
    }

    /** @param list<Inline> $in */
    private function openSyntax(array $in, int $k): string
    {
        $n = $in[$k];
        $src = $this->doc->source();
        switch ($n->kind) {
            case Inline::EMPHASIS: case Inline::STRONG: case Inline::STRIKETHROUGH: case Inline::LINK:
                return $k + 1 < $n->next ? substr($src, $n->start, $in[$k + 1]->start - $n->start) : '';
            case Inline::CODE:
                return substr($src, $n->start, SpanBuilder::codeStart($src, $n) - $n->start);
        }
        return '';
    }

    /** @param list<Inline> $in */
    private function closeSyntax(array $in, int $k): string
    {
        $n = $in[$k];
        $src = $this->doc->source();
        switch ($n->kind) {
            case Inline::EMPHASIS: case Inline::STRONG: case Inline::STRIKETHROUGH: case Inline::LINK:
                if ($k + 1 < $n->next) { // the end of its last child
                    $end = $in[$k + 1]->end;
                    for ($j = $k + 1; $j < $n->next; $j = $in[$j]->next) { $end = $in[$j]->end; }
                    return substr($src, $end, $n->end - $end);
                }
                return '';
            case Inline::CODE:
                $ticks = SpanBuilder::codeStart($src, $n) - $n->start;
                return substr($src, $n->end - $ticks, $ticks);
        }
        return '';
    }

    /** How many spaces a code line starts with that are not the source's at $at, standing for a tab partly taken by indentation (mygo's linePad). */
    private static function linePad(string $src, string $line, int $at): int
    {
        $pad = 0;
        while ($pad < strlen($line) && $line[$pad] === ' ' && !str_starts_with(substr($src, $at), substr($line, $pad))) { $pad++; }
        return $pad;
    }
}
