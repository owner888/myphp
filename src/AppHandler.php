<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What the backend reports the app's events to (mygo's appHandler): each
 * goes to the module that hears it.
 *
 * @internal
 */
final class AppHandler implements Platform\AppHandler
{
    public function ready(): void
    {
        App::ready();
    }

    public function dispatch(): void
    {
        App::dispatch();
    }

    public function quitRequested(): bool
    {
        return App::shouldQuit();
    }

    public function terminating(): void
    {
        App::terminating();
    }

    public function activated(bool $hasVisibleWindows): void
    {
        App::activated($hasVisibleWindows);
    }

    public function didBecomeActive(): void
    {
        App::activeChanged(true);
    }

    public function didResignActive(): void
    {
        App::activeChanged(false);
    }

    public function openURLs(array $urls): void
    {
        Native\DeepLink::openURLs($urls);
    }

    public function openFiles(array $paths): void
    {
        Native\DeepLink::openFiles($paths);
    }

    public function menuItemClicked(int $id): void
    {
        Menu::clicked($id, Window::focused());
    }

    public function menuWillOpen(int $id): void
    {
        Menu::willOpen($id);
    }

    public function localeChanged(): void
    {
        Locales::changedFromSystem();
    }

    public function themeChanged(): void
    {
        App::systemChanged();
    }

    public function displaysChanged(): void
    {
        Screen::changed();
    }

    public function powerEvent(string $event): void
    {
        Power::event($event);
    }

    public function networkChanged(NetworkStatus $status): void
    {
        Network::changed($status);
    }

    public function hotkeyPressed(int $id): void
    {
        GlobalShortcut::pressed($id);
    }

    public function notificationClicked(string $id): void
    {
        Notification::clicked($id);
    }

    public function mediaCommand(int $cmd, float $position): void
    {
        NowPlaying::command($cmd, $position);
    }
}
