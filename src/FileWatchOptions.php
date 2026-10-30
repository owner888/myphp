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
        /**
         * How many files and directories a watch covers before it stops
         * with ErrWatchLimit: 65536 on macOS, where a watch covers a whole
         * tree with one stream, and 8192 elsewhere, where a backend may take
         * a handle per directory, when 0. With $hintsOnly it counts the
         * directories the watch enrolls.
         */
        public int $maxEntries = 0,
        /**
         * Makes the watch cheap and coarse: it keeps no snapshot of the tree
         * and stats no file, so it reports only FileHint events that name a
         * directory in or under which the system said something changed,
         * never which file, how, or that it was renamed. After the system
         * lost changes it hints "." (look at everything). It suits a dev
         * reloader, which rebuilds whatever changed; never use it where what
         * changed matters, such as following a document's file.
         *
         * It still doesn't follow symlinks and doesn't leave the directory.
         * Ignore is asked only about directories: what is in one it ignores
         * is not watched or hinted, but a change to a file it would ignore
         * still hints at that file's directory. On macOS a recursive watch
         * enrolls only its directory, which FSEvents watches as a tree; on
         * Linux it enrolls every directory, without stat'ing what is in
         * them.
         */
        public bool $hintsOnly = false,
    ) {}
}
