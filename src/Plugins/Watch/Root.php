<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

use Closure;

/** A directory, or a file, pages may watch (mygo's watch.Root). */
final class Root
{
    /** @param ?Closure(string, bool): bool $ignore */
    public function __construct(
        /** The absolute path of the directory or file. */
        public string $path,
        /** Lets pages watch the directories in it too, and what is in them. */
        public bool $recursive = false,
        /** Asked about every file and directory a watch of the root finds, by its path relative to the root, as FileWatchOptions::$ignore is, when set. */
        public ?Closure $ignore = null,
    ) {}
}
