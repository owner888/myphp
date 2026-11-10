<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\{Com, Mem};

/**
 * The windows' taskbar buttons (mygo's taskbar.go): their progress, through
 * the shell's ITaskbarList3, which Explorer takes once it has made a
 * window's button (TaskbarButtonCreated, again when it restarts), so a
 * window keeps its state and puts it then; their flashing; the app's icon
 * (mygo's SetDockIcon), each window's, a new one's too.
 *
 * @internal Platform\Windows
 */
final class Taskbar
{
    private const CLSID_TASKBARLIST = '56fdf344-fd6d-11d0-958a-006097c9a090', IID_TASKBARLIST3 = 'ea1afb91-9e28-4b86-90e9-9e9f8a5eefaf';
    private const WM_SETICON = 0x0080;

    private static ?FFI $f = null;
    /** The ITaskbarList3, once; false where there is none. */
    private static CData|false|null $list = null;
    private static ?int $created = null;
    /** The app's icon, null for the executable's. */
    private static ?CData $icon = null;

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct {
                void *r0[3];
                int32_t (*HrInit)(void *self);
                void *r4[5];
                int32_t (*SetProgressValue)(void *self, void *hwnd, uint64_t completed, uint64_t total);
                int32_t (*SetProgressState)(void *self, void *hwnd, uint32_t flags);
            } ITaskbarList3Vtbl;
            typedef struct { ITaskbarList3Vtbl *v; } ITaskbarList3;
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void *out);
            C, 'ole32.dll');
    }

    /** The message Explorer sends a window once its button is made (RegisterWindowMessage's TaskbarButtonCreated). */
    public static function created(): int
    {
        return self::$created ??= FFI::cdef('uint32_t RegisterWindowMessageW(const void *name);', 'user32.dll')->RegisterWindowMessageW(Win32::wide('TaskbarButtonCreated'));
    }

    private static function list(): ?CData
    {
        if (self::$list === null) {
            self::$list = false;
            $f = self::f();
            $out = $f->new('void *');
            [$c, $i] = [Com::guid(self::CLSID_TASKBARLIST), Com::guid(self::IID_TASKBARLIST3)];
            if ($f->CoCreateInstance(FFI::addr($c), null, 1, FFI::addr($i), FFI::addr($out)) >= 0 && !FFI::isNull($out)) { // CLSCTX_INPROC_SERVER
                $l = Com::typed($f, $out, 'ITaskbarList3 *');
                if (($l->v->HrInit)($l) >= 0) {
                    self::$list = $l;
                } else {
                    Com::release($out);
                }
            }
        }
        return self::$list === false ? null : self::$list;
    }

    /** Puts progress $state ('' none, 'normal', 'indeterminate', 'paused', 'error') at $value, 0 to 1, on $hwnd's button (mygo's applyTaskbar). */
    public static function progress(CData $hwnd, string $state, float $value): void
    {
        if (($l = self::list()) === null) {
            return; // no taskbar (Explorer not running): no button to show it on
        }
        $flags = ['normal' => 2, 'indeterminate' => 1, 'paused' => 8, 'error' => 4][$state] ?? 0; // TBPF_
        ($l->v->SetProgressState)($l, $hwnd, $flags);
        if ($flags !== 0 && $flags !== 1) {
            ($l->v->SetProgressValue)($l, $hwnd, (int)round(max(0.0, min(1.0, $value)) * 10000), 10000);
        }
    }

    /** Flashes $hwnd's button (FLASHWINFO's $flags: FLASHW_TRAY 0x2, FLASHW_ALL 0x3, FLASHW_TIMERNOFG 0xC), or stops it for 0. */
    public static function flash(CData $hwnd, int $flags): void
    {
        $u = Win32::user32();
        $info = $u->new('FLASHWINFO');
        $info->cbSize = FFI::sizeof($info);
        $info->hwnd = $hwnd;
        $info->dwFlags = $flags;
        $u->FlashWindowEx(FFI::addr($info));
    }

    /** The app's icon of a PNG, on each window and each made after; '' the executable's again. Throws for what is no image. */
    public static function setAppIcon(string $png): void
    {
        $u = Win32::user32();
        $icon = null;
        if ($png !== '') {
            $icon = $u->CreateIconFromResourceEx($png, strlen($png), 1, 0x00030000, 0, 0, 0); // its own size
            if ($icon === null) {
                throw new \InvalidArgumentException('myphp: the app\'s icon is no image Windows reads');
            }
        }
        $old = self::$icon;
        self::$icon = $icon;
        foreach (Window::all() as $w) {
            self::applyIcon($w->hwnd);
        }
        if ($old !== null) { $u->DestroyIcon($old); }
    }

    /** Puts the app's icon on $hwnd, the title bar's and the button's (none: its class's, the executable's). */
    public static function applyIcon(CData $hwnd): void
    {
        $u = Win32::user32();
        $icon = self::$icon === null ? 0 : Mem::addr(self::$icon);
        $u->SendMessageW($hwnd, self::WM_SETICON, 0, $icon); // ICON_SMALL
        $u->SendMessageW($hwnd, self::WM_SETICON, 1, $icon); // ICON_BIG
    }

    /** Whether the app's icon was set. */
    public static function hasAppIcon(): bool
    {
        return self::$icon !== null;
    }
}
