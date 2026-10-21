<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

/** A watch a page asks for (mygo's watch.Request), as Options::$allow sees it. */
final class Request
{
    public function __construct(
        /** The name of the root. */
        public readonly string $root,
        /** What to watch in the root, relative to it and separated by slashes: "." for the root itself. */
        public readonly string $path,
        /** Whether it asks to watch the directories in it too. */
        public readonly bool $recursive,
    ) {}
}
