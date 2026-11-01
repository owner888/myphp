<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * What every native backend implements (mygo's platform.Backend): the
 * contract between the public API and Cocoa, GTK or Win32. Unless a
 * method says otherwise it is called on the main thread, and every
 * handler callback runs on it.
 */
interface Backend
{
    /** Identifies the backend, as "darwin/wkwebview". */
    public function name(): string;

    /**
     * Prepares the native application, once, before it runs. The handler
     * came with the backend (myphp's: PHP turns the run loop before the app
     * runs, as transfers wait, which must reach AppHandler::dispatch).
     */
    public function init(\MyPHP\ActivationPolicy $policy): void;

    /** Runs the event loop until the app quits; AppHandler::ready comes once it finished launching. */
    public function run(): void;

    /** Asks the app to quit, as the Quit menu does: AppHandler::quitRequested decides. */
    public function quit(): void;

    /** Asks for AppHandler::dispatch on the main thread, after the events already queued. Callable before init. */
    public function signal(): void;

    /** Runs $fn on the main thread $seconds from now (myphp's: Go has its own timers). Callable before init. */
    public function timer(float $seconds, \Closure $fn): void;

    /** Handles the native events there are, or waits a little for one: for a wait that keeps the app running. */
    public function step(): void;

    public function app(): AppController;

    public function setApplicationMenu(?Menu $m): void;

    /**
     * Shows $m as a context menu over $w's content, at $at points from its
     * top-left corner, else at the pointer; it returns once the menu
     * closes. @param ?array{0: float, 1: float} $at
     */
    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void;

    /** Gives every native item of $it's id its state. */
    public function updateMenuItem(MenuItem $it): void;

    /** Fills the submenus of the native items of $id with $m's items anew (myphp's, for a submenu brought up to date as it opens). */
    public function replaceSubmenu(int $id, Menu $m): void;

    /** The native window of $w, made as $o says, a panel's for $panel (the core's flyout state says a flyout's); its content comes with setContent or createSurface. */
    public function newWindow(\MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel): Window;

    /**
     * A web page of $width by $height points, its documents getting
     * $scripts at their start, the bridge's messages to $onMessage: with
     * the configuration the engine handed over for window.open(), or a new
     * one; the user agent, its suffix and zoom; swipe and pinch gestures,
     * autoplay, throttling while hidden, picture in picture; the website
     * data store of a session, null for the default one.
     */
    public function newPage(int $width, int $height, array $scripts, \Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Page;

    /** The window that has the keyboard, or null. */
    public function focusedWindow(): ?\MyPHP\Window;

    /** Seconds between two clicks that make a double click, as the user set it. */
    public function doubleClickInterval(): float;

    public function newTray(TrayHandler $h): Tray;

    /** The system's fonts the UI's text shapes and rasterizes with, at the main display's scale. */
    public function fonts(): Fonts;

    /** The UI's text now rasterizes glyphs at $scale pixels a point (App::rescale). */
    public function rescaled(float $scale): void;

    public function dialogs(): Dialogs;

    public function clipboard(): Clipboard;

    public function shell(): Shell;

    public function screen(): Screen;

    public function theme(): Theme;

    public function power(): Power;

    public function network(): Network;

    public function nowPlaying(): NowPlaying;

    /** May be called before init. */
    public function secrets(): Secrets;

    /** The applications installed on the computer, sorted by name; it reads the disk. @return list<\MyPHP\InstalledApp> */
    public function applications(): array;

    /** Registers a global shortcut of $accelerator, reported as AppHandler::hotkeyPressed($id). */
    public function registerHotkey(int $id, string $accelerator): void;

    public function unregisterHotkey(int $id): void;

    public function notificationsSupported(): bool;

    /** Shows $n; throws why it could not. */
    public function showNotification(\MyPHP\Notification $n): void;

    public function removeNotification(string $id): void;

    /** Removes the app's notifications, those of earlier runs too on macOS. */
    public function removeAllNotifications(): void;

    /** Whether the system web view is there, so that windows showing pages work. May be called before init. */
    public function webViewAvailable(): bool;
}
