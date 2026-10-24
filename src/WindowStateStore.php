<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Keeps the states of the windows with a WindowOptions::$stateKey, by key,
 * for App::setWindowStateStore (mygo's WindowStateStore). It is called on
 * the run loop, so it should return quickly.
 */
interface WindowStateStore
{
    /** The saved states, called once, as the first window with a state key is created; without any (null, or a throw), windows get their options. @return ?array<string, WindowState> */
    public function loadWindowStates(): ?array;

    /** Replaces the saved states with $states, called as a window with a state key closes and as the app quits, when a state changed; after a throw the next call saves them again. @param array<string, WindowState> $states */
    public function saveWindowStates(array $states): void;
}
