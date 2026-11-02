<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Platform;

/**
 * Dark or light as GTK's settings say (mygo's linux theme): the app's
 * choice, the theme's dark preference, the desktop portal's color scheme,
 * or a theme named dark. The app's choice sets the preference GTK draws
 * its widgets by, which WebKitGTK's prefers-color-scheme follows.
 */
final class Theme implements Platform\Theme
{
    /** The appearance setSource() was given last. */
    private ThemeSource $source = ThemeSource::System;
    /** Whether setSource(Light) put the light variant in place of the desktop's dark theme (mygo's lightTheme). */
    private bool $lightTheme = false;

    public function isDark(): bool
    {
        if ($this->source !== ThemeSource::System) {
            return $this->source === ThemeSource::Dark;
        }
        $f = Gtk::ffi();
        $settings = $f->gtk_settings_get_default();
        if ($settings === null) {
            return false;
        }
        $prefer = $f->new('int[1]');
        $f->g_object_get($settings, 'gtk-application-prefer-dark-theme', \FFI::addr($prefer[0]), null);
        if ($prefer[0] !== 0) {
            return true;
        }
        $scheme = self::portalColorScheme();
        if ($scheme !== null && $scheme !== 0) {
            return $scheme === 1;
        }
        return str_contains(strtolower(self::themeName($settings)), 'dark');
    }

    /** mygo's SetSource: GTK's dark preference set by the choice, which GTK's widgets and WebKitGTK's pages follow. */
    public function setSource(ThemeSource $source): void
    {
        $this->source = $source;
        $f = Gtk::ffi();
        $settings = $f->gtk_settings_get_default();
        if ($settings === null) {
            return;
        }
        // A theme named for its dark variant, as Ubuntu's Yaru-dark in dark mode, keeps GTK and WebKitGTK's
        // prefers-color-scheme dark whatever gtk-application-prefer-dark-theme says: the light appearance takes the
        // theme of the name without the suffix, and the others the desktop's theme again (mygo's).
        if ($source !== ThemeSource::Light && $this->lightTheme) {
            $this->lightTheme = false;
            $f->gtk_settings_reset_property($settings, 'gtk-theme-name');
        }
        $name = self::themeName($settings);
        if ($source === ThemeSource::Light && !$this->lightTheme && strlen($name) > 5 && strcasecmp(substr($name, -5), '-dark') === 0) {
            $this->lightTheme = true;
            $f->g_object_set($settings, 'gtk-theme-name', substr($name, 0, -5), null);
        }
        $dark = match ($source) { ThemeSource::Dark => true, ThemeSource::Light => false, ThemeSource::System => self::portalColorScheme() === 1 };
        $f->g_object_set($settings, 'gtk-application-prefer-dark-theme', $dark ? 1 : 0, null);
    }

    public function preferences(): ThemePreferences
    {
        return new ThemePreferences();
    }

    /** The name of GTK's theme. */
    private static function themeName(\FFI\CData $settings): string
    {
        $f = Gtk::ffi();
        $name = $f->new('char*[1]');
        $f->g_object_get($settings, 'gtk-theme-name', \FFI::addr($name[0]), null);
        if ($name[0] === null) {
            return '';
        }
        $theme = \FFI::string($name[0]);
        $f->g_free($name[0]);
        return $theme;
    }

    /** The desktop portal's color scheme (0 no preference, 1 dark, 2 light), null when there is none (mygo's portalColorScheme). */
    private static function portalColorScheme(): ?int
    {
        $f = Gtk::ffi();
        $res = Portal::call(Portal::PATH, 'org.freedesktop.portal.Settings', 'Read', "('org.freedesktop.appearance', 'color-scheme')", '(v)');
        if ($res === null) {
            return null;
        }
        $v = $f->g_variant_get_child_value($res, 0);
        $inner = $f->g_variant_get_variant($v); // the portal wraps it twice
        $f->g_variant_unref($v);
        while (str_starts_with((string)$f->g_variant_get_type_string($inner), 'v')) {
            $next = $f->g_variant_get_variant($inner);
            $f->g_variant_unref($inner);
            $inner = $next;
        }
        $scheme = $f->g_variant_get_type_string($inner) === 'u' ? $f->g_variant_get_uint32($inner) : null;
        $f->g_variant_unref($inner);
        $f->g_variant_unref($res);
        return $scheme;
    }
}
