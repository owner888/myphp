<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Platform;

/**
 * Dark or light as Settings > Personalization > Colors says for apps
 * (mygo's windows theme), and the preferences: the accent of the title
 * bars and Start, animation and transparency effects, contrast themes,
 * the size of text. The windows follow the appearance with their stage.
 */
final class Theme implements Platform\Theme
{
    private const PERSONALIZE = 'Software\Microsoft\Windows\CurrentVersion\Themes\Personalize';

    private ThemeSource $source = ThemeSource::System;

    public function isDark(): bool
    {
        if ($this->source !== ThemeSource::System) {
            return $this->source === ThemeSource::Dark;
        }
        return Win32::regDword(self::PERSONALIZE, 'AppsUseLightTheme') === 0;
    }

    public function __construct(private readonly Backend $b) {}

    /** The app's appearance, the app told as Windows tells it of the system's (the windows' title bars and content follow). */
    public function setSource(ThemeSource $source): void
    {
        $this->source = $source;
        $this->b->h->themeChanged();
    }

    public function preferences(): ThemePreferences
    {
        $u = Win32::user32();
        $accent = Win32::regDword('Software\Microsoft\Windows\DWM', 'AccentColor'); // 0xAABBGGRR
        $animate = $u->new('int32_t');
        $reduceMotion = $u->SystemParametersInfoW(0x1042 /* SPI_GETCLIENTAREAANIMATION */, 0, FFI::addr($animate), 0) !== 0 && $animate->cdata === 0;
        $hc = $u->new('HIGHCONTRASTW');
        $hc->cbSize = FFI::sizeof($hc);
        $highContrast = $u->SystemParametersInfoW(0x0042 /* SPI_GETHIGHCONTRAST */, $hc->cbSize, FFI::addr($hc), 0) !== 0 && ($hc->dwFlags & 1) !== 0;
        $text = Win32::regDword('Software\Microsoft\Accessibility', 'TextScaleFactor');
        return new ThemePreferences(
            accent: $accent === null ? null : (($accent & 0xFF) << 16) | ($accent & 0xFF00) | (($accent >> 16) & 0xFF),
            reduceMotion: $reduceMotion,
            reduceTransparency: Win32::regDword(self::PERSONALIZE, 'EnableTransparency') === 0,
            highContrast: $highContrast,
            textScale: $text !== null && $text >= 100 && $text <= 500 ? $text / 100 : 1.0,
        );
    }
}
