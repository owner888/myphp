<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** A status bar or notification area icon (mygo's platform.Tray). */
interface Tray
{
    /** Names the icon across launches (macOS's autosave name). */
    public function setID(string $id): void;

    /** Whether the bar the icon shows on is dark. */
    public function dark(): bool;

    public function setImage(string $png, bool $template): void;

    public function setTitle(string $title): void;

    /** Sets a system vector icon by name (Apple SF Symbols on macOS, e.g. 'dot.radiowaves.left.and.right'). */
    public function setSystemIcon(string $name): void;

    /** Sets a compact two-line title on the status item (macOS). */
    public function setDualLineTitle(string $top, string $bottom): void;

    public function setToolTip(string $tip): void;

    public function setMenu(?Menu $menu): void;

    /** Shows the icon's menu, as a click would; nothing without one. */
    public function popUpMenu(): void;

    /** Tells the panel whether the icon answers activation, so that it can tell a single click from a double one; platforms that report clicks themselves ignore it. */
    public function setActivatable(bool $on): void;

    public function bounds(): \MyPHP\Rectangle;

    public function destroy(): void;

    /** Highlights the icon while a flyout shows from it (myphp's). */
    public function highlight(bool $on): void;

    /** Whether the event being handled is a press on the icon, which a flyout of it leaves to its click (myphp's). */
    public function pressed(): bool;

    /** The native object a flyout shows from (NSStatusBarButton; myphp's). */
    public function handle(): mixed;
}
