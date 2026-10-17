<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{DisplayList, InputEvent, TextPainter};

/**
 * Shows a diff in an editor (mygo's editor decor): the colors of lines
 * and their gutters, the words changed, the numbers of lines, and blocks.
 * A diff viewer's sides implement it.
 */
interface Decor
{
    public function lineStyle(int $line): LineLook;
    /** The ranges of line $line's bytes the diff highlights. @return list<array{0: int, 1: int}> */
    public function words(int $line): array;
    /** The number shown for line $line, or -1 for none. */
    public function number(int $line): int;
    /** Paints block $b in $r: [x, y, w, h] in the window. */
    public function paintBlock(DisplayList $list, TextPainter $text, Editor $ed, Block $b, array $r): void;
    /** Takes an event on block $b (null for a line's gutter), whose box in the element is $r: [x, y, w, h]. */
    public function blockInput(Editor $ed, ?Block $b, InputEvent $ev, array $r): bool;
}
