<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * A step of undo (mygo's undoStep): the texts before and after it, which
 * share all they did not change, the selections, and the region it
 * changed ($start < 0 while it changed nothing).
 *
 * @internal
 */
final class UndoStep
{
    /** @var array{0: int, 1: int} */
    public array $selAfter = [0, 0];
    public int $oldEnd = 0;
    public int $newEnd = 0;

    /** @param array{0: int, 1: int} $selBefore */
    public function __construct(
        public Text $before,
        public Text $after,
        public array $selBefore,
        public int $start = -1,
    ) {}
}
