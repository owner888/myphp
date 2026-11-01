<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** Receives a tray icon's events (mygo's platform.TrayHandler). */
interface TrayHandler
{
    public function clicked(): void;

    public function rightClicked(): void;

    /** The bar the icon shows on turned dark or light. */
    public function appearanceChanged(): void;
}
