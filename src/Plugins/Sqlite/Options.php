<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/** Configure the page plugin (mygo's sqlite.Options). PHP's Sqlite::open() takes its own path and OpenOptions, not these. */
final class Options
{
    public function __construct(
        /** Holds the pages' database files: App::userDataPath() when empty. Pages open file names here, or ":memory:", not paths. */
        public string $directory = '',
        /** As OpenOptions::$busyTimeout, in seconds. */
        public float $busyTimeout = 0,
    ) {}
}
