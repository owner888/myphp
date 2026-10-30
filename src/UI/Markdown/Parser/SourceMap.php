<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * Where the bytes of a block's content are in the source (mygo's
 * sourceMap): content joined from lines looks an offset up by halving, so
 * that the offsets of all the inlines of a paragraph of many lines take
 * n log n time, not quadratic.
 *
 * @internal
 */
final class SourceMap
{
    /**
     * @param int $slice where the content starts in the source when it is a slice of it, else -1
     * @param list<array{start: int, end: int, pad: int, cell: bool}> $segs the lines it is joined from
     * @param list<int> $marks where each line starts in the content, or in a table cell the offsets of the | written \|
     */
    public function __construct(
        private readonly int $slice,
        private readonly int $start,
        private readonly array $segs,
        private readonly array $marks,
    ) {}

    /** The source offset of byte $off of the content. */
    public function source(int $off): int
    {
        if ($this->slice >= 0) {
            return $this->slice + $off;
        }
        if ($this->segs === []) {
            return $this->start;
        }
        $s = $this->segs[0];
        $len = $s['end'] - $s['start'];
        if ($s['cell']) {
            // Each \| read as | shifts what follows by one.
            if ($off > $len - count($this->marks)) {
                return $s['start'] + min($off, $len);
            }
            return $s['start'] + $off + $this->below($off);
        }
        // The line holding it: the last starting at or before it.
        $k = $this->below($off + 1) - 1;
        $s = $this->segs[$k];
        return $s['start'] + max(min($off - $this->marks[$k] - $s['pad'], $s['end'] - $s['start']), 0);
    }

    /** How many marks are below $v. */
    private function below(int $v): int
    {
        [$a, $b] = [0, count($this->marks)];
        while ($a < $b) {
            $m = ($a + $b) >> 1;
            if ($this->marks[$m] < $v) { $a = $m + 1; } else { $b = $m; }
        }
        return $a;
    }
}
