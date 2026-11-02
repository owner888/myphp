<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Platform;

/**
 * Dark or light as GTK's settings say (mygo's linux theme): the theme's
 * dark preference, or a theme named dark; an app's choice sets the
 * preference GTK draws its own widgets by.
 */
final class Theme implements Platform\Theme
{
    private ?bool $forced = null;

    public function isDark(): bool
    {
        if ($this->forced !== null) {
            return $this->forced;
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
        $name = $f->new('char*[1]');
        $f->g_object_get($settings, 'gtk-theme-name', \FFI::addr($name[0]), null);
        if ($name[0] === null) {
            return false;
        }
        $theme = \FFI::string($name[0]);
        $f->g_free($name[0]);
        return str_ends_with(strtolower($theme), '-dark') || str_ends_with(strtolower($theme), ':dark');
    }

    public function setSource(ThemeSource $source): void
    {
        $this->forced = match ($source) { ThemeSource::Light => false, ThemeSource::Dark => true, ThemeSource::System => null };
    }

    public function preferences(): ThemePreferences
    {
        return new ThemePreferences();
    }
}
