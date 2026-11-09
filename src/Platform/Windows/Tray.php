<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\Build\Png;
use MyPHP\Native\Zlib;
use MyPHP\Platform;
use MyPHP\Platform\{Menu, TrayHandler};
use MyPHP\Rectangle;

/**
 * An icon of the notification area (mygo's windows tray.go): Shell's
 * NOTIFYICONDATA, its messages to the app's window, by its number. It is
 * added once the call that made it gave it its number, image and tooltip
 * (the notification area keeps the user's choices for an icon by its
 * number and names it by its first tooltip); a template image shows white
 * on a dark taskbar, as macOS tints one. A left click is a click, a right
 * one a right click, which shows its menu, as Windows's icons do.
 */
final class Tray implements Platform\Tray
{
    private const NIM_ADD = 0, NIM_MODIFY = 1, NIM_DELETE = 2;
    private const NIF_MESSAGE = 0x1, NIF_ICON = 0x2, NIF_TIP = 0x4, NIF_SHOWTIP = 0x80;
    private const PERSONALIZE = 'Software\Microsoft\Windows\CurrentVersion\Themes\Personalize';

    private static ?FFI $f = null;

    private ?CData $icon = null;
    private string $tip = '';
    private ?Menu $menu = null;
    private bool $added = false;
    /** The taskbar's appearance, as last told; the PNG the icon shows, and whether it is a template. */
    private bool $dark;
    private string $image = '';
    private bool $template = false;

    public function __construct(private readonly Backend $b, private readonly TrayHandler $h, public int $id)
    {
        $this->dark = $this->dark();
    }

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { uint32_t d1; uint16_t d2, d3; uint8_t d4[8]; } GUID;
            typedef struct { int32_t left, top, right, bottom; } RECT;
            typedef struct { uint32_t cbSize; void *hWnd; uint32_t uID, uFlags, uCallbackMessage; void *hIcon; uint16_t szTip[128]; uint32_t dwState, dwStateMask; uint16_t szInfo[256]; uint32_t uVersion; uint16_t szInfoTitle[64]; uint32_t dwInfoFlags; GUID guidItem; void *hBalloonIcon; } NOTIFYICONDATAW;
            typedef struct { uint32_t cbSize; void *hWnd; uint32_t uID; GUID guidItem; } NOTIFYICONIDENTIFIER;
            int32_t Shell_NotifyIconW(uint32_t message, NOTIFYICONDATAW *data);
            int32_t Shell_NotifyIconGetRect(const NOTIFYICONIDENTIFIER *id, RECT *r);
            C, 'shell32.dll');
    }

    /** The icon's NOTIFYICONDATA, of $flags besides its message, icon and tooltip (mygo's data). */
    private function data(int $flags = 0): CData
    {
        $f = self::f();
        $d = $f->new('NOTIFYICONDATAW');
        $d->cbSize = FFI::sizeof($d);
        $d->hWnd = $this->b->hwnd();
        $d->uID = $this->id;
        $d->uFlags = $flags | self::NIF_MESSAGE;
        $d->uCallbackMessage = Backend::WM_APP_TRAY;
        if ($this->icon !== null) {
            $d->hIcon = $this->icon;
            $d->uFlags |= self::NIF_ICON;
        }
        if ($this->tip !== '') {
            $d->uFlags |= self::NIF_TIP | self::NIF_SHOWTIP;
            $u = (string)iconv('UTF-8', 'UTF-16LE', $this->tip);
            FFI::memcpy($d->szTip, $u, min(254, strlen($u) & ~1));
        }
        return $d;
    }

    /** @internal added, as the backend adds the icons made since it last did */
    public function add(): void
    {
        if ($this->added) {
            return;
        }
        self::f()->Shell_NotifyIconW(self::NIM_ADD, FFI::addr($this->data()));
        $this->added = true;
    }

    private function update(): void
    {
        if ($this->added) { // the backend adds it as it is
            self::f()->Shell_NotifyIconW(self::NIM_MODIFY, FFI::addr($this->data()));
        }
    }

    /**
     * Numbers the icon by $id (mygo's SetID): the notification area keeps
     * whether the user shows an icon on the taskbar by the executable's
     * path and the icon's number, which the backend otherwise gives as
     * icons come. An icon already added is added again under its new
     * number; a number another icon has keeps the old one.
     */
    public function setID(string $id): void
    {
        $n = hexdec(hash('fnv1a32', $id)) | (1 << 31); // its FNV-1a hash with the high bit set, which the numbers counted never reach
        if ($id === '' || $n === $this->id || !$this->b->renumberTray($this, $n)) {
            return;
        }
        if ($this->added) {
            self::f()->Shell_NotifyIconW(self::NIM_DELETE, FFI::addr($this->data()));
            $this->id = $n;
            $this->added = false;
            $this->add();
            return;
        }
        $this->id = $n;
    }

    /** Whether the taskbar is dark: Windows draws it in the system's mode, which may differ from the apps' (mygo's Dark). */
    public function dark(): bool
    {
        return Win32::regDword(self::PERSONALIZE, 'SystemUsesLightTheme') === 0;
    }

    public function setImage(string $png, bool $template): void
    {
        $shown = $template && $this->dark ? self::whiten($png) : $png;
        $size = unpack('N', substr($shown, 16, 4))[1] ?? 0;
        $icon = Win32::user32()->CreateIconFromResourceEx($shown, strlen($shown), 1, 0x00030000, $size, $size, 0);
        if ($icon === null) {
            throw new \RuntimeException('myphp: cannot make the tray\'s icon of the image');
        }
        if ($this->icon !== null) { Win32::user32()->DestroyIcon($this->icon); }
        [$this->icon, $this->image, $this->template] = [$icon, $png, $template];
        $this->update();
    }

    /** A template image drawn white, its alpha kept (mygo's whiten). */
    private static function whiten(string $png): string
    {
        [$w, $h, $px] = Png::decode($png);
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $raw .= "\xff\xff\xff" . chr((int)round($px[($y * $w + $x) * 4 + 3]));
            }
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', Zlib::deflate($raw, 15, 6)) . $chunk('IEND', '');
    }

    public function setTitle(string $title): void {}

    public function setSystemIcon(string $name): void {}

    public function setDualLineTitle(string $top, string $bottom): void {}

    public function setToolTip(string $tip): void
    {
        $this->tip = $tip;
        $this->update();
    }

    public function setMenu(?Menu $menu): void
    {
        $this->menu = $menu;
    }

    /** @internal the menu, which the backend keeps current with the items' updates */
    public function menu(): ?Menu
    {
        return $this->menu;
    }

    /** Shows the icon's menu at the pointer, over the app's window, and runs the item chosen (mygo's PopUpMenu). */
    public function popUpMenu(): void
    {
        if ($this->menu === null) {
            return;
        }
        $u = Win32::user32();
        $pt = $u->new('POINT');
        $u->GetCursorPos(FFI::addr($pt));
        $this->b->menus()->track($this->menu, $this->b->hwnd(), $pt->x, $pt->y, 0, null);
    }

    /** The notification area reports its clicks itself (mygo's). */
    public function setActivatable(bool $on): void {}

    /** Where the icon is on the screen, in points of its display (Shell_NotifyIconGetRect). */
    public function bounds(): Rectangle
    {
        $this->add(); // it is somewhere once added
        $f = self::f();
        $ident = $f->new('NOTIFYICONIDENTIFIER');
        $ident->cbSize = FFI::sizeof($ident);
        $ident->hWnd = $this->b->hwnd();
        $ident->uID = $this->id;
        $r = $f->new('RECT');
        if ($f->Shell_NotifyIconGetRect(FFI::addr($ident), FFI::addr($r)) < 0 || $r->right <= $r->left) {
            return new Rectangle(0, 0, 0, 0);
        }
        $u = Win32::user32();
        $pt = $u->new('POINT');
        [$pt->x, $pt->y] = [$r->left, $r->top];
        $dx = $u->new('uint32_t'); $dy = $u->new('uint32_t');
        $dpi = Win32::shcore()->GetDpiForMonitor($u->MonitorFromPoint($pt, 2), 0, FFI::addr($dx), FFI::addr($dy)) === 0 && $dx->cdata > 0 ? $dx->cdata : 96;
        $pt = fn(int $v): int => intdiv($v * 96 + intdiv($dpi, 2), $dpi);
        return new Rectangle($pt($r->left), $pt($r->top), $pt($r->right - $r->left), $pt($r->bottom - $r->top));
    }

    public function destroy(): void
    {
        if ($this->added) {
            self::f()->Shell_NotifyIconW(self::NIM_DELETE, FFI::addr($this->data()));
            $this->added = false;
        }
        if ($this->icon !== null) {
            Win32::user32()->DestroyIcon($this->icon);
            $this->icon = null;
        }
        $this->b->forgetTray($this);
    }

    public function highlight(bool $on): void {}

    public function pressed(): bool
    {
        return false;
    }

    public function handle(): mixed
    {
        return null;
    }

    /** @internal the notification area's callback message: a left button's release a click, a right one's a right click and the menu (mygo's trayMessage) */
    public function event(int $event): void
    {
        if ($event === Win32::WM_LBUTTONUP) {
            $this->h->clicked();
        } elseif ($event === Win32::WM_RBUTTONUP) {
            $this->h->rightClicked();
            $this->popUpMenu();
        }
    }

    /** @internal the taskbar turned dark or light: a template image shown again, white or black, and the handler told (mygo's traysAppearance) */
    public function appearance(): void
    {
        $dark = $this->dark();
        if ($dark === $this->dark) {
            return;
        }
        $this->dark = $dark;
        if ($this->template && $this->image !== '') {
            $this->setImage($this->image, true);
        }
        $this->h->appearanceChanged();
    }
}
