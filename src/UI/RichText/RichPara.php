<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * A paragraph of a Text (mygo's richPara). Its runs hold its runes and,
 * last, its end: its newline, or for the last paragraph the end of the
 * text, whose attributes are those typing gets in an empty paragraph.
 * They are as long as they can be, none empty: $n + 1 runes in all. A
 * paragraph in a text never changes; a change makes another.
 *
 * @internal
 */
final class RichPara
{
    /** Numbers the paragraphs of every text, so that a paragraph keeps its identity as the text changes around it, for layouts. */
    private static int $ids = 0;

    /** @param list<array{0: int, 1: Attributes}> $runs runes and their attributes */
    public function __construct(
        public int $id,
        public ParagraphStyle $style,
        /** runes, without the end */
        public int $n,
        public array $runs,
    ) {}

    public static function nextID(): int { return ++self::$ids; }

    /** A paragraph of a new identity, of $runs. @param list<array{0: int, 1: Attributes}> $runs */
    public static function new(ParagraphStyle $style, array $runs): RichPara
    {
        $n = -1;
        foreach ($runs as $r) { $n += $r[0]; }
        return new RichPara(self::nextID(), $style->normalized(), $n, $runs);
    }

    /** The attributes of rune $i of the paragraph, its end for its length. */
    public function attributesAt(int $i): Attributes
    {
        $at = 0;
        foreach ($this->runs as $r) {
            $at += $r[0];
            if ($i < $at) { return $r[1]; }
        }
        return $this->runs[count($this->runs) - 1][1];
    }

    /** The attributes of the end: the newline, or the end of the text. */
    public function end(): Attributes { return $this->runs[count($this->runs) - 1][1]; }

    /**
     * Appends run $r to $runs, joining it to the last run when they have the
     * same attributes: runs holding an attachment are never joined.
     *
     * @param list<array{0: int, 1: Attributes}> $runs
     * @return list<array{0: int, 1: Attributes}>
     */
    public static function appendRun(array $runs, int $n, Attributes $a): array
    {
        if ($n <= 0) { return $runs; }
        $k = count($runs) - 1;
        if ($k >= 0 && $a->attachment === null && $runs[$k][1]->equals($a)) {
            $runs[$k][0] += $n;
            return $runs;
        }
        $runs[] = [$n, $a];
        return $runs;
    }

    /**
     * Appends the runs of $s with attributes $a: an object replacement
     * character is a run of its own, where $a holds an attachment, which no
     * other rune takes.
     *
     * @param list<array{0: int, 1: Attributes}> $runs
     * @return list<array{0: int, 1: Attributes}>
     */
    public static function appendRuns(array $runs, string $s, Attributes $a): array
    {
        if ($s === '') { return $runs; }
        if ($a->attachment === null) { return self::appendRun($runs, mb_strlen($s, 'UTF-8'), $a); }
        $plain = $a->plain();
        foreach (mb_str_split($s, 1, 'UTF-8') as $r) {
            $runs = self::appendRun($runs, 1, $r === Text::OBJECT_REPLACEMENT ? $a : $plain);
        }
        return $runs;
    }

    /**
     * Appends the runs from rune $from up to rune $to of $runs to $dst.
     *
     * @param list<array{0: int, 1: Attributes}> $dst
     * @param list<array{0: int, 1: Attributes}> $runs
     * @return list<array{0: int, 1: Attributes}>
     */
    public static function cutRuns(array $dst, array $runs, int $from, int $to): array
    {
        $at = 0;
        foreach ($runs as $r) {
            $a = max($at, $from); $z = min($at + $r[0], $to);
            if ($a < $z) { $dst = self::appendRun($dst, $z - $a, $r[1]); }
            $at += $r[0];
            if ($at >= $to) { break; }
        }
        return $dst;
    }

    /** Whether two lists of runs are equal. @param list<array{0: int, 1: Attributes}> $a @param list<array{0: int, 1: Attributes}> $b */
    public static function runsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) { return false; }
        foreach ($a as $k => $r) {
            if ($r[0] !== $b[$k][0] || !$r[1]->equals($b[$k][1])) { return false; }
        }
        return true;
    }
}
