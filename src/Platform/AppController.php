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
}
