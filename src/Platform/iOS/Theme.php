<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\ThemePreferences;

final class Theme implements Platform\Theme
{
    private \MyPHP\ThemeSource $source = \MyPHP\ThemeSource::System;

    /** The app's appearance, the window's too (overrideUserInterfaceStyle), so that UIKit's own views, as its glass, follow it. */
    public function setSource(\MyPHP\ThemeSource $source): void
    {
        $this->source = $source;
        iOS::ffi()->myphp_ios_set_interface_style(match ($source) { \MyPHP\ThemeSource::Light => 1, \MyPHP\ThemeSource::Dark => 2, \MyPHP\ThemeSource::System => 0 }); // UIUserInterfaceStyle, on the main queue (UIKit asserts otherwise)
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

    /** The system blue, Reduce Motion, and the text size over the default (Dynamic Type), which the theme's text and the text styles scale by. */
    public function preferences(): ThemePreferences
    {
        $f = iOS::ffi();
        return new ThemePreferences(
            accent: 0x007AFF, // iOS System Blue
            reduceMotion: $f->myphp_ios_reduce_motion() === 1,
            textScale: $f->myphp_ios_text_scale(),
        );
    }

}
