<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** The press dragging a selection (mygo's gesture). */
final class Gesture
{
    /** What the drag selects: characters, words, lines or columns. */
    public const CHARS = 0, WORDS = 1, LINES = 2, COLUMNS = 3;

    public bool $active = false;
    public int $mode = self::CHARS;
    public Sel $origin;
    /** Added to the selections, which keep the others. */
    public bool $add = false;
    /** @var list<Sel> */
    public array $others = [];
    /** @var array{0: float, 1: float} where a column selection started, in the content */
    public array $colStart = [0.0, 0.0];

    public function __construct(bool $active = false, int $mode = self::CHARS, ?Sel $origin = null)
    {
        $this->active = $active; $this->mode = $mode; $this->origin = $origin ?? new Sel;
    }
}
