<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/** Configures App::watchFiles() (mygo's FileWatchOptions). The defaults watch a file, or a directory without the directories in it. */
final class FileWatchOptions
{
    /** @param ?Closure(string, bool): bool $ignore */
    public function __construct(
        /** Watches the directories in the directory too. */
        public bool $recursive = false,
        /**
         * Asked about every file and directory the watch finds, by its path
         * relative to the watched directory, separated by slashes, and
         * whether it is a directory, when set. What it ignores is not
         * watched, reported or counted in $maxEntries, nor is anything in a
         * directory it ignores, which is never opened: return true for
         * node_modules and .git. It must be quick.
         */
        public ?Closure $ignore = null,
        /** How long a watch waits for changes to stop before it reports them, in seconds: 0.05 when 0, right away when negative. */
        public float $debounce = 0,
        /** The longest changes wait while more keep coming, in seconds: 0.5 when 0, and never less than $debounce. */
        public float $maxDelay = 0,
        /** How many files and directories a watch covers before it stops with ErrWatchLimit: 8192 when 0. */
        public int $maxEntries = 0,
    ) {}
}
