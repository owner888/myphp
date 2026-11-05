<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\ObjC;
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
        ObjC::boot(['UIKit']);
        $tc = ObjC::cls('UITraitCollection')?->msg('currentTraitCollection');
        if ($tc !== null) {
            $style = (int)$tc->msg('userInterfaceStyle');
            return $style === 2; // UIUserInterfaceStyleDark
        }
        return false;
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
