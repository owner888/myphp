<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * A file or directory the scan enrolls in a backend (mygo's
 * platform.FileWatchTarget). IDs are never reused within a watch.
 *
 * @internal
 */
final class Target
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly bool $directory,
        /** What the scan saw at the path (Fs::lstat): the backend checks the target it opens is still that file, so that a replacement racing the enrollment is not watched in its place. @var array{0: int, 1: int, 2: int, 3: int, 4: int} */
        public readonly array $info,
        /** Asks to watch everything beneath the directory with this one target; a backend that can't throws Unsupported, and the scan enrolls every directory instead. */
        public readonly bool $subtree = false,
    ) {}
}
