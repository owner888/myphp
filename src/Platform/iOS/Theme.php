<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\ThemePreferences;

final class Theme implements Platform\Theme
{
    private ?Closure $onChange = null;
    private \MyPHP\ThemeSource $source = \MyPHP\ThemeSource::System;

    public function setSource(\MyPHP\ThemeSource $source): void
    {
        $this->source = $source;
    }

    public function isDark(): bool
    {
        if ($this->source !== \MyPHP\ThemeSource::System) {
            return $this->source === \MyPHP\ThemeSource::Dark;
        }
        return iOS::ffi()->myphp_ios_is_dark() === 1; // the view's trait collection, kept by the launcher
    }

    public function accentColor(): array
    {
        return [0.0, 0.478, 1.0, 1.0]; // iOS System Blue #007AFF
    }

    public function preferences(): ThemePreferences
    {
        return new ThemePreferences(
            accent: 0x007AFF, // iOS System Blue
        );
    }

    public function onChange(Closure $fn): void
    {
        $this->onChange = $fn;
    }

    public function notifyChanged(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)();
        }
    }
}
