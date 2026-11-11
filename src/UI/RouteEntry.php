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

    /** @var list<mixed>|null what the page's declarative view puts at the end of its NavigationStack's bar (View::toolbar) @internal */
    public ?array $toolbar = null;
    /** What scrolls the page's large title while it asks for one (a ScrollState, a list's ListState), and the ScrollState it keeps for it. @internal */
    public ?Scrollable $largeTitle = null;
    public ?ScrollState $largeScroll = null;
    /** @var array<string, true> what the page's last build put in the bar @internal */
    public array $barSeen = [];
    /** Whether the page hid its navigation bar (toolbarVisibility(Visibility::hidden, for: ToolbarPlacement::navigationBar)). @internal */
    public bool $navBarHidden = false;
}
