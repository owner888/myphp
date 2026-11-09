<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\Native\Mem;
use MyPHP\Platform\{Accelerator, AppHandler, Menu, MenuItem};

/**
 * Win32's menus (mygo's windows menu.go): the menu bar of each window,
 * which shows the application menu, its keyboard shortcuts, and the
 * context menus, popup menus' items drawn in Windows 11's style there
 * (MenuDraw). WM_COMMAND reports 16-bit command
 * ids, which map back to the items they were built from; the items are
 * tracked per owner (a window's menu bar, a popup, a submenu filled anew
 * as it opens) and forgotten with it.
 */
final class Menus
{
    /** The roles of the editing commands, which the content of the window performs (mygo's editRole, Linux's EDIT_ROLES). */
    private const EDIT_ROLES = ['undo', 'redo', 'cut', 'copy', 'paste', 'pasteAndMatchStyle', 'delete', 'selectAll'];
    private const MF_STRING = 0x0, MF_GRAYED = 0x1, MF_CHECKED = 0x8, MF_POPUP = 0x10, MF_SEPARATOR = 0x800, MF_BYPOSITION = 0x400;
    /** The virtual keys of the accelerators' named keys (mygo's namedVirtualKeys). */
    private const NAMED_KEYS = [
        'Enter' => 0x0D, 'Tab' => 0x09, 'Space' => 0x20, 'Backspace' => 0x08, 'Delete' => 0x2E, 'Insert' => 0x2D,
        'Escape' => 0x1B, 'Up' => 0x26, 'Down' => 0x28, 'Left' => 0x25, 'Right' => 0x27, 'Home' => 0x24, 'End' => 0x23,
        'PageUp' => 0x21, 'PageDown' => 0x22, 'VolumeUp' => 0xAF, 'VolumeDown' => 0xAE, 'VolumeMute' => 0xAD,
        'MediaNextTrack' => 0xB0, 'MediaPreviousTrack' => 0xB1, 'MediaStop' => 0xB2, 'MediaPlayPause' => 0xB3,
        'PrintScreen' => 0x2C, 'NumDec' => 0x6E, 'NumAdd' => 0x6B, 'NumSub' => 0x6D, 'NumMult' => 0x6A, 'NumDiv' => 0x6F,
        'CapsLock' => 0x14, 'NumLock' => 0x90, 'ScrollLock' => 0x91,
        ',' => 0xBC, '-' => 0xBD, '.' => 0xBE, '=' => 0xBB, '+' => 0xBB, ';' => 0xBA, '/' => 0xBF, '`' => 0xC0,
        '[' => 0xDB, '\\' => 0xDC, ']' => 0xDD, "'" => 0xDE,
    ];

    /** The application menu, which new windows' menu bars show, kept current by update(). */
    public ?Menu $appMenu = null;
    /** @var array<int, array{uid: int, role: string, owner: int, menu: CData, radio: bool, accel: string, look: int}> the items, by command id; look the key of the look of one drawn here, 0 for one Windows draws */
    private array $entries = [];
    private int $next = 0;
    private int $owners = 0;
    /** @var array<int, int> the owner each owner was made in, which goes with it */
    private array $parents = [];
    /** @var array<int, list<array{menu: CData, owner: int, window: ?Window}>> the submenus of each item's id */
    private array $subs = [];
    /** @var array<int, int> the item whose submenu tells it opens (MenuItem::$opens), by the submenu's address */
    private array $opens = [];
    /** @var array<int, int> the owner of the items a submenu was filled with anew, by its address */
    private array $subOwners = [];

    public function __construct(private readonly AppHandler $h) {}

    /** A new owner of items, gone with $parent's when given. */
    public function newOwner(?int $parent = null): int
    {
        $o = ++$this->owners;
        if ($parent !== null) { $this->parents[$o] = $parent; }
        return $o;
    }

    /** Forgets the items of $owner, and of the owners made in it (mygo's drop). */
    public function dropOwner(int $owner): void
    {
        foreach ($this->parents as $child => $parent) {
            if ($parent === $owner) {
                unset($this->parents[$child]);
                $this->dropOwner($child);
            }
        }
        $this->entries = array_filter($this->entries, fn(array $e): bool => $e['owner'] !== $owner);
        MenuDraw::dropOwner($owner);
        foreach ($this->subs as $id => $list) {
            $kept = array_values(array_filter($list, fn(array $s): bool => $s['owner'] !== $owner));
            foreach ($list as $s) {
                if ($s['owner'] === $owner) { unset($this->opens[Mem::addr($s['menu'])], $this->subOwners[Mem::addr($s['menu'])]); }
            }
            if ($kept === []) { unset($this->subs[$id]); } else { $this->subs[$id] = $kept; }
        }
    }

    /**
     * A native menu bar ($bar) or popup menu of $m (mygo's buildMenu), its
     * items $owner's; the shortcuts of its items go into $accels, by
     * "virtual key:modifiers", for a window's bar.
     * @param ?array<string, int> $accels
     */
    public function build(Menu $m, bool $bar, int $owner, ?array &$accels, ?Window $window): CData
    {
        $u = Win32::user32();
        $h = $bar ? $u->CreateMenu() : $u->CreatePopupMenu();
        $this->fill($h, $m->items, $owner, $accels, $window, $bar);
        return $h;
    }

    /**
     * Appends $items to menu $h; a popup's ($bar false) are drawn here on
     * Windows 11 (MenuDraw), leaving room for checks when any item has one.
     * @param list<MenuItem> $items @param ?array<string, int> $accels
     */
    private function fill(CData $h, array $items, int $owner, ?array &$accels, ?Window $window, bool $bar = false): void
    {
        $u = Win32::user32();
        $drawn = !$bar && MenuDraw::fluent();
        $items = array_values(array_filter($items, fn(MenuItem $it): bool => $it->visible && !$it->alternate)); // the alternates are macOS's, shown while Option is held
        $column = false;
        foreach ($items as $it) {
            $column = $column || $it->type === MenuItem::CHECKBOX || $it->type === MenuItem::RADIO || $it->checked;
        }
        $pos = $u->GetMenuItemCount($h);
        $look = function (array $l, int $cmd = 0) use ($h, &$pos, $drawn, $owner, $column): int {
            $at = $pos++;
            if (!$drawn) {
                return 0;
            }
            $key = MenuDraw::ownerDraw($h, $at, $l + ['owner' => $owner, 'column' => $column, 'first' => $at === 0, 'last' => false, 'label' => '', 'accel' => '', 'separator' => false, 'submenu' => false, 'radio' => false]);
            if ($cmd !== 0) { $this->entries[$cmd]['look'] = $key; }
            return $key;
        };
        $last = 0;
        foreach ($items as $it) {
            if ($it->type === MenuItem::SEPARATOR) {
                $u->AppendMenuW($h, self::MF_SEPARATOR, 0, null);
                $last = $look(['separator' => true]);
                continue;
            }
            $flags = self::MF_STRING | ($it->enabled && $it->type !== MenuItem::HEADER ? 0 : self::MF_GRAYED);
            if ($it->submenu !== null) {
                $sub = $u->CreatePopupMenu();
                $this->fill($sub, $it->submenu->items, $owner, $accels, $window);
                $u->AppendMenuW($h, $flags | self::MF_POPUP, Mem::addr($sub), Win32::wide($it->label));
                $last = $look(['label' => $it->label, 'submenu' => true]);
                $this->subs[$it->id][] = ['menu' => $sub, 'owner' => $owner, 'window' => $window];
                if ($it->opens) { $this->opens[Mem::addr($sub)] = $it->id; }
                continue;
            }
            if ($it->type === MenuItem::HEADER) {
                $u->AppendMenuW($h, $flags, 0, Win32::wide($it->label));
                $last = $look(['label' => $it->label]);
                continue;
            }
            $accel = '';
            $cmd = $this->add(['uid' => $it->id, 'role' => $it->role, 'owner' => $owner, 'menu' => $h, 'radio' => $it->type === MenuItem::RADIO, 'accel' => '', 'look' => 0]);
            if ($it->accelerator !== '') {
                try {
                    $a = Accelerator::parse($it->accelerator, 'Windows');
                    $accel = self::text($a);
                    $this->entries[$cmd]['accel'] = $accel;
                    if ($accels !== null && ($vk = self::virtualKey($a->key)) !== null) {
                        $accels["$vk:{$a->modifiers}"] = $cmd;
                    }
                } catch (\UnexpectedValueException $e) {
                    Logger::warn("the menu item's accelerator is left out: {$e->getMessage()}", 'windows');
                }
            }
            $label = $accel === '' ? $it->label : "{$it->label}\t$accel";
            $u->AppendMenuW($h, $flags | ($it->checked ? self::MF_CHECKED : 0), $cmd, Win32::wide($label));
            $last = $look(['label' => $it->label, 'accel' => $accel, 'radio' => $it->type === MenuItem::RADIO], $cmd);
            if ($it->type === MenuItem::RADIO && $last === 0) {
                self::setState($h, $cmd, $label, $it->enabled, $it->checked, true, false);
            }
        }
        if ($last !== 0) {
            MenuDraw::$looks[$last]['last'] = true; // the menu's padding below it
        }
    }

    /** A command id of the items not taken, from 1: 0xF000 and up are the system's. @param array{uid: int, role: string, owner: int, menu: CData, radio: bool, accel: string, look: int} $e */
    private function add(array $e): int
    {
        do {
            $this->next = $this->next + 1 >= 0xF000 ? 1 : $this->next + 1;
        } while (isset($this->entries[$this->next]));
        $this->entries[$this->next] = $e;
        return $this->next;
    }

    /** Gives an item its label, state and kind (mygo's setItemInfo): a radio item shows a bullet; one drawn here ($drawn) stays so. */
    private static function setState(CData $menu, int $cmd, string $label, bool $enabled, bool $checked, bool $radio, bool $drawn): void
    {
        $u = Win32::user32();
        $mi = $u->new('MENUITEMINFOW');
        $mi->cbSize = FFI::sizeof($mi);
        $mi->fMask = 0x1 | 0x100 | 0x40; // MIIM_STATE, MIIM_FTYPE, MIIM_STRING
        $mi->fType = ($radio ? 0x200 : 0) | ($drawn ? 0x100 : 0); // MFT_RADIOCHECK, MFT_OWNERDRAW
        $mi->fState = ($checked ? 0x8 : 0) | ($enabled ? 0 : 0x3); // MFS_CHECKED, MFS_DISABLED
        $text = Win32::wide($label);
        $mi->dwTypeData = $u->cast('uint16_t *', FFI::addr($text[0]));
        $u->SetMenuItemInfoW($menu, $cmd, 0, FFI::addr($mi));
    }

    /**
     * Runs the item of command id $cmd (mygo's menuCommand): an editing
     * role's command goes to the content of its window, or of the active
     * one; the others are the app's.
     */
    public function command(int $cmd, ?Window $w): void
    {
        $e = $this->entries[$cmd] ?? null;
        if ($e === null) {
            return;
        }
        $w ??= Window::active();
        if ($w !== null && in_array($e['role'], self::EDIT_ROLES, true)) {
            $w->command($e['role']);
            return;
        }
        $this->h->menuItemClicked($e['uid']);
    }

    /**
     * Runs the item of a window's menu bar whose shortcut the key down $vk
     * is, with the modifiers held now, and reports whether it ran one
     * (mygo's menuShortcut): the app's own items take their keys before
     * the content, which gets only what they leave; items with a role
     * ($roles), only those the content leaves, as Ctrl+C copies in a field
     * and is Edit › Copy's.
     * @param array<string, int> $accels
     */
    public function shortcut(array $accels, int $vk, bool $roles, Window $w): bool
    {
        $cmd = $accels["$vk:" . self::modifiers()] ?? null;
        $e = $cmd === null ? null : ($this->entries[$cmd] ?? null);
        if ($e === null || ($e['role'] !== '') !== $roles) {
            return false;
        }
        $state = Win32::user32()->GetMenuState($e['menu'], $cmd, 0); // MF_BYCOMMAND
        if ($state !== -1 && ($state & self::MF_GRAYED) !== 0) {
            return false;
        }
        $this->command($cmd, $w);
        return true;
    }

    /** Gives every native item of $it's id its label and state (mygo's UpdateMenuItem): the snapshots kept build the menus to come as it is now. */
    public function update(MenuItem $it): void
    {
        $this->appMenu?->apply($it);
        foreach ($this->entries as $cmd => $e) {
            if ($e['uid'] !== $it->id) {
                continue;
            }
            if ($e['look'] !== 0 && isset(MenuDraw::$looks[$e['look']])) { MenuDraw::$looks[$e['look']]['label'] = $it->label; }
            self::setState($e['menu'], $cmd, $e['accel'] === '' ? $it->label : "{$it->label}\t{$e['accel']}", $it->enabled, $it->checked, $e['radio'], $e['look'] !== 0);
        }
        foreach (Window::all() as $w) {
            $w->menuChanged();
        }
    }

    /** Fills the submenus of the native items of $id with $m's items anew (myphp's, for a submenu brought up to date as it opens). */
    public function replaceSubmenu(int $id, Menu $m): void
    {
        $u = Win32::user32();
        foreach ($this->subs[$id] ?? [] as $s) {
            $addr = Mem::addr($s['menu']);
            if (isset($this->subOwners[$addr])) { $this->dropOwner($this->subOwners[$addr]); }
            $this->subOwners[$addr] = $o = $this->newOwner($s['owner']);
            for ($n = $u->GetMenuItemCount($s['menu']); $n > 0; $n--) {
                $u->DeleteMenu($s['menu'], 0, self::MF_BYPOSITION); // and the submenus in it
            }
            $none = null;
            $this->fill($s['menu'], $m->items, $o, $none, $s['window']);
        }
    }

    /** A popup is about to show (WM_INITMENUPOPUP): the core hears of a submenu that tells it opens, which brings its items up to date. */
    public function opening(int $hmenu): void
    {
        if (isset($this->opens[$hmenu])) {
            $this->h->menuWillOpen($this->opens[$hmenu]);
        }
    }

    /**
     * Shows $m as a context menu over $w's content, at $at points from its
     * top-left corner, else at the pointer, and runs the item chosen once it
     * closes (mygo's PopupMenu). @param ?array{0: float, 1: float} $at
     */
    public function popup(Menu $m, Window $w, ?array $at): void
    {
        $u = Win32::user32();
        $owner = $this->newOwner();
        $none = null;
        $menu = $this->build($m, false, $owner, $none, $w);
        try {
            $pt = $u->new('POINT');
            if ($at !== null) {
                $s = $w->backingScale();
                $pt->x = (int)round($at[0] * $s); $pt->y = (int)round($at[1] * $s);
                $u->ClientToScreen($w->hwnd, FFI::addr($pt));
            } else {
                $u->GetCursorPos(FFI::addr($pt));
            }
            $flags = $m->rightToLeft ? 0x8 | 0x8000 : 0; // TPM_RIGHTALIGN, TPM_LAYOUTRTL: its right edge at the point, laid out from the right
            $cmd = $this->track($menu, $w->hwnd, $pt->x, $pt->y, $flags);
        } finally {
            $u->DestroyMenu($menu);
        }
        if ($cmd !== 0) {
            $this->command($cmd, $w);
        }
        $this->dropOwner($owner);
    }

    /** Shows a popup menu over $owner and returns the command chosen, or 0 (mygo's trackPopup); TrackPopupMenuEx's $flags besides those it always gives. */
    private function track(CData $menu, CData $owner, int $x, int $y, int $flags): int
    {
        $u = Win32::user32();
        $u->SetForegroundWindow($owner); // or the menu does not close as the user clicks elsewhere
        $cmd = $u->TrackPopupMenuEx($menu, 0x100 | 0x2 | $flags, $x, $y, $owner, null); // TPM_RETURNCMD, TPM_RIGHTBUTTON
        $u->PostMessageW($owner, 0, 0, 0); // WM_NULL, as the documentation asks
        return $cmd & 0xFFFF;
    }

    /**
     * Runs the item of menu bar $bar at the end of $path, the labels along
     * it, as choosing it does (mygo's TestActivateMenuItem).
     * @param list<string> $path
     */
    public function activate(CData $bar, array $path, Window $w): void
    {
        $u = Win32::user32();
        $menu = $bar;
        foreach ($path as $i => $label) {
            $at = null;
            for ($j = 0, $n = $u->GetMenuItemCount($menu); $j < $n && $at === null; $j++) {
                $buf = $u->new('uint16_t[256]');
                $len = $u->GetMenuStringW($menu, $j, $buf, 256, self::MF_BYPOSITION);
                $text = $len > 0 ? (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $len * 2)) : '';
                if (explode("\t", $text)[0] === $label) { $at = $j; }
            }
            if ($at === null) {
                throw new \RuntimeException('no menu item ' . json_encode($label, JSON_UNESCAPED_UNICODE));
            }
            if ($i === count($path) - 1) {
                $this->command($u->GetMenuItemID($menu, $at) & 0xFFFF, $w);
                return;
            }
            $menu = $u->GetSubMenu($menu, $at) ?? throw new \RuntimeException('menu ' . json_encode($label, JSON_UNESCAPED_UNICODE) . ' has no submenu');
            $this->opening(Mem::addr($menu));
        }
    }

    /** The modifiers held now, as an accelerator's (mygo's currentModifiers). */
    private static function modifiers(): int
    {
        $u = Win32::user32();
        $down = fn(int $vk): bool => ($u->GetKeyState($vk) & 0x8000) !== 0;
        return ($down(0x11) ? Accelerator::CTRL : 0) | ($down(0x12) ? Accelerator::ALT : 0) | ($down(0x10) ? Accelerator::SHIFT : 0) | ($down(0x5B) || $down(0x5C) ? Accelerator::SUPER : 0);
    }

    /** An accelerator as Windows's menus show it, "Ctrl+Shift+Z" (mygo's acceleratorText). */
    private static function text(Accelerator $a): string
    {
        $parts = [];
        foreach ([Accelerator::CTRL => 'Ctrl', Accelerator::ALT => 'Alt', Accelerator::SHIFT => 'Shift', Accelerator::SUPER => 'Win'] as $m => $name) {
            if ($a->has($m)) { $parts[] = $name; }
        }
        $parts[] = mb_strlen($a->key) === 1 ? mb_strtoupper($a->key) : $a->key;
        return implode('+', $parts);
    }

    /** The virtual key of an accelerator's key, null for one that has none (mygo's virtualKey). */
    private static function virtualKey(string $key): ?int
    {
        if (isset(self::NAMED_KEYS[$key])) {
            return self::NAMED_KEYS[$key];
        }
        if (strlen($key) === 1 && ctype_alpha($key)) {
            return ord(strtoupper($key));
        }
        if (strlen($key) === 1 && ctype_digit($key)) {
            return ord($key);
        }
        if (preg_match('/^F(\d{1,2})$/', $key, $m) === 1 && (int)$m[1] >= 1 && (int)$m[1] <= 24) {
            return 0x70 + (int)$m[1] - 1;
        }
        if (preg_match('/^Num(\d)$/', $key, $m) === 1) {
            return 0x60 + (int)$m[1];
        }
        return null;
    }
}
