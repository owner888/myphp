<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use Throwable;

/**
 * A change a backend reports (mygo's platform.FileWatchNotice): to a
 * target ($name empty) or to one of the immediate children of a directory
 * target ($name its name; a path for a subtree target). A notice with $err
 * ends the watch, except an Overflow, after which the scan looks at
 * everything again.
 *
 * @internal
 */
final class Notice
{
    /** Something about the target or its child changed. */
    public const DIRTY = 0;
    public const WRITE = 1;
    public const CREATE = 2;
    public const REMOVE = 3;
    public const MOVE_FROM = 4;
    public const MOVE_TO = 5;
    /** The system stopped watching the target, as when it was deleted; the scan enrolls it again if it still exists. */
    public const LOST = 6;

    public function __construct(
        public readonly int $target = 0,
        /** What changed, one of the constants: only a hint, as the scan looks again at what a notice names. */
        public readonly int $kind = self::DIRTY,
        public readonly string $name = '',
        public readonly ?Throwable $err = null,
    ) {}
}
