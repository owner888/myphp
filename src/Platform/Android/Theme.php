<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Platform;

final class Theme implements Platform\Theme
{
    private ThemeSource $source = ThemeSource::System;
    /** What the activity's last configuration said (Backend, EVENT_CONFIGURATION): night mode, the font scale, animations removed. */
    private bool $systemDark = false;
    private float $textScale = 1.0;
    private bool $reduceMotion = false;

    /** @internal the activity's configuration: night mode, the user's font size over the default, animations removed */
    public function setConfiguration(bool $dark, float $textScale, bool $reduceMotion): void
    {
        $this->systemDark = $dark;
        $this->textScale = $textScale > 0 ? $textScale : 1.0;
        $this->reduceMotion = $reduceMotion;
    }

    public function isDark(): bool
    {
        if ($this->source !== ThemeSource::System) {
            return $this->source === ThemeSource::Dark;
        }
        return $this->systemDark;
    }

    public function setSource(ThemeSource $source): void
    {
        $this->source = $source;
    }

    /** The configuration's font scale and animations setting, which the theme's text and the text styles scale by; no accent of the system's (the theme keeps its own). */
    public function preferences(): ThemePreferences
    {
        return new ThemePreferences(reduceMotion: $this->reduceMotion, textScale: $this->textScale);
    }
}
