<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Whether a window has developer tools (WindowOptions::$devTools, mygo's
 * DevTools): the web inspector of a page, the inspector of native UI
 * (UI\Inspector). Auto turns them on while the app runs from its project
 * (App::isDev), off in a bundle `myphp build` made.
 */
enum DevTools
{
    case Auto;
    case Enabled;
    case Disabled;

    /** Whether the tools are on, as the app runs now. */
    public function on(): bool
    {
        return $this === self::Enabled || ($this === self::Auto && App::isDev());
    }
}
