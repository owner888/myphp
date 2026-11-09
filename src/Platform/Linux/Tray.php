<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use FFI\CData;
use MyPHP\Platform;
use MyPHP\Platform\{Menu, TrayHandler, Unsupported};
use MyPHP\Rectangle;
use MyPHP\Native\Mem;

/**
 * A tray icon through AppIndicator (StatusNotifierItem, mygo's linux
 * menu.go): the panel shows its menu on a click; a panel can activate the
 * icon (GNOME's indicators on a double click), which the library reports
 * as its "activate" signal while a handler is connected.
 */
final class Tray implements Platform\Tray
{
    private static ?FFI $lib = null;
    private static int $count = 0;
    /** Whether the library has the "activate" signal, which older libayatana-appindicator3 releases lack. */
    private static ?bool $activates = null;

    private ?CData $ind;
    private int $owner = 0;
    private string $iconDir = '';
    private int $icons = 0;
    private ?CData $onActivate = null;
    private int $activate = 0;

    public function __construct(private readonly Menus $menus, private readonly TrayHandler $h)
    {
        $l = self::lib();
        $this->ind = $l->app_indicator_new('myphp-' . getmypid() . '-' . ++self::$count, 'application-x-executable', 0); // APPLICATION_STATUS
        $l->app_indicator_set_status($this->ind, 1); // ACTIVE
        $this->setMenu(new Menu()); // AppIndicator shows only once it has a menu
    }

    private static function lib(): FFI
    {
        try {
            return self::$lib ??= FFI::cdef('
                typedef void (*CbActivate)(void*, int, int, void*); typedef struct { CbActivate fn; } HActivate;
                void* app_indicator_new(const char*, const char*, int);
                void app_indicator_set_status(void*, int);
                void app_indicator_set_icon_theme_path(void*, const char*);
                void app_indicator_set_icon_full(void*, const char*, const char*);
                void app_indicator_set_label(void*, const char*, const char*);
                void app_indicator_set_title(void*, const char*);
                void app_indicator_set_menu(void*, void*);
                unsigned long g_signal_connect_data(void*, const char*, void*, void*, void*, int);
                void g_signal_handler_disconnect(void*, unsigned long);
                unsigned int g_signal_lookup(const char*, unsigned long); unsigned long g_type_from_name(const char*);
                void g_object_unref(void*);
            ', 'libayatana-appindicator3.so.1');
        } catch (FFI\Exception $e) {
            throw new Unsupported('myphp: tray icons need libayatana-appindicator3: ' . $e->getMessage());
        }
    }

    /** Panels place indicators themselves; the ID is given as it is made. */
    public function setID(string $id): void {}

    /** AppIndicator tells nothing of the panel, whose icon theme draws the icon in its own colors. */
    public function dark(): bool
    {
        return false;
    }

    /** The image, written to a file of a new name, which makes the indicator host load it again. */
    public function setImage(string $png, bool $template): void
    {
        if ($this->iconDir === '') {
            $this->iconDir = sys_get_temp_dir() . '/myphp-tray-' . getmypid() . '-' . self::$count;
            if (!is_dir($this->iconDir) && !mkdir($this->iconDir, 0o700)) {
                throw new \RuntimeException("myphp: cannot make $this->iconDir for the tray's icon");
            }
        }
        @unlink("$this->iconDir/icon-$this->icons.png");
        $name = 'icon-' . ++$this->icons;
        if (file_put_contents("$this->iconDir/$name.png", $png) === false) {
            throw new \RuntimeException("myphp: cannot write the tray's icon");
        }
        self::lib()->app_indicator_set_icon_theme_path($this->ind, $this->iconDir);
        self::lib()->app_indicator_set_icon_full($this->ind, $name, '');
    }

    public function setTitle(string $title): void
    {
        self::lib()->app_indicator_set_label($this->ind, $title, $title);
    }

    public function setSystemIcon(string $name): void {}

    public function setDualLineTitle(string $top, string $bottom): void
    {
        $this->setTitle(trim("$top $bottom"));
    }

    public function setToolTip(string $tip): void
    {
        self::lib()->app_indicator_set_title($this->ind, $tip);
    }

    public function setMenu(?Menu $menu): void
    {
        $f = Gtk::ffi();
        $this->menus->dropOwner($this->owner);
        $this->owner = $this->menus->newOwner();
        $gm = $f->gtk_menu_new();
        if ($menu !== null) {
            $this->menus->fill($gm, $menu->items, null, $this->owner, null, false);
        }
        $f->gtk_widget_show_all($gm);
        self::lib()->app_indicator_set_menu($this->ind, self::lib()->cast('void*', Mem::addr($gm)));
        $this->menus->exportIcons($this->owner); // exported now
    }

    /** The panel shows the menu; the app cannot. */
    public function popUpMenu(): void {}

    /**
     * Connects or drops the indicator's activate handler, which is also
     * the answer a panel gets asking the icon whether it can be activated
     * at all: without one GNOME's extension opens the menu on every click;
     * with one, a double click arrives as TrayHandler::clicked (mygo's
     * SetActivatable).
     */
    public function setActivatable(bool $on): void
    {
        $l = self::lib();
        if ($this->ind === null || $on === ($this->activate !== 0) || !(self::$activates ??= $l->g_signal_lookup('activate', $l->g_type_from_name('AppIndicator')) !== 0)) {
            return;
        }
        if (!$on) {
            $l->g_signal_handler_disconnect($this->ind, $this->activate);
            $this->activate = 0;
            return;
        }
        if ($this->onActivate === null) {
            $this->onActivate = $l->new('HActivate', false);
            $this->onActivate->fn = fn($ind, $x, $y, $data) => $this->h->clicked();
        }
        $this->activate = $l->g_signal_connect_data($this->ind, 'activate', $l->cast('void*', $this->onActivate->fn), null, null, 0);
    }

    /** AppIndicator tells nothing of where the icon is. */
    public function bounds(): Rectangle
    {
        return new Rectangle(0, 0, 0, 0);
    }

    public function destroy(): void
    {
        if ($this->ind === null) {
            return;
        }
        $this->setActivatable(false);
        $l = self::lib();
        $l->app_indicator_set_status($this->ind, 0); // PASSIVE
        $l->g_object_unref($this->ind);
        $this->ind = null;
        $this->menus->dropOwner($this->owner);
        if ($this->iconDir !== '') {
            @unlink("$this->iconDir/icon-$this->icons.png");
            @rmdir($this->iconDir);
        }
    }

    public function highlight(bool $on): void {}

    public function pressed(): bool
    {
        return false;
    }

    public function handle(): mixed
    {
        return $this->ind;
    }
}
