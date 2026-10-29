<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;
use Generator;
use MyPHP\UI\Editor\Rope;
use MyPHP\UI\Editor\RopeNode;
use MyPHP\UI\Editor\RopeText;

/**
 * Text whose runs have attributes of their own, in paragraphs that have a
 * style of their own (mygo's richtext.Text): what an Editor edits, as
 * Foundation's AttributedString is what SwiftUI's TextEditor edits. It is
 * a value: methods return a new text and leave the one they are called on
 * as it was, sharing what did not change with it, so that keeping a
 * version (for undo, to save it later, to compare) costs a pointer.
 * `new Text` is the empty text.
 *
 * Offsets count runes, newlines included, as text selections do.
 * Paragraphs end at newlines; the style of a paragraph and the attributes
 * of its newline belong to it, as in word processors, so that an empty
 * paragraph remembers the attributes typing gets there. Runs are kept as
 * long as they can be: neighbors with the same attributes are one run.
 */
final class Text
{
    /** The character an Attachment takes in a text (mygo's ObjectReplacement). */
    public const OBJECT_REPLACEMENT = "\u{FFFC}";

    private static ?Seq $emptyParas = null;

    public function __construct(
        /** @internal */
        public readonly ?RopeNode $text = null,
        /** @internal */
        public readonly ?Seq $paras = null,
    ) {}

    /** @internal the paragraphs, those of the empty text for the zero value */
    public function seq(): Seq
    {
        return $this->paras ?? (self::$emptyParas ??= Seq::leaf([new RichPara(0, new ParagraphStyle, 0, [[1, new Attributes]])]));
    }

    /** $s as a Text, without attributes (mygo's NewText). */
    public static function new(string $s): Text
    {
        return self::of(new Paragraph(runs: [new Run($s)]));
    }

    /**
     * A Text of paragraphs (mygo's TextOf). A newline in a run starts
     * another paragraph of the same style, without its ID.
     *
     *     Text::of(
     *         new Paragraph(new ParagraphStyle(heading: 1), [new Run('Groceries')]),
     *         new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Milk')]),
     *     );
     */
    public static function of(Paragraph ...$paragraphs): Text
    {
        if ($paragraphs === []) { return new Text; }
        $lines = [];
        $paras = [];
        foreach ($paragraphs as $p) {
            $line = '';
            $runs = [];
            $last = new Attributes;
            $style = null; // the paragraph's, with its ID, until a newline starts another
            foreach ($p->runs as $r) {
                $last = $r->attributes;
                $parts = explode("\n", $r->text);
                foreach ($parts as $k => $part) {
                    if ($k > 0) {
                        $lines[] = $line;
                        $paras[] = RichPara::new($style ?? $p->style, RichPara::appendRun($runs, 1, $r->attributes->extending()));
                        $line = '';
                        $runs = [];
                        $style = $p->style->withID('');
                    }
                    $line .= $part;
                    $runs = RichPara::appendRuns($runs, $part, $r->attributes);
                }
            }
            $lines[] = $line;
            $paras[] = RichPara::new($style ?? $p->style, RichPara::appendRun($runs, 1, $last->extending()));
        }
        return new Text(Rope::build(implode("\n", $lines)), Seq::build($paras));
    }

    /** The number of runes of the text, newlines included. */
    public function len(): int { return Rope::sum($this->text)->runes; }

    /** The text without its attributes. */
    public function string(): string { return (string)new RopeText($this->text); }

    public function __toString(): string { return $this->string(); }

    /** The number of paragraphs: one more than the newlines. */
    public function paragraphCount(): int { return Rope::sum($this->text)->lines + 1; }

    /** Whether $u is the same version of a text, without comparing them: a text is the same as what it was copied from. */
    public function same(Text $u): bool { return $this->text === $u->text && $this->paras === $u->paras; }

    /** Whether $u holds the same text with the same attributes and paragraph styles. */
    public function equal(Text $u): bool
    {
        if ($this->same($u)) { return true; }
        if ($this->len() !== $u->len() || $this->paragraphCount() !== $u->paragraphCount() || $this->string() !== $u->string()) { return false; }
        $equal = true;
        $us = $u->seq();
        Seq::each($this->seq(), 0, function (int $i, RichPara $p) use ($us, &$equal): bool {
            $q = Seq::at($us, $i);
            $equal = $p->style->equals($q->style) && RichPara::runsEqual($p->runs, $q->runs);
            return $equal;
        });
        return $equal;
    }

    /** @internal the paragraph holding rune $i, whose newline is its */
    public function paraAt(int $i): int
    {
        $i = max(0, min($i, $this->len()));
        return Rope::prefix($this->text, Rope::byteOfRune($this->text, $i))->lines;
    }

    /** @internal the rune paragraph $p starts at */
    public function paraStart(int $p): int
    {
        if ($p <= 0) { return 0; }
        return Rope::prefix($this->text, Rope::lineStart($this->text, $p))->runes;
    }

    /** @internal paragraph $p */
    public function para(int $p): RichPara { return Seq::at($this->seq(), $p); }

    /** Where the paragraph holding rune $i starts and ends, before its newline. @return array{0: int, 1: int} */
    public function paragraphRange(int $i): array
    {
        $p = $this->paraAt($i);
        $start = $this->paraStart($p);
        return [$start, $start + $this->para($p)->n];
    }

    /** The attributes of rune $i, or those of the end of the text for its length. */
    public function attributesAt(int $i): Attributes
    {
        return clone $this->attrsAt($i);
    }

    /** @internal attributesAt without the copy, for what only reads them */
    public function attrsAt(int $i): Attributes
    {
        $p = $this->paraAt($i);
        return $this->para($p)->attributesAt($i - $this->paraStart($p));
    }

    /** The style of the paragraph holding rune $i. */
    public function paragraphStyleAt(int $i): ParagraphStyle
    {
        return clone $this->para($this->paraAt($i))->style;
    }

    /**
     * The paragraphs of the text, keyed by the rune each starts at.
     *
     * @return Generator<int, Paragraph>
     */
    public function paragraphs(): Generator
    {
        $tx = new RopeText($this->text);
        $s = $this->seq();
        $start = 0;
        for ($i = 0, $n = Seq::len($s); $i < $n; $i++) {
            $p = Seq::at($s, $i);
            $line = $tx->line($i);
            $par = new Paragraph(clone $p->style);
            $off = 0;
            foreach ($p->runs as $r) {
                if ($off >= $p->n) { break; }
                $k = min($r[0], $p->n - $off);
                $par->runs[] = new Run(mb_substr($line, $off, $k, 'UTF-8'), clone $r[1]);
                $off += $k;
            }
            yield $start => $par;
            $start += $p->n + 1;
        }
    }

    /**
     * The runs of the text from rune $start to rune $end, keyed by the rune
     * each starts at: the newline ending a paragraph is a run of its own,
     * or ends the paragraph's last run when it has the same attributes.
     *
     * @return Generator<int, Run>
     */
    public function runs(int $start, int $end): Generator
    {
        $n = $this->len();
        $start = max(0, min($start, $n)); $end = max(0, min($end, $n));
        if ($start >= $end) { return; }
        $tx = new RopeText($this->text);
        $s = $this->seq();
        $pa = $this->paraAt($start);
        $at = $this->paraStart($pa);
        for ($i = $pa, $count = Seq::len($s); $i < $count; $i++) {
            foreach (Seq::at($s, $i)->runs as $r) {
                $a = max($at, $start); $z = min($at + $r[0], $end, $n);
                if ($a < $z) {
                    yield $a => new Run($tx->slice(Rope::byteOfRune($this->text, $a), Rope::byteOfRune($this->text, $z)), clone $r[1]);
                }
                $at += $r[0];
                if ($at >= $end) { return; }
            }
        }
    }

    /** The runes from $start to $end with their attributes and the styles of their paragraphs. */
    public function slice(int $start, int $end): Text
    {
        $n = $this->len();
        $start = max(0, min($start, $n)); $end = max(0, min($end, $n));
        if ($start >= $end) { return new Text; }
        $pa = $this->paraAt($start); $pz = $this->paraAt($end);
        $sa = $this->paraStart($pa); $sz = $this->paraStart($pz);
        $paras = [];
        Seq::each($this->seq(), $pa, function (int $i, RichPara $p) use ($pa, $pz, $start, $end, $sa, $sz, &$paras): bool {
            $from = 0; $to = $p->n + 1;
            if ($i === $pa) { $from = $start - $sa; }
            $last = $i === $pz;
            if ($last) { $to = $end - $sz; }
            $runs = RichPara::cutRuns([], $p->runs, $from, $to);
            $k = $to - $from - 1;
            if ($last) {
                $runs = RichPara::appendRun($runs, 1, $p->end());
                $k++;
            }
            $paras[] = new RichPara($p->id, $p->style, $k, $runs);
            return !$last;
        });
        [, $rest] = Rope::split($this->text, Rope::byteOfRune($this->text, $start));
        [$mid] = Rope::split($rest, Rope::byteOfRune($rest, $end - $start));
        return new Text($mid, Seq::build($paras));
    }

    /**
     * The text with the runes from $start to $end replaced by $with.
     * Paragraphs joined or split take the style of the one their first rune
     * comes from, as AppKit fixes paragraph styles: deleting the newline
     * after a heading keeps the heading, and text put at the start of a
     * paragraph brings its own paragraph's style.
     */
    public function replace(int $start, int $end, Text $with): Text
    {
        $n = $this->len();
        [$start, $end] = [max(0, min($start, $end, $n)), max(0, min(max($start, $end), $n))];
        $pa = $this->paraAt($start); $pz = $this->paraAt($end);
        $sa = $this->paraStart($pa); $sz = $this->paraStart($pz);
        $s = $this->seq();
        $first = Seq::at($s, $pa); $last = Seq::at($s, $pz);
        $head = RichPara::cutRuns([], $first->runs, 0, $start - $sa);
        $tail = RichPara::cutRuns([], $last->runs, $end - $sz, $last->n + 1);
        $headN = $start - $sa; $tailN = $last->n - ($end - $sz);

        $frag = [];
        Seq::each($with->seq(), 0, function (int $i, RichPara $p) use (&$frag): bool {
            $frag[] = $p;
            return true;
        });
        $out = [];
        if (count($frag) === 1) {
            $f = $frag[0];
            $runs = RichPara::cutRuns($head, $f->runs, 0, $f->n);
            $runs = RichPara::cutRuns($runs, $tail, 0, $tailN + 1);
            $style = $last->style;
            if ($headN > 0) {
                $style = $first->style;
            } elseif ($f->n > 0) {
                $style = $f->style;
            }
            $style = $style->withID($headN > 0 || $f->n > 0 ? $first->style->id : $last->style->id); // joined, the first's, unless nothing of it is left
            $out[] = new RichPara($first->id, $style, $headN + $f->n + $tailN, $runs);
        } else {
            $moved = $pa === $pz && $headN === 0; // split at its start, the paragraph's text goes to the last part, with its ID
            $f = $frag[0];
            $style = ($headN > 0 ? $first->style : $f->style)->withID($moved ? '' : $first->style->id);
            $out[] = new RichPara($first->id, $style, $headN + $f->n, RichPara::cutRuns($head, $f->runs, 0, $f->n + 1));
            for ($k = 1, $m = count($frag) - 1; $k < $m; $k++) {
                $out[] = new RichPara(RichPara::nextID(), $frag[$k]->style->withID(''), $frag[$k]->n, $frag[$k]->runs);
            }
            $f = $frag[count($frag) - 1];
            $style = ($f->n > 0 ? $f->style : $last->style)->withID($pz !== $pa ? $last->style->id : ($moved ? $first->style->id : ''));
            $id = RichPara::nextID();
            if ($pz !== $pa) { $id = $last->id; }
            $runs = RichPara::cutRuns([], $f->runs, 0, $f->n);
            $runs = RichPara::cutRuns($runs, $tail, 0, $tailN + 1);
            $out[] = new RichPara($id, $style, $f->n + $tailN, $runs);
        }
        [$left, $rest] = Rope::split($this->text, Rope::byteOfRune($this->text, $start));
        [, $right] = Rope::split($rest, Rope::byteOfRune($rest, $end - $start));
        return new Text(Rope::join(Rope::join($left, $with->text), $right), Seq::replace($s, $pa, $pz + 1, $out));
    }

    /**
     * The text with the runes from $start to $end replaced by $s, which
     * takes attributes $a and, for the paragraphs it adds, the style of the
     * paragraph holding $start.
     */
    public function replaceString(int $start, int $end, string $s, Attributes $a): Text
    {
        $style = $this->para($this->paraAt($start))->style;
        $frag = [];
        foreach (explode("\n", $s) as $line) {
            $runs = RichPara::appendRuns([], $line, $a);
            $frag[] = new RichPara(0, $style, mb_strlen($line, 'UTF-8'), RichPara::appendRun($runs, 1, $a->extending()));
        }
        return $this->replace($start, $end, new Text(Rope::build($s), Seq::build($frag)));
    }

    /** The text with $with inserted at rune $i. */
    public function insert(int $i, Text $with): Text { return $this->replace($i, $i, $with); }

    /** The text without the runes from $start to $end. */
    public function delete(int $start, int $end): Text { return $this->replace($start, $end, new Text); }

    /** The text followed by $s with attributes $a. */
    public function append(string $s, Attributes $a): Text
    {
        $n = $this->len();
        return $this->replaceString($n, $n, $s, $a);
    }

    /**
     * The text with $fn changing the attributes of the runes from $start to
     * $end, newlines included. $fn gets a copy to change, and cannot add or
     * take away an attachment, which only an object replacement character
     * has.
     *
     * @param Closure(Attributes): void $fn
     */
    public function format(int $start, int $end, Closure $fn): Text
    {
        $n = $this->len();
        $start = max(0, min($start, $n)); $end = max(0, min($end, $n));
        if ($start >= $end) { return $this; }
        $pa = $this->paraAt($start); $pz = $this->paraAt($end);
        if ($end === $this->paraStart($pz) && $pz > $pa && $end < $n) {
            $pz--; // up to the newline ending the paragraph before
        }
        $at = $this->paraStart($pa);
        $out = [];
        $changed = false;
        Seq::each($this->seq(), $pa, function (int $i, RichPara $p) use ($start, $end, $n, $pz, $fn, &$at, &$out, &$changed): bool {
            $from = max($start - $at, 0); $to = min($end - $at, $p->n + 1);
            if ($end === $n && $i === $pz) {
                $to = $p->n + 1; // to the end of the text: what typing gets there too
            }
            $runs = RichPara::cutRuns([], $p->runs, 0, $from);
            foreach (RichPara::cutRuns([], $p->runs, $from, $to) as [$k, $a]) {
                $b = clone $a;
                $fn($b);
                $b->attachment = $a->attachment;
                if ($b->equals($a)) {
                    $b = $a;
                } else {
                    $changed = true;
                }
                $runs = RichPara::appendRun($runs, $k, $b);
            }
            $runs = RichPara::cutRuns($runs, $p->runs, $to, $p->n + 1);
            $out[] = new RichPara($p->id, $p->style, $p->n, $runs);
            $at += $p->n + 1;
            return $i < $pz;
        });
        if (!$changed) { return $this; }
        return new Text($this->text, Seq::replace($this->seq(), $pa, $pz + 1, $out));
    }

    /**
     * The text with $fn changing the styles of the paragraphs from the one
     * holding $start to the one holding $end, but for a paragraph $end only
     * starts, after a selection of whole paragraphs. $fn gets a copy to
     * change.
     *
     * @param Closure(ParagraphStyle): void $fn
     */
    public function formatParagraphs(int $start, int $end, Closure $fn): Text
    {
        [$pa, $pz] = $this->paragraphSpan($start, $end);
        return $this->formatParas($pa, $pz, $fn);
    }

    /** The text with $fn changing the styles of paragraphs $pa to $pz (mygo's formatParas). @internal Document @param Closure(ParagraphStyle): void $fn */
    public function formatParas(int $pa, int $pz, Closure $fn): Text
    {
        $out = [];
        $changed = false;
        Seq::each($this->seq(), $pa, function (int $i, RichPara $p) use ($pz, $fn, &$out, &$changed): bool {
            $st = clone $p->style;
            $fn($st);
            $st = $st->normalized();
            if ($st->equals($p->style)) {
                $st = $p->style;
            } else {
                $changed = true;
            }
            $out[] = new RichPara($p->id, $st, $p->n, $p->runs);
            return $i < $pz;
        });
        if (!$changed) { return $this; }
        return new Text($this->text, Seq::replace($this->seq(), $pa, $pz + 1, $out));
    }

    /**
     * The paragraphs a selection from $start to $end formats: those it
     * touches, but for the one after a selection ending at its start.
     *
     * @internal
     * @return array{0: int, 1: int}
     */
    public function paragraphSpan(int $start, int $end): array
    {
        $n = $this->len();
        [$start, $end] = [max(0, min($start, $end, $n)), max(0, min(max($start, $end), $n))];
        $pa = $this->paraAt($start); $pz = $this->paraAt($end);
        if ($end > $start && $pz > $pa && $end === $this->paraStart($pz)) { $pz--; }
        return [$pa, $pz];
    }

    /**
     * The runes around $i that link to the same URL as the rune at $i, or
     * [$i, $i].
     *
     * @internal
     * @return array{0: int, 1: int}
     */
    public function linkRange(int $i): array
    {
        $url = $this->attrsAt($i)->link;
        if ($url === '' || $i >= $this->len()) { return [$i, $i]; }
        [$ps, $pe] = $this->paragraphRange($i);
        $a = $i; $z = $i;
        while ($a > $ps && $this->attrsAt($a - 1)->link === $url) { $a--; }
        while ($z < $pe && $this->attrsAt($z)->link === $url) { $z++; }
        return [$a, $z];
    }

    /**
     * The text as CommonMark with GitHub's extensions: headings, lists and
     * task lists, quotations, fenced blocks of code, **bold**, *italic*,
     * ~~strikethrough~~, `code`, links and images; underlines are HTML's
     * <u>, which Markdown keeps. Body paragraphs are apart by a blank line,
     * so that they stay paragraphs; alignment, fonts and colors have no
     * Markdown and are left out.
     */
    public function markdown(): string { return MarkdownWriter::write($this); }

    /**
     * The text as HTML: paragraphs, headings, lists (task lists with check
     * boxes), quotations and blocks of code, and runs in the tags and styles
     * of their attributes, as browsers, mail and word processors paste it.
     */
    public function html(): string { return HtmlWriter::write($this); }

    /** The text as plain text, its attachments left out. */
    public function plainText(): string { return str_replace(self::OBJECT_REPLACEMENT, '', $this->string()); }

    /**
     * The text as JSON, as the clipboard's RichText::FORMAT holds it, to
     * save it: paragraphs of a style each, of runs of text and attributes.
     * Attachments keep their names, URLs and sizes; images and drawings are
     * the app's to save.
     */
    public function marshalJSON(): string
    {
        return json_encode(Clip::of($this, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** The text marshalJSON() wrote; throws JsonException on what is not JSON. */
    public static function unmarshalJSON(string $b): Text
    {
        $c = json_decode($b, true, 64, JSON_THROW_ON_ERROR);
        return is_array($c) ? Clip::text($c) : new Text;
    }
}
