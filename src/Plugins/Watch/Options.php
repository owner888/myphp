<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

use Closure;

/** Configures the plugin (mygo's watch.Options). */
final class Options
{
    /**
     * @param array<string, Root> $roots
     * @param ?Closure(\MyPHP\CallContext, string): ?Root $resolve
     * @param ?Closure(\MyPHP\CallContext, Request): bool $allow
     */
    public function __construct(
        /** The directories and files pages may watch, by the names they use: lowercase letters, digits and dashes, starting with a letter. */
        public array $roots = [],
        /** Asked for the root of a name that is not in $roots, with the call's context, when set, so that the app can grant roots while it runs, such as a folder the user chose in a dialog. It returns null for names it doesn't know. */
        public ?Closure $resolve = null,
        /** Asked before each watch with the call's context and the request, when set; it can refuse it. */
        public ?Closure $allow = null,
        /** How many watches a page may have at a time: 8 when 0. */
        public int $maxWatchesPerPage = 0,
        /** How many watches all pages may have at a time: 128 when 0. */
        public int $maxWatches = 0,
    ) {}
}
