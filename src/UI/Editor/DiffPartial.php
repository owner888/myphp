<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Maps the lines of the texts a patch shows, its hunks' lines only, to the lines of the files (mygo's diffPartial). */
final class DiffPartial
{
    /** @var list<int> the line of the texts each hunk starts at, of the old and the new */
    public array $oldStarts = [], $newStarts = [];
    /** @var list<int> and of the files, from 0 */
    public array $oldFile = [], $newFile = [];
    /** @var list<string> */
    public array $headers = [];
    /** The texts made of the hunks' lines. */
    public ?Buffer $old = null, $new = null;

    /** The file's line a line of a side's text is. */
    public function fileLine(bool $old, int $line): int
    {
        [$starts, $file] = $old ? [$this->oldStarts, $this->oldFile] : [$this->newStarts, $this->newFile];
        $k = 0;
        while ($k + 1 < count($starts) && $starts[$k + 1] <= $line) { $k++; }
        if ($k >= count($starts)) { return $line; }
        return $file[$k] + $line - $starts[$k];
    }
}
