<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Platform;

final class Theme implements Platform\Theme
{
    private ThemeSource $source = ThemeSource::System;

    public function isDark(): bool
    {
        if ($this->source !== ThemeSource::System) {
            return $this->source === ThemeSource::Dark;
        }
        return true;
    }

    public function setSource(ThemeSource $source): void
    {
        $this->source = $source;
    }

    public function preferences(): ThemePreferences
    {
        return new ThemePreferences();
    }
}
