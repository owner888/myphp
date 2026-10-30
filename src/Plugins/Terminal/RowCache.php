<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Text\Layout;

/**
 * What a row of the screen draws, made when it changes (mygo's rowCache):
 * backgrounds, runs of glyphs, lines and drawn characters, in cells.
 *
 * @internal
 */
final class RowCache
{
    /** @var list<array{0: int, 1: int, 2: array{0: int, 1: float}, 3: bool}> backgrounds: from column, to column, color, and whether it is one character in inverse video, as a program draws its own cursor, whose background does not extend into the padding */
    public array $bgs = [];
    /** Every cell has a background of its own, other than the default, from which PaddingExtend extends. */
    public bool $filled = true;
    /** @var list<array{0: Layout, 1: list<int>, 2: array{0: int, 1: float}}> runs of glyphs placed in their cells, x from the left of the grid in points: the glyphs as a layout of one line, the column of each glyph, the color */
    public array $runs = [];
    /** @var list<array{0: int, 1: int, 2: int, 3: array{0: int, 1: float}}> lines: from column, to column, an underline style or DECO_STRIKE or DECO_OVERLINE, color */
    public array $decos = [];
    /** @var list<array{0: int, 1: int, 2: array{0: int, 1: float}}> characters drawn rather than taken from the font: column, codepoint, color */
    public array $boxes = [];
    /** The selected columns, $selA to $selB, when $sel. */
    public int $selA = 0, $selB = 0;
    public bool $sel = false;
    /** @var list<int> the columns of wide characters */
    public array $wide = [];
}
