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

    public function isDark(): bool
    {
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
            dark: $this->isDark(),
            accent: $this->accentColor(),
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
