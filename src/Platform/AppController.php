<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** Application level native features (mygo's platform.AppController). */
interface AppController
{
    public function setActivationPolicy(\MyPHP\ActivationPolicy $policy): void;

    /** Brings the app to the front. */
    public function activate(): void;

    public function hide(): void;

    public function unhide(): void;

    public function isHidden(): bool;

    /** Whether the app is the active one: in front on macOS, with a window that has the keyboard elsewhere. */
    public function isActive(): bool;

    public function setBadge(string $label): void;

    public function badge(): string;

    /** Requests the user's attention; returns an id for cancelBounce. */
    public function bounce(bool $critical): int;

    public function cancelBounce(int $id): void;

    /** Another icon of the app, a PNG; '' restores the app's. Throws for what is no image. */
    public function setDockIcon(string $png): void;

    /** The menu of the Dock icon (macOS); null removes it. */
    public function setDockMenu(?Menu $menu): void;

    /**
     * A website data store other than the default one (mygo's
     * NewDataStore), for a session (\MyPHP\Session), which newPage and the
     * calls below take; the core closes it with DataStore::close.
     */
    public function newDataStore(DataStoreOptions $opts): DataStore;

    /**
     * Deletes the data of $types (\MyPHP\SiteData bits) pages stored in
     * $store, null for the default one (mygo's ClearBrowsingData). The
     * calls of the stores return once the engine is done, the run loop
     * turning meanwhile, where mygo's take a function they call then.
     */
    public function clearBrowsingData(?DataStore $store, int $types): void;

    /** Every cookie of $store, null for the default one (mygo's ListCookies). @return list<\MyPHP\Cookie> */
    public function listCookies(?DataStore $store): array;

    /** Adds the cookie, normalized, or replaces the one of its name, domain and path (mygo's SetCookie). */
    public function setCookie(?DataStore $store, \MyPHP\Cookie $cookie): void;

    /** Deletes the cookie of the name, domain and path, which none is no error (mygo's DeleteCookie). */
    public function deleteCookie(?DataStore $store, string $name, string $domain, string $path): void;

    /** Deletes the cookies of $store, its other data kept (mygo's ClearCookies). */
    public function clearCookies(?DataStore $store): void;

    /**
     * A content rule list of $rules, in WebKit's content blocker format
     * (mygo's CompileContentRules): the list kept as $identifier
     * (\MyPHP\ContentRules::identifier) where the engine keeps compiled
     * lists, else $rules compiled, kept as $identifier, and the lists kept
     * for $id under other identifiers removed (ContentRules::stale). A
     * handle Page::setContentRules takes; throws with what is wrong.
     */
    public function compileContentRules(string $id, string $identifier, string $rules): mixed;

    public function showAboutPanel(\MyPHP\AboutPanelOptions $opts): void;

    /** The languages the user reads, most preferred first (mygo's PreferredLanguages). @return list<string> */
    public function preferredLanguages(): array;

    /** The language the app chose in an earlier run where the system keeps it, '' for none; null where the system keeps none, which the core's file then does (mygo's SavedLocale). */
    public function savedLocale(): ?string;

    /** Keeps the language the app chose where the system keeps it ('' forgets it); false where the system keeps none (mygo's SaveLocale). */
    public function saveLocale(string $tag): bool;

    /** The user's regional formats: their locale, first weekday (-1 unknown) and hour cycle (0 unknown) (mygo's Region). */
    public function region(): \MyPHP\Region;
}
