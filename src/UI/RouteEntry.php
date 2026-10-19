<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * An entry of a Router's history: a location of a page (mygo's
 * routeEntry). Router's own: an app reads the history through Router.
 */
final class RouteEntry
{
    /**
     * $pages are the pages showing the entry: Router::view's, then those of
     * each Route::view inside, made as they are first built; an entry pushed
     * shares the pages of the layouts whose part of the path it keeps, and
     * all of them for the same path with another query. $layouts are, by
     * level, how many parts of the path a page holding a Route::view takes,
     * -1 for other pages and those not built yet. $focus is the node that
     * had the keyboard focus as the entry went out of sight.
     * @param list<int> $pages @param list<int> $layouts
     */
    public function __construct(
        /** The path, escaped, and the query. */
        public string $loc,
        public array $pages = [],
        public array $layouts = [],
        public string $title = '',
        public ?int $focus = null,
    ) {}
}
