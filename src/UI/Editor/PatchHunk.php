<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A hunk of a FilePatch (mygo's PatchHunk): lines from $oldStart (from 1) of the old file, $oldLines of them, and $newStart and $newLines of the new. */
final class PatchHunk
{
    /**
     * @param string $section what follows the second @@, as the function the hunk is in
     * @param list<string> $lines the hunk's lines with their first character: a space for a line of both, - for one deleted, + for one added; a line "\ No newline at end of file" follows the line it is about
     */
    public function __construct(
        public int $oldStart = 0, public int $oldLines = 0, public int $newStart = 0, public int $newLines = 0,
        public string $section = '', public array $lines = [],
    ) {}
}
