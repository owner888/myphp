<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * Receives application level events from the backend (mygo's
 * platform.AppHandler), on the main thread.
 */
interface AppHandler
{
    /** The application finished launching. */
    public function ready(): void;

    /** Runs what was queued for the main thread (Backend::signal). */
    public function dispatch(): void;

    /** The user or the system asks the app to quit (Cmd+Q, Dock > Quit, logout): true lets it. */
    public function quitRequested(): bool;

    /** Right before the process ends; run() does not return. */
    public function terminating(): void;

    /** The app is activated again, as by a click on its Dock icon. */
    public function activated(bool $hasVisibleWindows): void;

    public function didBecomeActive(): void;

    public function didResignActive(): void;

    /** @param list<string> $urls */
    public function openURLs(array $urls): void;

    /** @param list<string> $paths */
    public function openFiles(array $paths): void;

    /** An item of a menu, by its id, was chosen. */
    public function menuItemClicked(int $id): void;

    /** The submenu of the item of $id is about to show (MenuItem::$opens; myphp's). */
    public function menuWillOpen(int $id): void;

    /** The languages the user prefers, or the regional formats, may have changed. */
    public function localeChanged(): void;

    public function themeChanged(): void;

    public function displaysChanged(): void;

    /** $event is "suspend", "resume", "lockScreen" or "unlockScreen". */
    public function powerEvent(string $event): void;

    /** The network's status, once Network::watch was called, when it may have changed. */
    public function networkChanged(\MyPHP\NetworkStatus $status): void;

    public function hotkeyPressed(int $id): void;

    public function notificationClicked(string $id): void;

    /** A media key or a remote command for what NowPlaying shows (MediaCommand's bit); $position is where SEEK asks to go, in seconds. */
    public function mediaCommand(int $cmd, float $position): void;
}
