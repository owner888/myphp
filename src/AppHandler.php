<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What the backend reports the app's events to (mygo's appHandler): each
 * goes to the module that hears it, then the plain state properties its
 * listeners changed redraw the views that read them (Observer::sync), as after a
 * timer or a fiber.
 *
 * @internal
 */
final class AppHandler implements Platform\AppHandler
{
    public function ready(): void
    {
        App::ready();
        UI\Reactive\Observer::sync();
    }

    public function dispatch(): void
    {
        App::dispatch();
    }

    public function quitRequested(): bool
    {
        $quit = App::shouldQuit();
        UI\Reactive\Observer::sync();
        return $quit;
    }

    public function terminating(): void
    {
        App::terminating();
        UI\Reactive\Observer::sync();
    }

    public function activated(bool $hasVisibleWindows): void
    {
        App::activated($hasVisibleWindows);
        UI\Reactive\Observer::sync();
    }

    public function didBecomeActive(): void
    {
        App::activeChanged(true);
        UI\Reactive\Observer::sync();
    }

    public function didResignActive(): void
    {
        App::activeChanged(false);
        UI\Reactive\Observer::sync();
    }

    public function openURLs(array $urls): void
    {
        Native\DeepLink::openURLs($urls);
        UI\Reactive\Observer::sync();
    }

    public function openFiles(array $paths): void
    {
        Native\DeepLink::openFiles($paths);
        UI\Reactive\Observer::sync();
    }

    public function menuItemClicked(int $id): void
    {
        Menu::clicked($id, Window::focused());
        UI\Reactive\Observer::sync();
    }

    public function menuWillOpen(int $id): void
    {
        Menu::willOpen($id);
        UI\Reactive\Observer::sync();
    }

    public function localeChanged(): void
    {
        Locales::changedFromSystem();
        UI\Reactive\Observer::sync();
    }

    public function themeChanged(): void
    {
        App::systemChanged();
        UI\Reactive\Observer::sync();
    }

    public function displaysChanged(): void
    {
        Screen::changed();
        UI\Reactive\Observer::sync();
    }

    public function powerEvent(string $event): void
    {
        Power::event($event);
        UI\Reactive\Observer::sync();
    }

    public function networkChanged(NetworkStatus $status): void
    {
        Network::changed($status);
        UI\Reactive\Observer::sync();
    }

    public function hotkeyPressed(int $id): void
    {
        GlobalShortcut::pressed($id);
        UI\Reactive\Observer::sync();
    }

    public function notificationClicked(string $id): void
    {
        Notification::clicked($id);
        UI\Reactive\Observer::sync();
    }

    public function mediaCommand(int $cmd, float $position): void
    {
        NowPlaying::command($cmd, $position);
        UI\Reactive\Observer::sync();
    }
}
