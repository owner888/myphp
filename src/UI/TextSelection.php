<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * One selection across the paragraphs of a Selectable container (mygo's
 * textSelection): its endpoints, each a paragraph's node id and a rune of
 * its text; the endpoints a double or triple click started with, its unit
 * (1 runes, 2 words, 3 lines), whether the pointer drags it, and the x the
 * Up and Down keys keep.
 */
final class TextSelection
{
    /** @var array{0: int, 1: int} */
    public array $anchor = [0, 0], $caret = [0, 0];
    /** @var array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} */
    public array $start = [[0, 0], [0, 0]];
    public int $unit = 0;
    public bool $dragging = false;
    public ?float $desiredX = null;

    /** @param array{0: int, 1: int} $at */
    public function __construct(public int $scope = 0, array $at = [0, 0])
    {
        $this->anchor = $this->caret = $at;
    }
}
