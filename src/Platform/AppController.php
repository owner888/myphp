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
