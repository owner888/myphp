<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\InstalledApp;
use MyPHP\Platform;
use RuntimeException;

/**
 * The desktop through GIO (mygo's linux system.go, icon.go, apps.go): URIs
 * open in their default application, files go to the trash, the file
 * manager shows a file by D-Bus (org.freedesktop.FileManager1), icons come
 * from the icon theme, and applications are the desktop entries.
 */
final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        self::launch($url);
    }

    public function openPath(string $path): void
    {
        self::launch(self::fileURI($path));
    }

    /** Shows the file selected in the file manager, else opens its folder (mygo's ShowItemInFolder). */
    public function showItemInFolder(string $path): void
    {
        $reply = DBus::call(DBus::SESSION, 'org.freedesktop.FileManager1', '/org/freedesktop/FileManager1', 'org.freedesktop.FileManager1', 'ShowItems',
            '([' . DBus::str(self::fileURI($path)) . "], '')");
        if ($reply !== null) {
            Gtk::ffi()->g_variant_unref($reply);
            return;
        }
        $this->openPath(dirname($path));
    }

    public function trashItem(string $path): void
    {
        $f = Gtk::ffi();
        $file = $f->g_file_new_for_path($path);
        $error = $f->new('void*[1]');
        $ok = $f->g_file_trash($file, null, $error);
        $f->g_object_unref($file);
        if (!$ok) { Gtk::fail($error, "cannot move $path to the trash"); }
    }

    public function beep(): void
    {
        $f = Gtk::ffi();
        $f->gdk_display_beep($f->gdk_display_get_default());
    }

    /**
     * The icon the file manager shows for a file or a folder, that of its
     * content type, or the icon of the application of a desktop entry, from
     * the icon theme at $size by $size pixels (mygo's FileIcon).
     */
    public function fileIcon(string $path, int $size): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException("myphp: no file $path");
        }
        $f = Gtk::ffi();
        if (str_ends_with($path, '.desktop') && ($app = $f->g_desktop_app_info_new_from_filename($path)) !== null) {
            $png = self::themeIcon($f->g_app_info_get_icon($app), $size);
            $f->g_object_unref($app);
            if ($png !== '') {
                return $png;
            }
        }
        $file = $f->g_file_new_for_path($path);
        $error = $f->new('void*[1]');
        $info = $f->g_file_query_info($file, 'standard::icon', 0, null, $error);
        $f->g_object_unref($file);
        if ($info === null) {
            Gtk::fail($error, "no icon for $path");
        }
        $png = self::themeIcon($f->g_file_info_get_icon($info), $size);
        $f->g_object_unref($info);
        return $png !== '' ? $png : throw new RuntimeException("myphp: the icon theme has no icon for \"$path\"");
    }

    /** Starts the application of a desktop entry (mygo's OpenApplication). */
    public function openApplication(string $path): void
    {
        $f = Gtk::ffi();
        $info = $f->g_desktop_app_info_new_from_filename($path) ?? throw new RuntimeException("myphp: \"$path\" is not a desktop entry of an application");
        $ctx = $f->gdk_display_get_app_launch_context($f->gdk_display_get_default());
        $error = $f->new('void*[1]');
        $ok = $f->g_app_info_launch($info, null, $ctx, $error);
        $f->g_object_unref($ctx);
        $f->g_object_unref($info);
        if (!$ok) { Gtk::fail($error, "cannot start $path"); }
    }

    /** Whether the file's file system is not a remote one (GIO's filesystem::remote). */
    public function isLocal(string $path): bool
    {
        $f = Gtk::ffi();
        $file = $f->g_file_new_for_path($path);
        $error = $f->new('void*[1]');
        $info = $f->g_file_query_filesystem_info($file, 'filesystem::remote', null, $error);
        $f->g_object_unref($file);
        if ($info === null) {
            Gtk::fail($error, "no file system known of $path");
        }
        $remote = $f->g_file_info_get_attribute_boolean($info, 'filesystem::remote');
        $f->g_object_unref($info);
        return !$remote;
    }

    /** The app's icon: the icon theme's of its program name, which its desktop entry names. */
    public function appIcon(int $size): string
    {
        $f = Gtk::ffi();
        $icon = $f->g_themed_icon_new($f->g_get_prgname());
        $png = self::themeIcon($icon, $size);
        $f->g_object_unref($icon);
        return $png !== '' ? $png : throw new RuntimeException('myphp: the icon theme has no icon of the app');
    }

    /**
     * The applications a launcher shows, their desktop entries (mygo's
     * Applications), sorted by name.
     * @return list<InstalledApp>
     */
    public static function installedApps(): array
    {
        $f = Gtk::ffi();
        $apps = [];
        $list = $f->g_app_info_get_all();
        for ($node = $list; $node !== null; $node = $node->next) {
            $info = $node->data;
            if ($f->g_app_info_should_show($info) && ($path = $f->g_desktop_app_info_get_filename($info)) !== null && $path !== '') {
                $apps[] = new InstalledApp((string)$f->g_app_info_get_display_name($info), (string)$f->g_app_info_get_id($info), $path);
            }
            $f->g_object_unref($info);
        }
        $f->g_list_free($list);
        usort($apps, fn(InstalledApp $a, InstalledApp $b): int => strnatcasecmp($a->name, $b->name));
        return $apps;
    }

    /** A GIcon drawn from the icon theme at $size by $size pixels, '' where the theme has none of its names (mygo's themeIcon). */
    private static function themeIcon(?CData $icon, int $size): string
    {
        if ($icon === null) {
            return '';
        }
        $f = Gtk::ffi();
        $info = $f->gtk_icon_theme_lookup_by_gicon($f->gtk_icon_theme_get_default(), $icon, $size, 1 << 4); // GTK_ICON_LOOKUP_FORCE_SIZE: GIO's themed icons list their generic names themselves
        if ($info === null) {
            return '';
        }
        $pix = $f->gtk_icon_info_load_icon($info, null);
        $f->g_object_unref($info);
        if ($pix === null) {
            return '';
        }
        $png = Clipboard::png($pix);
        $f->g_object_unref($pix);
        return $png;
    }

    private static function launch(string $uri): void
    {
        $f = Gtk::ffi();
        $error = $f->new('void*[1]');
        if (!$f->g_app_info_launch_default_for_uri($uri, null, $error)) {
            Gtk::fail($error, "cannot open $uri");
        }
    }

    /** A path as a file URI (g_file_get_uri). */
    private static function fileURI(string $path): string
    {
        $f = Gtk::ffi();
        $file = $f->g_file_new_for_path($path);
        $p = $f->g_file_get_uri($file);
        $f->g_object_unref($file);
        $uri = \FFI::string($p);
        $f->g_free($p);
        return $uri;
    }
}
