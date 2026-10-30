<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** What painting a frame of an editor works out once: the lines in view and what highlights them (mygo's frame). */
final class PaintFrame
{
    public int $first = 0, $last = 0;
    /** @var list<array{0: int, 1: int}> the find bar's matches in view */
    public array $matches = [];
    /** @var ?array{0: int, 1: int} */
    public ?array $current = null;
    /** The text selected, whose other occurrences show. */
    public string $occurrence = '';
    /** @var ?array{0: Range, 1: Range} the brackets around the caret */
    public ?array $brackets = null;
    public bool $caretsOn = false;
    /** @var array<int, true> the lines with a caret */
    public array $activeLines = [];
    /** @var list<TextBackground> the app's text backgrounds in the lines in view */
    public array $texts = [];
    /** The guide of the block holding the caret, which shows stronger: its level, from -1, and its lines. */
    public int $guideLevel = -1, $guideFrom = 0, $guideTo = 0;
    /** The colors of the line being painted (View::colorsOf). */
    public array $colors = [[], []];
}
