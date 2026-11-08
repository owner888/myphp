<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use FFI\CData;

/**
 * Win32 over FFI (mygo's internal/windows win32.go): the system's
 * libraries, each a cdef of its own with the types its functions take
 * (another's types reach it as void *), wide strings, and the one window
 * procedure every window class of the backend shares (mygo's wndProc),
 * which hands a window's messages to the closure route() gave for it.
 */
final class Win32
{
    public const WM_DESTROY = 0x0002, WM_SETTINGCHANGE = 0x001A, WM_DISPLAYCHANGE = 0x007E, WM_TIMER = 0x0113;
    public const WM_POWERBROADCAST = 0x0218, WM_WTSSESSION_CHANGE = 0x02B1, WM_QUIT = 0x0012, WM_APP = 0x8000;
    /** HKEY_CURRENT_USER: (HKEY)(ULONG_PTR)(LONG)0x80000001, sign-extended. */
    public const HKCU = -0x7FFFFFFF;

    /** @var array<string, FFI> */
    private static array $libs = [];
    /** @var array<int, Closure(int, int, int): ?int> what handles each window's messages, by its handle */
    private static array $routes = [];
    /** @var array<string, true> the window classes registered */
    private static array $classes = [];
    /** The callbacks Windows calls, kept as long as the process: the window procedure, the monitors' enumeration. */
    private static ?CData $callbacks = null;
    /** @var ?Closure(CData): void what the monitors' enumeration reports each monitor to */
    public static ?Closure $monitorSink = null;

    public static function user32(): FFI
    {
        return self::$libs['user32'] ??= FFI::cdef(<<<'C'
            typedef intptr_t (*WNDPROC)(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            typedef int32_t (*MONITORENUMPROC)(void *monitor, void *dc, void *rect, intptr_t data);
            typedef struct { WNDPROC wndProc; MONITORENUMPROC monitorEnum; } Callbacks;
            typedef struct { uint32_t cbSize, style; WNDPROC lpfnWndProc; int32_t cbClsExtra, cbWndExtra; void *hInstance, *hIcon, *hCursor, *hbrBackground; const uint16_t *lpszMenuName, *lpszClassName; void *hIconSm; } WNDCLASSEXW;
            typedef struct { int32_t x, y; } POINT;
            typedef struct { int32_t left, top, right, bottom; } RECT;
            typedef struct { void *hwnd; uint32_t message; uintptr_t wParam; intptr_t lParam; uint32_t time; POINT pt; uint32_t lPrivate; } MSG;
            typedef struct { uint32_t cbSize; RECT rcMonitor, rcWork; uint32_t dwFlags; uint16_t szDevice[32]; } MONITORINFOEXW;
            typedef struct { uint32_t cbSize, dwFlags; void *lpszDefaultScheme; } HIGHCONTRASTW;
            typedef struct { uint32_t cbSize, dwTime; } LASTINPUTINFO;
            uint16_t RegisterClassExW(const WNDCLASSEXW *wc);
            void *CreateWindowExW(uint32_t exStyle, const void *cls, const void *title, uint32_t style, int32_t x, int32_t y, int32_t w, int32_t h, void *parent, void *menu, void *inst, void *param);
            int32_t DestroyWindow(void *hwnd);
            intptr_t DefWindowProcW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            int32_t PostMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            void PostQuitMessage(int32_t code);
            int32_t GetMessageW(MSG *msg, void *hwnd, uint32_t min, uint32_t max);
            int32_t PeekMessageW(MSG *msg, void *hwnd, uint32_t min, uint32_t max, uint32_t remove);
            int32_t TranslateMessage(const MSG *msg);
            intptr_t DispatchMessageW(const MSG *msg);
            uint32_t MsgWaitForMultipleObjectsEx(uint32_t n, const void *handles, uint32_t ms, uint32_t wake, uint32_t flags);
            uintptr_t SetTimer(void *hwnd, uintptr_t id, uint32_t ms, void *fn);
            int32_t KillTimer(void *hwnd, uintptr_t id);
            uint32_t GetDoubleClickTime(void);
            intptr_t SetProcessDpiAwarenessContext(intptr_t context);
            int32_t EnumDisplayMonitors(void *dc, const RECT *clip, MONITORENUMPROC fn, intptr_t data);
            int32_t GetMonitorInfoW(void *monitor, MONITORINFOEXW *info);
            void *MonitorFromPoint(POINT pt, uint32_t flags);
            int32_t GetCursorPos(POINT *pt);
            int32_t SystemParametersInfoW(uint32_t action, uint32_t param, void *value, uint32_t winIni);
            int32_t GetLastInputInfo(LASTINPUTINFO *info);
            int32_t MessageBeep(uint32_t type);
            int32_t OpenClipboard(void *owner);
            int32_t CloseClipboard(void);
            int32_t EmptyClipboard(void);
            void *GetClipboardData(uint32_t format);
            void *SetClipboardData(uint32_t format, void *mem);
            uint32_t EnumClipboardFormats(uint32_t format);
            int32_t IsClipboardFormatAvailable(uint32_t format);
            int32_t GetClipboardFormatNameW(uint32_t format, uint16_t *name, int32_t max);
            uint32_t RegisterClipboardFormatW(const void *name);
            C, 'user32.dll');
    }

    public static function kernel32(): FFI
    {
        return self::$libs['kernel32'] ??= FFI::cdef(<<<'C'
            typedef struct { uint8_t ACLineStatus, BatteryFlag, BatteryLifePercent, SystemStatusFlag; uint32_t BatteryLifeTime, BatteryFullLifeTime; } SYSTEM_POWER_STATUS;
            void *GetModuleHandleW(const void *name);
            uint32_t GetLastError(void);
            int32_t lstrlenW(const void *s);
            void *GlobalAlloc(uint32_t flags, size_t bytes);
            void *GlobalFree(void *mem);
            void *GlobalLock(void *mem);
            int32_t GlobalUnlock(void *mem);
            size_t GlobalSize(void *mem);
            uint32_t GetTickCount(void);
            uint32_t SetThreadExecutionState(uint32_t flags);
            int32_t GetSystemPowerStatus(SYSTEM_POWER_STATUS *status);
            int32_t GetUserPreferredUILanguages(uint32_t flags, uint32_t *count, uint16_t *buffer, uint32_t *size);
            int32_t GetUserDefaultLocaleName(uint16_t *name, int32_t max);
            int32_t GetLocaleInfoEx(const void *locale, uint32_t type, void *data, int32_t max);
            uint32_t GetDriveTypeW(const void *root);
            uint32_t GetFullPathNameW(const void *path, uint32_t max, uint16_t *out, void *file);
            C, 'kernel32.dll');
    }

    public static function ole32(): FFI
    {
        return self::$libs['ole32'] ??= FFI::cdef('int32_t CoInitializeEx(void *reserved, uint32_t model);', 'ole32.dll');
    }

    public static function shell32(): FFI
    {
        return self::$libs['shell32'] ??= FFI::cdef(<<<'C'
            typedef struct { void *hwnd; uint32_t wFunc; const void *pFrom, *pTo; uint16_t fFlags; int32_t fAnyOperationsAborted; void *hNameMappings; const void *lpszProgressTitle; } SHFILEOPSTRUCTW;
            intptr_t ShellExecuteW(void *hwnd, const void *verb, const void *file, const void *params, const void *dir, int32_t show);
            int32_t SHFileOperationW(SHFILEOPSTRUCTW *op);
            C, 'shell32.dll');
    }

    public static function advapi32(): FFI
    {
        return self::$libs['advapi32'] ??= FFI::cdef('int32_t RegGetValueW(intptr_t key, const void *subKey, const void *value, uint32_t flags, uint32_t *type, void *data, uint32_t *size);', 'advapi32.dll');
    }

    public static function shcore(): FFI
    {
        return self::$libs['shcore'] ??= FFI::cdef('int32_t GetDpiForMonitor(void *monitor, int32_t type, uint32_t *x, uint32_t *y);', 'shcore.dll');
    }

    /** $s as a NUL-terminated string of UTF-16, as the W functions take it (an array, which a pointer parameter takes). */
    public static function wide(string $s): CData
    {
        $u = (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
        $buf = self::user32()->new('uint16_t[' . (strlen($u) >> 1) . ']');
        FFI::memcpy($buf, $u, strlen($u));
        return $buf;
    }

    /** The UTF-16 string at $p, to its NUL or of $chars characters, as UTF-8; '' for null. */
    public static function str(?CData $p, ?int $chars = null): string
    {
        if ($p === null || FFI::isNull($p)) {
            return '';
        }
        $chars ??= self::kernel32()->lstrlenW($p);
        return $chars <= 0 ? '' : (string)iconv('UTF-16LE', 'UTF-8', FFI::string($p, $chars * 2));
    }

    /** The UTF-16 string at address $addr (as a message's lParam), as str(). */
    public static function strAt(int $addr): string
    {
        return $addr === 0 ? '' : self::str(self::user32()->cast('uint16_t *', $addr));
    }

    /** A handle's address, by which route() keeps windows: the pointer's own bytes, through a slot (FFI's cast of a pointer to an integer reads what it points to, ffi_addr_test). */
    public static function addr(?CData $p): int
    {
        if ($p === null) {
            return 0;
        }
        if (self::$slot === null) {
            self::$slot = self::user32()->new('void *[1]');
            self::$slotInt = self::user32()->cast('int64_t[1]', self::$slot);
        }
        self::$slot[0] = $p;
        return self::$slotInt[0];
    }

    private static ?CData $slot = null, $slotInt = null;

    public static function instance(): CData
    {
        return self::kernel32()->GetModuleHandleW(null);
    }

    private static function callbacks(): CData
    {
        if (self::$callbacks === null) {
            $u = self::user32();
            self::$callbacks = $u->new('Callbacks', false);
            self::$callbacks->wndProc = static function ($hwnd, int $msg, int $wp, int $lp) use ($u): int {
                $fn = self::$routes[self::addr($hwnd)] ?? null;
                if ($fn !== null && ($r = $fn($msg, $wp, $lp)) !== null) {
                    return $r;
                }
                if ($msg === self::WM_DESTROY) {
                    unset(self::$routes[self::addr($hwnd)]);
                }
                return $u->DefWindowProcW($hwnd, $msg, $wp, $lp);
            };
            self::$callbacks->monitorEnum = static function ($monitor, $dc, $rect, int $data): int {
                if (self::$monitorSink !== null) { (self::$monitorSink)($monitor); }
                return 1;
            };
        }
        return self::$callbacks;
    }

    /** Registers window class $name, once, with the shared window procedure; $style its CS_ flags. */
    public static function registerClass(string $name, int $style = 0x0003): void
    {
        if (isset(self::$classes[$name])) {
            return;
        }
        $u = self::user32();
        $wc = $u->new('WNDCLASSEXW');
        $wc->cbSize = FFI::sizeof($wc);
        $wc->style = $style;
        $wc->lpfnWndProc = self::callbacks()->wndProc;
        $wc->hInstance = self::instance();
        $class = self::wide($name);
        $wc->lpszClassName = $u->cast('const uint16_t *', FFI::addr($class[0]));
        if ($u->RegisterClassExW(FFI::addr($wc)) === 0) {
            throw new \RuntimeException("myphp: cannot register the window class $name (error " . self::kernel32()->GetLastError() . ')');
        }
        self::$classes[$name] = true;
    }

    /** A window of class $name, its messages to $handler (its result, or null for DefWindowProc's). @param Closure(int $msg, int $wp, int $lp): ?int $handler */
    public static function createWindow(string $class, string $title, int $style, int $exStyle, Closure $handler, int $x = 0, int $y = 0, int $w = 0, int $h = 0, ?CData $parent = null): CData
    {
        $hwnd = self::user32()->CreateWindowExW($exStyle, self::wide($class), self::wide($title), $style, $x, $y, $w, $h, $parent, null, self::instance(), null);
        if ($hwnd === null) {
            throw new \RuntimeException("myphp: cannot create a window of class $class (error " . self::kernel32()->GetLastError() . ')');
        }
        self::$routes[self::addr($hwnd)] = $handler;
        return $hwnd;
    }

    /** Calls $fn with each monitor's handle (EnumDisplayMonitors). @param Closure(CData): void $fn */
    public static function eachMonitor(Closure $fn): void
    {
        self::$monitorSink = $fn;
        try {
            self::user32()->EnumDisplayMonitors(null, null, self::callbacks()->monitorEnum, 0);
        } finally {
            self::$monitorSink = null;
        }
    }

    /** A DWORD of the current user's registry, null where it is not. */
    public static function regDword(string $key, string $value): ?int
    {
        $a = self::advapi32();
        $data = $a->new('uint32_t'); $size = $a->new('uint32_t');
        $size->cdata = 4;
        $r = $a->RegGetValueW(self::HKCU, self::wide($key), self::wide($value), 0x10 /* RRF_RT_REG_DWORD */, null, FFI::addr($data), FFI::addr($size));
        return $r === 0 ? $data->cdata : null;
    }
}
