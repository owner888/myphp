<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\Logger;
use MyPHP\MenuIcon;
use MyPHP\Platform\{Accelerator, AppHandler, Menu, MenuItem};
use MyPHP\Native\Mem;

/**
 * GTK's menus (mygo's linux menu.go and menuicon.go): the menu bar at the
 * top of each window, which shows the application menu, and the context
 * menus. Native items are tracked per owner (a window's menu bar, a popup,
 * a submenu filled anew as it opens) so that they are updated in place and
 * forgotten when rebuilt. Each signal has one C handler, its data the
 * item's id.
 */
final class Menus
{
    /** The roles of the editing commands, which the content of the window performs (mygo's editCommands). */
    private const EDIT_ROLES = ['undo', 'redo', 'cut', 'copy', 'paste', 'pasteAndMatchStyle', 'delete', 'selectAll'];
    /** The GDK modifiers that make a key a shortcut: Shift, Control, Alt, Super, Hyper and Meta (mygo's modifierMask). */
    public const MODIFIER_MASK = 1 << 0 | 1 << 2 | 1 << 3 | 1 << 26 | 1 << 27 | 1 << 28;
    /** GDK's names of the accelerators' named keys (mygo's gdkKeyNames). */
    private const KEY_NAMES = [
        'Enter' => 'Return', 'Tab' => 'Tab', 'Space' => 'space', 'Backspace' => 'BackSpace', 'Delete' => 'Delete',
        'Insert' => 'Insert', 'Escape' => 'Escape', 'Up' => 'Up', 'Down' => 'Down', 'Left' => 'Left', 'Right' => 'Right',
        'Home' => 'Home', 'End' => 'End', 'PageUp' => 'Page_Up', 'PageDown' => 'Page_Down', '+' => 'plus',
        'VolumeUp' => 'XF86AudioRaiseVolume', 'VolumeDown' => 'XF86AudioLowerVolume', 'VolumeMute' => 'XF86AudioMute',
        'MediaNextTrack' => 'XF86AudioNext', 'MediaPreviousTrack' => 'XF86AudioPrev', 'MediaStop' => 'XF86AudioStop',
        'MediaPlayPause' => 'XF86AudioPlay', 'PrintScreen' => 'Print', 'NumDec' => 'KP_Decimal', 'NumAdd' => 'KP_Add',
        'NumSub' => 'KP_Subtract', 'NumMult' => 'KP_Multiply', 'NumDiv' => 'KP_Divide', 'CapsLock' => 'Caps_Lock',
        'NumLock' => 'Num_Lock', 'ScrollLock' => 'Scroll_Lock',
    ];

    /** The application menu, which new windows' menu bars show, kept current by update(). */
    public ?Menu $appMenu = null;
    private int $owners = 0;
    /** @var array<int, int> the owner each owner was made in, which goes with it */
    private array $parents = [];
    /**
     * @var array<int, list<array{owner: int, item: CData, check: bool, window: ?Window, bar: bool, icon: ?MenuIcon, image: ?CData, label: ?CData}>>
     *     the native items of each item's id
     */
    private array $items = [];
    /** @var array<int, string> the items' roles, by id */
    private array $roles = [];
    /** @var array<int, array<string, true>> the shortcuts of the editing roles' items, which the content gets first, by accel group's address: "keyval:mods" */
    public array $roleKeys = [];
    /** @var array<int, int> the owner of the items a submenu was filled with anew, by the GtkMenu's address */
    private array $subOwners = [];
    /** @var list<CData> the main loops of the context menus showing, innermost last */
    private array $loops = [];
    /** gtk_check_menu_item_set_active emits "activate" as if the user clicked: set while a check item is changed here. */
    private bool $settingState = false;
    private CData $onActivate, $onOpen, $onDeactivate;

    public function __construct(private readonly AppHandler $h)
    {
        $f = Gtk::ffi();
        $this->onActivate = $f->new('HWidget', false);
        $this->onActivate->fn = fn($item, $data) => $this->activated($item, Mem::addr($data));
        $this->onOpen = $f->new('HWidget', false);
        $this->onOpen->fn = fn($menu, $data) => $this->h->menuWillOpen(Mem::addr($data));
        $this->onDeactivate = $f->new('HWidget', false);
        $this->onDeactivate->fn = function ($menu, $data): void {
            if ($this->loops !== []) { Gtk::ffi()->g_main_loop_quit($this->loops[count($this->loops) - 1]); }
        };
    }

    /** A new owner of items, gone with $parent's when given. */
    public function newOwner(?int $parent = null): int
    {
        $o = ++$this->owners;
        if ($parent !== null) { $this->parents[$o] = $parent; }
        return $o;
    }

    /** Forgets the native items of $owner, and of the owners made in it. */
    public function dropOwner(int $owner): void
    {
        foreach ($this->parents as $child => $parent) {
            if ($parent === $owner) {
                unset($this->parents[$child]);
                $this->dropOwner($child);
            }
        }
        foreach ($this->items as $id => $list) {
            $kept = array_values(array_filter($list, fn(array $n): bool => $n['owner'] !== $owner));
            if ($kept === []) { unset($this->items[$id]); } else { $this->items[$id] = $kept; }
        }
    }

    /** Fills a GtkMenuShell with $items; a menu bar's ($bar) own items show no icons. @param list<MenuItem> $items */
    public function fill(CData $shell, array $items, ?CData $accel, int $owner, ?Window $window, bool $bar): void
    {
        foreach ($items as $it) {
            if ($it->alternate) {
                continue; // macOS's, shown while Option is held
            }
            Gtk::ffi()->gtk_menu_shell_append($shell, $this->item($it, $accel, $owner, $window, $bar));
        }
    }

    /** The GtkMenuItem of $it, tracked as $owner's (mygo's buildItem). */
    private function item(MenuItem $it, ?CData $accel, int $owner, ?Window $window, bool $bar): CData
    {
        $f = Gtk::ffi();
        $check = false;
        if ($it->type === MenuItem::SEPARATOR) {
            $item = $f->gtk_separator_menu_item_new();
        } elseif ($it->type === MenuItem::CHECKBOX || $it->type === MenuItem::RADIO) {
            $item = $f->gtk_check_menu_item_new_with_label($it->label);
            $f->gtk_check_menu_item_set_draw_as_radio($item, $it->type === MenuItem::RADIO ? 1 : 0);
            $check = true;
        } else {
            $item = $f->gtk_menu_item_new_with_label($it->label);
        }
        if ($it->submenu !== null) {
            $sub = $f->gtk_menu_new();
            $this->fill($sub, $it->submenu->items, $accel, $owner, $window, false);
            $f->gtk_menu_item_set_submenu($item, $sub);
            if ($it->opens) {
                Gtk::connectShared($sub, 'show', $this->onOpen->fn, $it->id);
            }
        } elseif ($it->type !== MenuItem::SEPARATOR && $it->type !== MenuItem::HEADER) {
            Gtk::connectShared($item, 'activate', $this->onActivate->fn, $it->id);
            $this->roles[$it->id] = $it->role;
            if ($accel !== null && $it->accelerator !== '' && ($key = self::accelerator($it->accelerator)) !== null) {
                $f->gtk_widget_add_accelerator($item, 'activate', $accel, $key[0], $key[1], 1); // GTK_ACCEL_VISIBLE
                if (in_array($it->role, self::EDIT_ROLES, true)) {
                    $this->roleKeys[Mem::addr($accel)][$f->gdk_keyval_to_lower($key[0]) . ':' . $key[1]] = true;
                }
            }
        }
        $this->apply($item, $it, $check);
        $n = ['owner' => $owner, 'item' => $item, 'check' => $check, 'window' => $window, 'bar' => $bar, 'icon' => null, 'image' => null, 'label' => null];
        if ($it->icon !== null && $it->type !== MenuItem::SEPARATOR && !$bar) {
            $n['icon'] = $it->icon;
            if ($window !== null) { $this->setIcon($n, $it->icon); } // a tray's once its menu is exported (exportIcons)
        }
        $this->items[$it->id][] = $n;
        return $item;
    }

    /** Gives a native item $it's state (mygo's applyState); a header is an item that does nothing. */
    private function apply(CData $item, MenuItem $it, bool $check): void
    {
        $f = Gtk::ffi();
        $f->gtk_widget_set_sensitive($item, $it->enabled && $it->type !== MenuItem::HEADER ? 1 : 0);
        $f->gtk_widget_set_no_show_all($item, $it->visible ? 0 : 1);
        $f->gtk_widget_set_visible($item, $it->visible ? 1 : 0);
        if ($check) {
            $this->settingState = true;
            $f->gtk_check_menu_item_set_active($item, $it->checked ? 1 : 0);
            $this->settingState = false;
        }
        if ($it->toolTip !== '') {
            $f->gtk_widget_set_tooltip_text($item, $it->toolTip);
        }
    }

    /**
     * An item was activated: an editing role's command goes to the
     * content of its window, or of the active one; the others are the
     * app's (mygo's cbMenuActivate).
     */
    private function activated(CData $item, int $id): void
    {
        if ($this->settingState) {
            return;
        }
        if (in_array($this->roles[$id] ?? '', self::EDIT_ROLES, true)) {
            $w = null;
            foreach ($this->items[$id] ?? [] as $n) {
                if (Mem::addr($n['item']) === Mem::addr($item)) { $w = $n['window']; break; }
            }
            $w = $w ?? Window::active()?->native();
            if ($w instanceof Window) { $w->command($this->roles[$id]); }
            return;
        }
        $this->h->menuItemClicked($id);
    }

    /**
     * Activates the item of menu bar $bar at the end of $path, the labels
     * along it, as a click does (mygo's TestActivateMenuItem).
     * @param list<string> $path
     */
    public function activate(CData $bar, array $path): void
    {
        $f = Gtk::ffi();
        $shell = $bar;
        foreach ($path as $i => $label) {
            $item = $this->child($shell, $label) ?? throw new \RuntimeException('no menu item ' . json_encode($label, JSON_UNESCAPED_UNICODE));
            if ($i === count($path) - 1) {
                $f->gtk_menu_item_activate($item);
                return;
            }
            $shell = $f->gtk_menu_item_get_submenu($item) ?? throw new \RuntimeException('menu ' . json_encode($label, JSON_UNESCAPED_UNICODE) . ' has no submenu');
        }
    }

    /** The item of $shell labeled $label: an item with an icon holds its label in a box with the image (setIcon). */
    private function child(CData $shell, string $label): ?CData
    {
        $f = Gtk::ffi();
        $list = $f->gtk_container_get_children($shell);
        $found = null;
        for ($node = $list; $node !== null && $found === null; $node = $node->next) {
            $item = $node->data;
            $own = null;
            foreach ($this->items as $ns) {
                foreach ($ns as $n) {
                    if ($n['label'] !== null && Mem::addr($n['item']) === Mem::addr($item)) { $own = $n['label']; }
                }
            }
            $text = $own !== null ? $f->gtk_label_get_label($own) : $f->gtk_menu_item_get_label($item);
            if ($text === $label) {
                $found = $item;
            }
        }
        if ($list !== null) { $f->g_list_free($list); }
        return $found;
    }

    /** Gives every native item of $it's id its state (mygo's UpdateMenuItem). */
    public function update(MenuItem $it): void
    {
        $this->appMenu?->apply($it); // new windows build the application menu as it is now
        $f = Gtk::ffi();
        foreach ($this->items[$it->id] ?? [] as $i => $n) {
            if ($it->type !== MenuItem::SEPARATOR) {
                if ($n['label'] !== null) {
                    $f->gtk_label_set_label($n['label'], $it->label);
                } else {
                    $f->gtk_menu_item_set_label($n['item'], $it->label);
                }
                if ($it->icon !== $n['icon'] && !$n['bar']) {
                    $this->items[$it->id][$i]['icon'] = $it->icon;
                    $this->setIcon($this->items[$it->id][$i], $it->icon);
                }
            }
            $this->apply($n['item'], $it, $n['check']);
        }
    }

    /** Fills the submenus of the native items of $id with $m's items anew (myphp's, for a submenu brought up to date as it opens). */
    public function replaceSubmenu(int $id, Menu $m): void
    {
        $f = Gtk::ffi();
        foreach ($this->items[$id] ?? [] as $n) {
            $sub = $f->gtk_menu_item_get_submenu($n['item']);
            if ($sub === null) {
                continue;
            }
            $addr = Mem::addr($sub);
            if (isset($this->subOwners[$addr])) { $this->dropOwner($this->subOwners[$addr]); }
            $this->subOwners[$addr] = $o = $this->newOwner($n['owner']);
            $list = $f->gtk_container_get_children($sub);
            for ($node = $list; $node !== null; $node = $node->next) {
                $f->gtk_widget_destroy($node->data);
            }
            $f->g_list_free($list);
            $this->fill($sub, $m->items, null, $o, $n['window'], false);
            $f->gtk_widget_show_all($sub);
        }
    }

    /**
     * Shows $m as a context menu over the content of $w, at $at points
     * from its top-left corner, else at the press or key that brought it
     * up, else at the pointer; it returns once the menu closes (mygo's
     * PopupMenu). @param ?array{0: float, 1: float} $at
     */
    public function popup(Menu $m, Window $w, ?array $at): void
    {
        $f = Gtk::ffi();
        $owner = $this->newOwner();
        $menu = $f->gtk_menu_new();
        try {
            $this->fill($menu, $m->items, null, $owner, $w, false);
            $f->gtk_widget_show_all($menu);
            [$anchor, $dx, $dy] = $w->contentWindow();
            $event = $w->popupTrigger();
            $corner = 1; // GDK_GRAVITY_NORTH_WEST
            if ($m->rightToLeft) {
                $f->gtk_widget_set_direction($menu, 2); // GTK_TEXT_DIR_RTL: laid out from the right
                $corner = 3; // GDK_GRAVITY_NORTH_EAST: opening to the left of the point
            }
            $r = $f->new('GdkRectangle');
            if ($at !== null && $anchor !== null) {
                [$r->x, $r->y, $r->width, $r->height] = [(int)round($at[0]) + $dx, (int)round($at[1]) + $dy, 1, 1];
                $f->gtk_menu_popup_at_rect($menu, $anchor, \FFI::addr($r), 1, $corner, $event);
            } elseif ($event !== null) {
                $f->gtk_menu_popup_at_pointer($menu, $event);
            } elseif ($anchor !== null) { // no press to anchor to: at the pointer
                $x = $f->new('int[1]'); $y = $f->new('int[1]');
                $f->gdk_window_get_device_position($anchor, $f->gdk_seat_get_pointer($f->gdk_display_get_default_seat($f->gdk_display_get_default())), $x, $y, null);
                [$r->x, $r->y, $r->width, $r->height] = [$x[0], $y[0], 1, 1];
                $f->gtk_menu_popup_at_rect($menu, $anchor, \FFI::addr($r), 1, 1, null);
            }
            if (!$f->gtk_widget_get_visible($menu)) {
                Logger::warn('GTK could not show the context menu', 'linux');
                return;
            }
            $loop = $f->g_main_loop_new(null, 0);
            Gtk::connectShared($menu, 'deactivate', $this->onDeactivate->fn, 0);
            $this->loops[] = $loop;
            $f->g_main_loop_run($loop);
            array_pop($this->loops);
            $f->g_main_loop_unref($loop);
        } finally {
            $f->gtk_widget_destroy($menu);
            $this->dropOwner($owner);
        }
    }

    /** The keyval and GDK modifiers of an accelerator, null for one GTK has no key of (mygo's gtkAccelerator). @return ?array{0: int, 1: int} */
    public static function accelerator(string $accel): ?array
    {
        try {
            $a = Accelerator::parse($accel, 'Linux');
        } catch (\UnexpectedValueException $e) {
            Logger::warn("the menu item's accelerator is left out: {$e->getMessage()}", 'linux');
            return null;
        }
        $f = Gtk::ffi();
        $mods = ($a->has(Accelerator::SHIFT) ? 1 : 0) | ($a->has(Accelerator::CTRL) ? 1 << 2 : 0) | ($a->has(Accelerator::ALT) ? 1 << 3 : 0) | ($a->has(Accelerator::SUPER) ? 1 << 26 : 0);
        if (isset(self::KEY_NAMES[$a->key])) {
            $key = $f->gdk_keyval_from_name(self::KEY_NAMES[$a->key]);
        } elseif (preg_match('/^F\d+$/', $a->key) === 1) {
            $key = $f->gdk_keyval_from_name($a->key);
        } elseif (preg_match('/^Num(\d)$/', $a->key, $m) === 1) {
            $key = $f->gdk_keyval_from_name('KP_' . $m[1]);
        } else {
            $r = mb_ord($a->key, 'UTF-8');
            $key = $f->gdk_unicode_to_keyval($r);
            if (($mods & 1) !== 0 && preg_match('/^\pL$/u', $a->key) !== 1) {
                $key = self::shifted($key);
            }
        }
        return $key === 0 ? null : [$key, $mods];
    }

    /**
     * The keyval the key typing $keyval types with Shift, in the keyboard's
     * layout: plus for equal on a US keyboard. GTK matches a shortcut with
     * Shift by what the key types, Shift consumed, so Alt+Shift+= is
     * Alt+Shift+plus to it. $keyval when no key types it (mygo's shifted).
     */
    private static function shifted(int $keyval): int
    {
        $f = Gtk::ffi();
        $km = $f->gdk_keymap_get_for_display($f->gdk_display_get_default());
        $keys = $f->new('void*[1]'); $n = $f->new('int[1]');
        if ($km === null || !$f->gdk_keymap_get_entries_for_keyval($km, $keyval, $keys, $n) || $n[0] === 0) {
            return $keyval;
        }
        try {
            $entries = $f->cast('unsigned int*', $keys[0]); // GdkKeymapKey: keycode, group, level
            for ($i = 0; $i < $n[0]; $i++) {
                [$code, $group, $level] = [$entries[$i * 3], $entries[$i * 3 + 1], $entries[$i * 3 + 2]];
                if ($level !== 0) {
                    continue;
                }
                $sym = $f->new('unsigned int[1]'); $eg = $f->new('int[1]'); $lv = $f->new('int[1]'); $consumed = $f->new('int[1]');
                if ($f->gdk_keymap_translate_keyboard_state($km, $code, 1, $group, $sym, $eg, $lv, $consumed) && $sym[0] !== 0) {
                    return $sym[0];
                }
            }
        } finally {
            $f->g_free($keys[0]);
        }
        return $keyval;
    }

    /**
     * Shows $ic in a native item, null for none (mygo's setItemIcon): the
     * item gets a box holding an image and its label, the image first, as
     * GTK 3 advises in place of the deprecated GtkImageMenuItem.
     * @param array{owner: int, item: CData, check: bool, window: ?Window, bar: bool, icon: ?MenuIcon, image: ?CData, label: ?CData} $n
     */
    private function setIcon(array &$n, ?MenuIcon $ic): void
    {
        if ($n['window'] === null) {
            self::trayIcon($n['item'], $ic);
            return;
        }
        $f = Gtk::ffi();
        if ($ic === null && $n['image'] === null) {
            return;
        }
        if ($n['image'] === null) {
            $label = $f->gtk_bin_get_child($n['item']);
            if ($label === null) {
                return;
            }
            $f->g_object_ref($label);
            $f->gtk_container_remove($n['item'], $label);
            $box = $f->gtk_box_new(0, 6); // GTK_ORIENTATION_HORIZONTAL
            $n['image'] = $f->gtk_image_new();
            $f->gtk_widget_set_no_show_all($n['image'], 1);
            $f->gtk_box_pack_start($box, $n['image'], 0, 0, 0);
            $f->gtk_box_pack_start($box, $label, 1, 1, 0);
            $f->g_object_unref($label);
            $f->gtk_container_add($n['item'], $box);
            $f->gtk_widget_show($box);
            $f->gtk_widget_show($label);
            $n['label'] = $label;
        }
        $surface = null;
        if ($ic !== null) {
            $scale = max($f->gtk_widget_get_scale_factor($n['item']), 1);
            $pix = self::pixbuf($ic, MenuIcon::SIZE * $scale);
            if ($pix !== null) {
                $surface = $f->gdk_cairo_surface_create_from_pixbuf($pix, $scale, null);
            }
        }
        $f->gtk_image_set_from_surface($n['image'], $surface);
        $f->gtk_widget_set_visible($n['image'], $surface !== null ? 1 : 0);
        if ($surface !== null) {
            $f->cairo_surface_destroy($surface);
        }
    }

    /** Gives the items of $owner, a tray's menu just exported, their icons. */
    public function exportIcons(int $owner): void
    {
        foreach ($this->items as $list) {
            foreach ($list as $n) {
                if ($n['owner'] === $owner && $n['icon'] !== null) { self::trayIcon($n['item'], $n['icon']); }
            }
        }
    }

    private static ?\FFI $dbusmenu = null;

    /**
     * Gives the item a tray's menu exports for a GTK menu item an icon,
     * null for none (mygo's setTrayItemIcon): the panel draws a tray's menu,
     * which libdbusmenu exports, from the PNG of the item's "icon-data",
     * the image at most twice the size the panel draws it at.
     */
    private static function trayIcon(CData $item, ?MenuIcon $ic): void
    {
        try {
            self::$dbusmenu ??= \FFI::cdef('void* dbusmenu_gtk_parse_get_cached_item(void*);
                int dbusmenu_menuitem_property_set_byte_array(void*, const char*, const char*, size_t); void dbusmenu_menuitem_property_remove(void*, const char*);', 'libdbusmenu-gtk3.so.4');
        } catch (\FFI\Exception $e) {
            Logger::warn('the tray\'s menu shows no icons without libdbusmenu-gtk3: ' . $e->getMessage(), 'linux');
            return;
        }
        $d = self::$dbusmenu;
        $mi = $d->dbusmenu_gtk_parse_get_cached_item($d->cast('void*', Mem::addr($item)));
        if ($mi === null) {
            return;
        }
        $png = $ic === null ? '' : self::trayPNG($ic);
        if ($png === '') {
            $d->dbusmenu_menuitem_property_remove($mi, 'icon-data');
            return;
        }
        $d->dbusmenu_menuitem_property_set_byte_array($mi, 'icon-data', $png, strlen($png));
    }

    /** The PNG a tray's menu exports of an icon: the image, shrunk to twice the size the panel draws it at (mygo's trayIconPNG). */
    private static function trayPNG(MenuIcon $ic): string
    {
        $f = Gtk::ffi();
        $box = 2 * MenuIcon::SIZE;
        $full = self::pixbuf($ic, 0);
        if ($full === null) {
            return '';
        }
        if ($f->gdk_pixbuf_get_width($full) <= $box && $f->gdk_pixbuf_get_height($full) <= $box) {
            return $ic->png;
        }
        $sized = self::pixbuf($ic, $box);
        return $sized === null ? $ic->png : (Clipboard::png($sized) ?: $ic->png);
    }

    /** $ic's image fit to a box of $box pixels (0: as it is), decoded once with the sizes asked for kept as long as the icon (mygo's iconPixbuf); null for an image GDK cannot read. */
    private static function pixbuf(MenuIcon $ic, int $box): ?CData
    {
        $f = Gtk::ffi();
        $p = $ic->native(function () use ($ic, $f): ?\ArrayObject {
            $loader = $f->gdk_pixbuf_loader_new();
            $ok = $f->gdk_pixbuf_loader_write($loader, $ic->png, strlen($ic->png), null) && $f->gdk_pixbuf_loader_close($loader, null);
            $pix = $ok ? $f->gdk_pixbuf_loader_get_pixbuf($loader) : null;
            if ($pix !== null) { $f->g_object_ref($pix); }
            $f->g_object_unref($loader);
            if ($pix === null) {
                Logger::warn('GDK could not read a menu icon', 'linux');
                return null;
            }
            return new \ArrayObject(['image' => $pix, 'sized' => []]);
        }, function (\ArrayObject $p) use ($f): void {
            $f->g_object_unref($p['image']);
            foreach ($p['sized'] as $s) { $f->g_object_unref($s); }
        });
        if ($p === null) {
            return null;
        }
        if ($box === 0) {
            return $p['image'];
        }
        if (!isset($p['sized'][$box])) {
            [$w, $h] = [$f->gdk_pixbuf_get_width($p['image']), $f->gdk_pixbuf_get_height($p['image'])];
            [$w, $h] = $w >= $h ? [$box, max(1, intdiv($h * $box + intdiv($w, 2), $w))] : [max(1, intdiv($w * $box + intdiv($h, 2), $h)), $box]; // mygo's FitIcon
            $sized = $p['sized'];
            $sized[$box] = $f->gdk_pixbuf_scale_simple($p['image'], $w, $h, 3); // GDK_INTERP_HYPER, the finest when shrinking
            $p['sized'] = $sized;
        }
        return $p['sized'][$box];
    }
}
