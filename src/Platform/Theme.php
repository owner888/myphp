<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** The system appearance and its override (mygo's platform.Theme). */
interface Theme
{
    public function isDark(): bool;

    public function setSource(\MyPHP\ThemeSource $source): void;

    /** The settings of the desktop the system's controls follow; a change of any calls AppHandler::themeChanged. */
    public function preferences(): \MyPHP\ThemePreferences;
}
