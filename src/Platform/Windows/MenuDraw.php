<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\Mem;

/**
 * Menus in the appearance, and in Windows 11's style (mygo's darkmenu.go
 * and menudraw.go). Win32's menus have no dark appearance of their own:
 * popup menus follow uxtheme's preferred app mode, which Windows exports
 * by ordinal only (since 1903), and the window draws its menu bar when the
 * system asks through the UAH ("user API hook") messages. Windows 11 still
 * draws Win32's menus as Windows 10 did, while its own have more room,
 * highlights with rounded corners inset from the edges and shortcuts in a
 * fainter color: the items of popup menus are drawn so (MFT_OWNERDRAW),
 * and the bar's, while the menus stay Windows' (their windows, rounded by
 * a hook as Windows rounds its own, animations, keyboard, and the items'
 * strings, which mnemonics, screen readers and the tests read). Windows 10
 * keeps its own menus, dark as the app is.
 */
final class MenuDraw
{
    /** Popup items' metrics, in DIPs, after WinUI's MenuFlyoutItem (mygo's). */
    private const ITEM_HEIGHT = 32, SEPARATOR_HEIGHT = 9, INSET = 4, PADDING = 12, RADIUS = 4, MARK_COLUMN = 16 + 12, ACCEL_GAP = 32, CHEVRON = 12, GLYPH_SIZE = 12;
    /** The menu bar's, which grows to WinUI's room. */
    private const BAR_HEIGHT = 30, BAR_EXTRA = 8, BAR_INSET_X = 2, BAR_INSET_Y = 3;
    /** The glyphs of Segoe Fluent Icons, also in Segoe MDL2 Assets: CheckMark, RadioBullet, ChevronRight, ChevronLeft. */
    private const GLYPH_CHECK = 0xE73E, GLYPH_BULLET = 0xE915, GLYPH_CHEVRON = 0xE76C, GLYPH_CHEVRON_LEFT = 0xE76B;
    /** The colors of a dark menu bar, those of the dark title bar above it, COLORREFs (0x00BBGGRR). */
    private const DARK_BAR = 0x202020, DARK_HOT = 0x363636, DARK_OPEN = 0x4D4D4D, DARK_TEXT = 0xFFFFFF, DARK_DISABLED = 0x6D6D6D;
    private const ODS_SELECTED = 0x1, ODS_GRAYED = 0x2, ODS_DISABLED = 0x4, ODS_CHECKED = 0x8, ODS_HOTLIGHT = 0x40, ODS_NOACCEL = 0x100;
    private const DT_CENTER = 0x1, DT_RIGHT = 0x2, DT_VCENTER = 0x4, DT_SINGLELINE = 0x20, DT_NOPREFIX = 0x800, DT_CALCRECT = 0x400, DT_HIDEPREFIX = 0x100000;

    private static ?FFI $user = null, $gdi = null;
    private static ?int $build = null;
    /**
     * uxtheme's SetPreferredAppMode and FlushMenuThemes, once, as the
     * fields of a struct read through a pointer to it, as a COM vtable's
     * are (Native\Com): FFI calls a function pointer read so, not one cast
     * from an address nor one read from a struct itself; false where
     * there are none. The struct is kept with it, and the FFI of its type:
     * a CData whose FFI is gone has no type to read it by.
     */
    private static CData|false|null $appMode = null;
    private static ?CData $appModeFns = null;
    private static ?FFI $appModeFfi = null;
    /** @var array<int, array{text: CData, glyphs: CData}> the fonts of popup items, by DPI */
    private static array $fonts = [];
    /** @var array<int, CData> brushes, by COLORREF */
    private static array $brushes = [];
    /** @var array<int, int> how much Windows widens the items of a menu, by DPI (mygo's menuExtra) */
    private static array $extra = [];
    private static ?CData $hook = null, $hooks = null;

    /**
     * @var array<int, array{owner: int, label: string, accel: string, separator: bool, submenu: bool, radio: bool, column: bool, first: bool, last: bool, menu: int, width: int, dpi: int}>
     *     the looks of the items drawn, by their data (mygo's menuLook)
     */
    public static array $looks = [];
    private static int $nextLook = 0;

    private static function user(): FFI
    {
        return self::$user ??= FFI::cdef(<<<'C'
            typedef struct { int32_t left, top, right, bottom; } RECT;
            typedef struct { uint32_t CtlType, CtlID, itemID, itemAction, itemState; void *hwndItem, *hDC; RECT rcItem; uintptr_t itemData; } DRAWITEMSTRUCT;
            typedef struct { uint32_t CtlType, CtlID, itemID, itemWidth, itemHeight; uintptr_t itemData; } MEASUREITEMSTRUCT;
            typedef struct { void *hmenu, *hdc; uint32_t dwFlags; } UAHMENU;
            typedef struct { DRAWITEMSTRUCT dis; UAHMENU um; int32_t iPosition; } UAHDRAWMENUITEM;
            typedef struct { uint32_t cbSize; RECT rcBar; void *hMenu, *hwndMenu; int32_t focused; } MENUBARINFO;
            typedef struct { uint32_t cbSize, fMask, dwStyle, cyMax; void *hbrBack; uint32_t dwContextHelpID; uintptr_t dwMenuData; } MENUINFO;
            typedef struct { uint32_t cbSize, fMask, fType, fState, wID; void *hSubMenu, *hbmpChecked, *hbmpUnchecked; uintptr_t dwItemData; uint16_t *dwTypeData; uint32_t cch; void *hbmpItem; } MENUITEMINFOW;
            typedef struct { int32_t lfHeight, lfWidth, lfEscapement, lfOrientation, lfWeight; uint8_t lfItalic, lfUnderline, lfStrikeOut, lfCharSet, lfOutPrecision, lfClipPrecision, lfQuality, lfPitchAndFamily; uint16_t lfFaceName[32]; } LOGFONTW;
            typedef struct { uint32_t cbSize; int32_t iBorderWidth, iScrollWidth, iScrollHeight, iCaptionWidth, iCaptionHeight; LOGFONTW lfCaptionFont; int32_t iSmCaptionWidth, iSmCaptionHeight; LOGFONTW lfSmCaptionFont; int32_t iMenuWidth, iMenuHeight; LOGFONTW lfMenuFont, lfStatusFont, lfMessageFont; int32_t iPaddedBorderWidth; } NONCLIENTMETRICSW;
            typedef struct { intptr_t lParam; uintptr_t wParam; uint32_t message; void *hwnd; } CWPSTRUCT;
            typedef intptr_t (*HOOKPROC)(int32_t code, uintptr_t wp, intptr_t lp);
            typedef struct { HOOKPROC fn; } Hooks;
            int32_t GetMenuBarInfo(void *hwnd, int32_t object, int32_t item, MENUBARINFO *info);
            int32_t GetWindowRect(void *hwnd, RECT *r);
            void *GetWindowDC(void *hwnd);
            void *GetDC(void *hwnd);
            int32_t ReleaseDC(void *hwnd, void *dc);
            int32_t FillRect(void *dc, const RECT *r, void *brush);
            int32_t DrawTextW(void *dc, const void *text, int32_t n, RECT *r, uint32_t format);
            int32_t GetMenuStringW(void *menu, uint32_t item, uint16_t *text, int32_t max, uint32_t flags);
            int32_t SetMenuItemInfoW(void *menu, uint32_t item, int32_t byPosition, const MENUITEMINFOW *info);
            int32_t SetMenuInfo(void *menu, const MENUINFO *info);
            uint32_t GetSysColor(int32_t index);
            int32_t SystemParametersInfoForDpi(uint32_t action, uint32_t param, void *value, uint32_t winIni, uint32_t dpi);
            int32_t SystemParametersInfoW(uint32_t action, uint32_t param, void *value, uint32_t winIni);
            uint32_t GetDpiForWindow(void *hwnd);
            intptr_t DefWindowProcW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            void *SetWindowsHookExW(int32_t id, HOOKPROC fn, void *module, uint32_t thread);
            int32_t UnhookWindowsHookEx(void *hook);
            intptr_t CallNextHookEx(void *hook, int32_t code, uintptr_t wp, intptr_t lp);
            uintptr_t GetClassLongPtrW(void *hwnd, int32_t index);
            uintptr_t SetClassLongPtrW(void *hwnd, int32_t index, intptr_t value);
            C, 'user32.dll');
    }

    private static function gdi(): FFI
    {
        return self::$gdi ??= FFI::cdef(<<<'C'
            typedef struct { uint32_t biSize; int32_t biWidth, biHeight; uint16_t biPlanes, biBitCount; uint32_t biCompression, biSizeImage; int32_t biXPelsPerMeter, biYPelsPerMeter; uint32_t biClrUsed, biClrImportant; } BITMAPINFOHEADER;
            void *CreateDIBSection(void *dc, const BITMAPINFOHEADER *info, uint32_t usage, void **bits, void *section, uint32_t offset);
            void *CreateCompatibleDC(void *dc);
            int32_t DeleteDC(void *dc);
            void *SelectObject(void *dc, void *object);
            int32_t DeleteObject(void *object);
            void *CreateSolidBrush(uint32_t color);
            int32_t BitBlt(void *dc, int32_t x, int32_t y, int32_t w, int32_t h, void *src, int32_t sx, int32_t sy, uint32_t rop);
            int32_t GdiFlush(void);
            int32_t SetBkMode(void *dc, int32_t mode);
            uint32_t SetTextColor(void *dc, uint32_t color);
            uint32_t GetLayout(void *dc);
            int32_t ExcludeClipRect(void *dc, int32_t left, int32_t top, int32_t right, int32_t bottom);
            void *CreateFontIndirectW(const void *font);
            void *CreateFontW(int32_t height, int32_t width, int32_t escapement, int32_t orientation, int32_t weight, uint32_t italic, uint32_t underline, uint32_t strikeOut, uint32_t charSet, uint32_t outPrecision, uint32_t clipPrecision, uint32_t quality, uint32_t pitchAndFamily, const void *face);
            int32_t GetTextFaceW(void *dc, int32_t n, uint16_t *face);
            void *GetCurrentObject(void *dc, uint32_t type);
            C, 'gdi32.dll');
    }

    /** Windows's build (RtlGetVersion, which no manifest hides). */
    public static function build(): int
    {
        if (self::$build === null) {
            $nt = FFI::cdef('typedef struct { uint32_t size, major, minor, build, platform; uint16_t csd[128]; } OSVERSIONINFOW; int32_t RtlGetVersion(OSVERSIONINFOW *v);', 'ntdll.dll');
            $v = $nt->new('OSVERSIONINFOW');
            $v->size = FFI::sizeof($v);
            $nt->RtlGetVersion(FFI::addr($v));
            self::$build = $v->major > 10 ? 99999 : ($v->major === 10 ? $v->build : 0);
        }
        return self::$build;
    }

    /** Whether the menus are drawn in Windows 11's style (mygo's fluentMenus). */
    public static function fluent(): bool
    {
        return self::build() >= 22000;
    }

    private static function px(float $v, int $dpi): int
    {
        return intdiv((int)round($v * $dpi) + 48, 96);
    }

    private static function highContrast(): bool
    {
        $u = self::user();
        $hc = self::user()->new('uint32_t[4]'); // HIGHCONTRASTW: cbSize, dwFlags, the scheme's name
        $hc[0] = 16;
        return $u->SystemParametersInfoW(0x0042, 16, FFI::addr($hc[0]), 0) !== 0 && ($hc[1] & 1) !== 0;
    }

    private static function dark(): bool
    {
        return \MyPHP\App::backend()->theme()->isDark();
    }

    /** The app's popup menus, those of its trays too, in the appearance (mygo's applyMenuTheme): uxtheme's preferred app mode, then its menus flushed. */
    public static function applyTheme(): void
    {
        if (self::$appMode === null) {
            self::$appMode = false;
            if (self::build() >= 18362) { // ordinal 135 was AllowDarkModeForApp before
                $k = FFI::cdef('void *LoadLibraryExW(const void *name, void *file, uint32_t flags); void *GetProcAddress(void *module, intptr_t name);', 'kernel32.dll');
                $h = $k->LoadLibraryExW(Win32::wide('uxtheme.dll'), null, 0x800); // LOAD_LIBRARY_SEARCH_SYSTEM32
                $set = $h === null ? null : $k->GetProcAddress($h, 135);
                $flush = $h === null ? null : $k->GetProcAddress($h, 136);
                if ($set !== null && $flush !== null) {
                    $f = self::$appModeFfi = FFI::cdef('typedef struct { int32_t (*set)(int32_t mode); void (*flush)(void); } AppMode;');
                    self::$appModeFns = $f->new('AppMode', false);
                    FFI::memcpy(self::$appModeFns, pack('PP', Mem::addr($set), Mem::addr($flush)), 16);
                    self::$appMode = \MyPHP\Native\Com::typed($f, FFI::addr(self::$appModeFns), 'AppMode *');
                }
            }
        }
        if (self::$appMode === false) {
            return;
        }
        (self::$appMode->set)(self::dark() ? 2 : 3); // ForceDark, ForceLight
        (self::$appMode->flush)();
    }

    /** The fonts of popup items forgotten, for the next menus to follow the system's (mygo's dropMenuFonts). */
    public static function dropFonts(): void
    {
        foreach (self::$fonts as $f) {
            self::gdi()->DeleteObject($f['text']);
            self::gdi()->DeleteObject($f['glyphs']);
        }
        [self::$fonts, self::$extra] = [[], []];
    }

    // The items of popup menus, owner-drawn.

    /**
     * Makes the item at $pos of $menu one drawn here, of $type besides
     * (MFT_SEPARATOR, MFT_RADIOCHECK or 0), its look registered: its data
     * (mygo's ownerDraw, addLook).
     * @param array{owner: int, label: string, accel: string, separator: bool, submenu: bool, radio: bool, column: bool, first: bool, last: bool} $look
     */
    public static function ownerDraw(CData $menu, int $pos, array $look): int
    {
        $key = ++self::$nextLook;
        self::$looks[$key] = $look + ['menu' => Mem::addr($menu), 'width' => 0, 'dpi' => 0];
        $u = self::user();
        $mi = $u->new('MENUITEMINFOW');
        $mi->cbSize = FFI::sizeof($mi);
        $mi->fMask = 0x100 | 0x20; // MIIM_FTYPE, MIIM_DATA
        $mi->fType = ($look['separator'] ? 0x800 : ($look['radio'] ? 0x200 : 0)) | 0x100; // MFT_SEPARATOR, MFT_RADIOCHECK; MFT_OWNERDRAW
        $mi->dwItemData = $key;
        $u->SetMenuItemInfoW($menu, $pos, 1, FFI::addr($mi));
        return $key;
    }

    /** Forgets the looks of $owner's items. */
    public static function dropOwner(int $owner): void
    {
        self::$looks = array_filter(self::$looks, fn(array $l): bool => $l['owner'] !== $owner);
    }

    /** @return array{back: int, plate: int, text: int, plateText: int, secondary: int, disabled: int, line: int, border: int} the colors of popup menus (mygo's menuPalette) */
    private static function palette(): array
    {
        if (self::highContrast()) {
            $u = self::user();
            $text = $u->GetSysColor(7);
            return ['back' => $u->GetSysColor(4), 'plate' => $u->GetSysColor(13), 'text' => $text, 'plateText' => $u->GetSysColor(14), 'secondary' => $text, 'disabled' => $u->GetSysColor(17), 'line' => $text, 'border' => 0xFFFFFFFF];
        }
        if (self::dark()) {
            return ['back' => 0x2B2B2B, 'plate' => 0x383838, 'text' => 0xFFFFFF, 'plateText' => 0xFFFFFF, 'secondary' => 0xCFCFCF, 'disabled' => 0x787878, 'line' => 0x3D3D3D, 'border' => 0x3E3E3E];
        }
        return ['back' => 0xF9F9F9, 'plate' => 0xF0F0F0, 'text' => 0x1A1A1A, 'plateText' => 0x1A1A1A, 'secondary' => 0x5F5F5F, 'disabled' => 0x9F9F9F, 'line' => 0xEAEAEA, 'border' => 0xE5E5E5];
    }

    private static function brush(int $color): CData
    {
        return self::$brushes[$color] ??= self::gdi()->CreateSolidBrush($color);
    }

    /**
     * The messages of the popup items drawn here, which menus send the
     * window they show for (mygo's menuItemMessage): their room, their
     * drawing, the menus' background, and the hook that rounds their
     * windows while they show. Null for one left to DefWindowProc.
     */
    public static function message(CData $hwnd, int $m, int $wp, int $lp): ?int
    {
        $u = self::user();
        switch ($m) {
            case 0x0211: // WM_ENTERMENULOOP
                self::hook(true);
                return null;
            case 0x0212: // WM_EXITMENULOOP
                self::hook(false);
                return null;
            case 0x0117: // WM_INITMENUPOPUP: the menu's background in the appearance, around its items; the window menu's Windows's
                if (self::fluent() && (($lp >> 16) & 0xFFFF) === 0) {
                    $mi = $u->new('MENUINFO');
                    $mi->cbSize = FFI::sizeof($mi);
                    $mi->fMask = 0x2; // MIM_BACKGROUND
                    $mi->hbrBack = self::brush(self::palette()['back']);
                    $u->SetMenuInfo($u->cast('void *', $wp), FFI::addr($mi));
                }
                return null;
            case 0x002C: // WM_MEASUREITEM
                $mis = $u->cast('MEASUREITEMSTRUCT *', $lp);
                if ($mis->CtlType !== 1 || !isset(self::$looks[$mis->itemData])) { // ODT_MENU
                    return null;
                }
                self::measure($hwnd, $mis->itemData, $mis);
                return 1;
            case 0x002B: // WM_DRAWITEM
                $d = $u->cast('DRAWITEMSTRUCT *', $lp);
                if ($d->CtlType !== 1 || !isset(self::$looks[$d->itemData])) {
                    return null;
                }
                self::draw($hwnd, $d->itemData, $d);
                return 1;
        }
        return null;
    }

    /** @return array{text: CData, glyphs: CData} the fonts of popup items at $dpi: the system's menu font, from its 12 pixels to WinUI's 14, in Segoe UI Variable where it is Segoe UI; the marks Segoe Fluent Icons', else Segoe MDL2 Assets' (mygo's menuFace) */
    private static function fonts(int $dpi): array
    {
        if (isset(self::$fonts[$dpi])) {
            return self::$fonts[$dpi];
        }
        $u = self::user(); $g = self::gdi();
        $ncm = $u->new('NONCLIENTMETRICSW');
        $ncm->cbSize = FFI::sizeof($ncm);
        $lf = $u->new('LOGFONTW');
        if ($u->SystemParametersInfoForDpi(0x29, $ncm->cbSize, FFI::addr($ncm), 0, $dpi) !== 0) { // SPI_GETNONCLIENTMETRICS
            FFI::memcpy($lf, $ncm->lfMenuFont, FFI::sizeof($lf));
        } else {
            $lf->lfHeight = -self::px(12, $dpi); $lf->lfWeight = 400;
            self::face($lf, 'Segoe UI');
        }
        $lf->lfHeight = (int)round($lf->lfHeight * 14 / 12);
        $text = null;
        if (self::faceOf($lf) === 'Segoe UI') {
            self::face($lf, 'Segoe UI Variable Text');
            $text = $g->CreateFontIndirectW(FFI::addr($lf));
            if (!self::isFace($text, 'Segoe UI Variable Text')) {
                $g->DeleteObject($text);
                $text = null;
                self::face($lf, 'Segoe UI');
            }
        }
        $text ??= $g->CreateFontIndirectW(FFI::addr($lf));
        $glyphs = self::font(-self::px(self::GLYPH_SIZE, $dpi), 'Segoe Fluent Icons');
        if (!self::isFace($glyphs, 'Segoe Fluent Icons')) {
            $g->DeleteObject($glyphs);
            $glyphs = self::font(-self::px(self::GLYPH_SIZE, $dpi), 'Segoe MDL2 Assets');
        }
        return self::$fonts[$dpi] = ['text' => $text, 'glyphs' => $glyphs];
    }

    private static function face(CData $lf, string $name): void
    {
        FFI::memset($lf->lfFaceName, 0, 64);
        $u = (string)iconv('UTF-8', 'UTF-16LE', $name);
        FFI::memcpy($lf->lfFaceName, $u, min(62, strlen($u)));
    }

    private static function faceOf(CData $lf): string
    {
        return rtrim((string)iconv('UTF-16LE', 'UTF-8', FFI::string($lf->lfFaceName, 64)), "\0");
    }

    private static function font(int $height, string $face): CData
    {
        return self::gdi()->CreateFontW($height, 0, 0, 0, 400, 0, 0, 0, 1, 0, 0, 4, 0, Win32::wide($face)); // FW_NORMAL, DEFAULT_CHARSET, ANTIALIASED_QUALITY
    }

    /** Whether $font is the face named, not one Windows put in its place (mygo's fontFace). */
    private static function isFace(CData $font, string $name): bool
    {
        $g = self::gdi();
        $dc = $g->CreateCompatibleDC(null);
        $old = $g->SelectObject($dc, $font);
        $buf = self::user()->new('uint16_t[64]');
        $n = $g->GetTextFaceW($dc, 64, $buf);
        $g->SelectObject($dc, $old);
        $g->DeleteDC($dc);
        return $n > 0 && strcasecmp(rtrim((string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, 128)), "\0"), $name) === 0;
    }

    /** The width of $text in $font on $dc, its mnemonic's & left out. */
    private static function textWidth(CData $dc, CData $font, string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $u = self::user(); $g = self::gdi();
        $old = $g->SelectObject($dc, $font);
        $r = $u->new('RECT');
        $u->DrawTextW($dc, Win32::wide($text), -1, FFI::addr($r), self::DT_CALCRECT | self::DT_SINGLELINE);
        $g->SelectObject($dc, $old);
        return $r->right - $r->left;
    }

    /** @return array{0: int, 1: int} the room above and below an item: the menu's padding, as much as the highlights' inset, above its first item and below its last */
    private static function padding(array $l, int $dpi): array
    {
        return [$l['first'] ? self::px(self::INSET, $dpi) : 0, $l['last'] ? self::px(self::INSET, $dpi) : 0];
    }

    /**
     * An item's room (mygo's measureItem). Windows widens every item past
     * the width it asks for, by as much as it does not tell: items ask for
     * what they need less what Windows widened those of the menus shown at
     * the DPI by, as they drew (learnExtra), and for all they need before
     * one showed.
     */
    private static function measure(CData $hwnd, int $key, CData $mis): void
    {
        $l = &self::$looks[$key];
        $dpi = max(96, self::user()->GetDpiForWindow($hwnd));
        [$top, $bottom] = self::padding($l, $dpi);
        $l['dpi'] = $dpi;
        if ($l['separator']) {
            $l['width'] = 0;
            [$mis->itemWidth, $mis->itemHeight] = [0, self::px(self::SEPARATOR_HEIGHT, $dpi) + $top + $bottom];
            return;
        }
        $w = self::itemWidth($l, $dpi);
        if (isset(self::$extra[$dpi])) { $w = max($w - self::$extra[$dpi], 0); }
        $l['width'] = $w;
        [$mis->itemWidth, $mis->itemHeight] = [$w, self::px(self::ITEM_HEIGHT, $dpi) + $top + $bottom];
    }

    /** The width an item that is not a separator needs at $dpi: its text and marks, past the highlight's inset and padding (mygo's itemWidth). */
    private static function itemWidth(array $l, int $dpi): int
    {
        $u = self::user();
        $f = self::fonts($dpi);
        $dc = $u->GetDC(null);
        $w = self::px(self::INSET + self::PADDING, $dpi) * 2 + self::textWidth($dc, $f['text'], $l['label']);
        if ($l['column']) { $w += self::px(self::MARK_COLUMN, $dpi); }
        if ($l['accel'] !== '') { $w += self::px(self::ACCEL_GAP, $dpi) + self::textWidth($dc, $f['text'], $l['accel']); }
        if ($l['submenu']) { $w += self::px(self::ACCEL_GAP / 2 + self::CHEVRON, $dpi); }
        $u->ReleaseDC(null, $dc);
        return $w;
    }

    /** Learns how much Windows widened the items of a menu at $dpi from one of them drawn $w wide: all are as wide as the widest asked for, and that; the least seen is kept (mygo's learnMenuExtra). */
    private static function learnExtra(array $l, int $dpi, int $w): void
    {
        $widest = 0;
        foreach (self::$looks as $o) {
            if ($o['menu'] !== $l['menu']) {
                continue;
            }
            if ($o['dpi'] !== $dpi) {
                return; // measured at another DPI, or not yet
            }
            $widest = max($widest, $o['width']);
        }
        $extra = $w - $widest;
        if ($extra >= 0 && (!isset(self::$extra[$dpi]) || self::$extra[$dpi] > $extra)) {
            self::$extra[$dpi] = $extra;
        }
    }

    /** Draws an item (mygo's drawItem): its background, its highlight while it is selected, its mark, its label, its shortcut fainter, a submenu's chevron. */
    private static function draw(CData $hwnd, int $key, CData $d): void
    {
        $l = self::$looks[$key];
        $u = self::user(); $g = self::gdi();
        $dpi = max(96, $u->GetDpiForWindow($hwnd));
        $p = self::palette();
        [$rl, $rt, $rr, $rb] = [$d->rcItem->left, $d->rcItem->top, $d->rcItem->right, $d->rcItem->bottom];
        self::learnExtra($l, $dpi, $rr - $rl);
        $state = $d->itemState;
        $selected = ($state & self::ODS_SELECTED) !== 0 && !$l['separator'];
        $inset = self::px(self::INSET, $dpi);
        [$top, $bottom] = self::padding($l, $dpi);
        $plate = $selected ? [$inset, $top, $rr - $rl - $inset, $rb - $rt - $bottom] : null;
        $rtl = ($g->GetLayout($d->hDC) & 1) !== 0; // LAYOUT_RTL: a menu laid out right to left, whose submenus open to the left
        self::paint($d->hDC, $rl, $rt, $rr - $rl, $rb - $rt, $p['back'], $plate, $p['plate'], self::px(self::RADIUS, $dpi), function (CData $dc, int $w, int $h) use ($l, $p, $state, $selected, $inset, $top, $bottom, $dpi, $rtl, $u, $g): void {
            [$t, $b] = [$top, $h - $bottom];
            if ($l['separator']) {
                $thick = max(self::px(1, $dpi), 1);
                $y = intdiv($b - $t - $thick, 2) + $t;
                self::fill($dc, $inset, $y, $w - $inset, $y + $thick, $p['line']);
                return;
            }
            [$fore, $secondary] = [$p['text'], $p['secondary']];
            if ($selected) {
                $fore = $p['plateText'];
                if (self::highContrast()) { $secondary = $fore; }
            }
            if (($state & (self::ODS_GRAYED | self::ODS_DISABLED)) !== 0) {
                [$fore, $secondary] = [$p['disabled'], $p['disabled']];
            }
            $f = self::fonts($dpi);
            $g->SetBkMode($dc, 1); // TRANSPARENT
            $x = self::px(self::INSET + self::PADDING, $dpi);
            $right = $w - self::px(self::INSET + self::PADDING, $dpi);
            $glyph = function (int $c, int $left, int $right) use ($dc, $f, $t, $b, $u, $g): void {
                $old = $g->SelectObject($dc, $f['glyphs']);
                $r = $u->new('RECT');
                [$r->left, $r->top, $r->right, $r->bottom] = [$left, $t, $right, $b];
                $u->DrawTextW($dc, Win32::wide(mb_chr($c, 'UTF-8')), 1, FFI::addr($r), self::DT_CENTER | self::DT_VCENTER | self::DT_SINGLELINE | self::DT_NOPREFIX);
                $g->SelectObject($dc, $old);
            };
            $g->SetTextColor($dc, $fore);
            if ($l['column']) {
                if (($state & self::ODS_CHECKED) !== 0) {
                    $glyph($l['radio'] ? self::GLYPH_BULLET : self::GLYPH_CHECK, $x, $x + self::px(16, $dpi));
                }
                $x += self::px(self::MARK_COLUMN, $dpi);
            }
            if ($l['submenu']) {
                $glyph($rtl ? self::GLYPH_CHEVRON_LEFT : self::GLYPH_CHEVRON, $right - self::px(self::CHEVRON, $dpi), $right);
            }
            $old = $g->SelectObject($dc, $f['text']);
            $r = $u->new('RECT');
            [$r->left, $r->top, $r->right, $r->bottom] = [$x, $t, $right, $b];
            $u->DrawTextW($dc, Win32::wide($l['label']), -1, FFI::addr($r), self::DT_SINGLELINE | self::DT_VCENTER | (($state & self::ODS_NOACCEL) !== 0 ? self::DT_HIDEPREFIX : 0));
            if ($l['accel'] !== '') {
                $g->SetTextColor($dc, $secondary);
                $u->DrawTextW($dc, Win32::wide($l['accel']), -1, FFI::addr($r), self::DT_SINGLELINE | self::DT_VCENTER | self::DT_RIGHT | self::DT_NOPREFIX);
            }
            $g->SelectObject($dc, $old);
        });
        // Windows draws a submenu's arrow after the item, in its colors: the chevron is drawn already.
        $g->ExcludeClipRect($d->hDC, $rl, $rt, $rr, $rb);
    }

    private static function fill(CData $dc, int $l, int $t, int $r, int $b, int $color): void
    {
        $u = self::user();
        $rect = $u->new('RECT');
        [$rect->left, $rect->top, $rect->right, $rect->bottom] = [$l, $t, $r, $b];
        $u->FillRect($dc, FFI::addr($rect), self::brush($color));
    }

    /**
     * Draws an item in a bitmap its size, then copies it to the menu's DC
     * at ($x, $y) (mygo's paintItem): the background, a highlight with
     * rounded corners ($plate, in the item's coordinates, null for none)
     * antialiased over it, and what $draw draws over them.
     * @param ?array{0: int, 1: int, 2: int, 3: int} $plate
     * @param \Closure(CData $dc, int $w, int $h): void $draw
     */
    private static function paint(CData $dc, int $x, int $y, int $w, int $h, int $back, ?array $plate, int $plateColor, int $radius, \Closure $draw): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $g = self::gdi();
        $hdr = $g->new('BITMAPINFOHEADER');
        $hdr->biSize = FFI::sizeof($hdr);
        [$hdr->biWidth, $hdr->biHeight, $hdr->biPlanes, $hdr->biBitCount] = [$w, -$h, 1, 32]; // top down
        $bits = $g->new('void *');
        $bmp = $g->CreateDIBSection($dc, FFI::addr($hdr), 0, FFI::addr($bits), null, 0);
        if ($bmp === null || FFI::isNull($bits)) {
            return;
        }
        $mem = $g->CreateCompatibleDC($dc);
        $old = $g->SelectObject($mem, $bmp);
        [$bg, $fg] = [self::dib($back), self::dib($plateColor)];
        $px = str_repeat(pack('V', $bg), $w * $h);
        if ($plate !== null) {
            [$pl, $pt, $pr, $pb] = $plate;
            for ($j = max($pt, 0); $j < min($pb, $h); $j++) {
                $row = '';
                for ($i = max($pl, 0); $i < min($pr, $w); $i++) {
                    $row .= pack('V', self::blend($fg, $bg, self::coverage($i + 0.5, $j + 0.5, $plate, $radius)));
                }
                $px = substr_replace($px, $row, ($j * $w + max($pl, 0)) * 4, strlen($row));
            }
        }
        FFI::memcpy($bits, $px, strlen($px));
        $g->GdiFlush();
        $draw($mem, $w, $h);
        $g->GdiFlush();
        $g->BitBlt($dc, $x, $y, $w, $h, $mem, 0, 0, 0x00CC0020); // SRCCOPY
        $g->SelectObject($mem, $old);
        $g->DeleteDC($mem);
        $g->DeleteObject($bmp);
    }

    /** How much of the pixel centered at ($x, $y) a rectangle with corners rounded by $radius covers, 0 to 1 (mygo's roundedCoverage). @param array{0: int, 1: int, 2: int, 3: int} $r */
    private static function coverage(float $x, float $y, array $r, float $radius): float
    {
        $cx = min(max($x, $r[0] + $radius), $r[2] - $radius);
        $cy = min(max($y, $r[1] + $radius), $r[3] - $radius);
        return min(max($radius - hypot($x - $cx, $y - $cy) + 0.5, 0.0), 1.0);
    }

    /** A COLORREF (0x00BBGGRR) as a DIB's pixel (0x00RRGGBB). */
    private static function dib(int $c): int
    {
        return (($c & 0xFF) << 16) | ($c & 0xFF00) | (($c >> 16) & 0xFF);
    }

    /** $a over $b by $t, 0 to 1, of DIB pixels. */
    private static function blend(int $a, int $b, float $t): int
    {
        $out = 0;
        foreach ([16, 8, 0] as $s) {
            $x = ($a >> $s) & 0xFF; $y = ($b >> $s) & 0xFF;
            $out |= ((int)round($y + ($x - $y) * $t)) << $s;
        }
        return $out;
    }

    /**
     * Hooks the thread's messages while its menus show, or lets go (mygo's
     * hookMenus): a menu with items its app draws loses Windows 11's frame,
     * so the windows of menus, as they are created, are rounded as Windows
     * rounds its own, outlined in their colors, the class's shadow taken
     * off for DWM's around the rounded window.
     */
    private static function hook(bool $on): void
    {
        if (!self::fluent()) {
            return;
        }
        $u = self::user();
        if ($on && self::$hook === null) {
            if (self::$hooks === null) {
                self::$hooks = $u->new('Hooks', false);
                self::$hooks->fn = static function (int $code, int $wp, int $lp) use ($u): int {
                    if ($code >= 0) {
                        $m = $u->cast('CWPSTRUCT *', $lp);
                        if ($m->message === 0x0001 && $u->GetClassLongPtrW($m->hwnd, -32) === 0x8000) { // WM_CREATE of a menu's window (#32768)
                            self::round($m->hwnd);
                        }
                    }
                    return $u->CallNextHookEx(null, $code, $wp, $lp);
                };
            }
            $k = FFI::cdef('uint32_t GetCurrentThreadId(void);', 'kernel32.dll');
            self::$hook = $u->SetWindowsHookExW(4, self::$hooks->fn, null, $k->GetCurrentThreadId()); // WH_CALLWNDPROC
        } elseif (!$on && self::$hook !== null) {
            $u->UnhookWindowsHookEx(self::$hook);
            self::$hook = null;
        }
    }

    /** A menu's window, being created, given Windows 11's frame (mygo's roundMenu). */
    private static function round(CData $hwnd): void
    {
        $u = self::user();
        $v = self::user()->new('uint32_t');
        $v->cdata = 3; // DWMWCP_ROUNDSMALL
        Win32::dwmapi()->DwmSetWindowAttribute($hwnd, 33, FFI::addr($v), 4); // DWMWA_WINDOW_CORNER_PREFERENCE
        $v->cdata = self::palette()['border'];
        Win32::dwmapi()->DwmSetWindowAttribute($hwnd, 34, FFI::addr($v), 4); // DWMWA_BORDER_COLOR
        $style = $u->GetClassLongPtrW($hwnd, -26); // GCL_STYLE
        if (($style & 0x20000) !== 0) { // CS_DROPSHADOW
            $u->SetClassLongPtrW($hwnd, -26, $style & ~0x20000);
        }
    }

    // The menu bar, which the window draws.

    /** Whether the window draws its menu bar: on Windows 11 always, on Windows 10 when dark; a contrast theme keeps the system's colors (mygo's drawsMenuBar). */
    public static function drawsBar(): bool
    {
        return !self::highContrast() && (self::fluent() || self::dark());
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} the bar's background, the backplates of an item hovered and of one open, the text of an item and of a disabled one (mygo's menuBarColors) */
    private static function barColors(): array
    {
        if (!self::fluent()) {
            return [self::DARK_BAR, self::DARK_HOT, self::DARK_OPEN, self::DARK_TEXT, self::DARK_DISABLED];
        }
        return self::dark() ? [self::DARK_BAR, 0x2D2D2D, 0x333333, self::DARK_TEXT, self::DARK_DISABLED] : [0xFFFFFF, 0xF0F0F0, 0xEAEAEA, 0x1A1A1A, 0x9F9F9F];
    }

    /** @return ?array{0: int, 1: int, 2: int, 3: int} the menu bar in window coordinates, which the DCs of these messages use */
    private static function barRect(CData $hwnd): ?array
    {
        $u = self::user();
        $mbi = $u->new('MENUBARINFO');
        $mbi->cbSize = FFI::sizeof($mbi);
        if ($u->GetMenuBarInfo($hwnd, -3, 0, FFI::addr($mbi)) === 0) { // OBJID_MENU
            return null;
        }
        $wr = $u->new('RECT');
        $u->GetWindowRect($hwnd, FFI::addr($wr));
        return [$mbi->rcBar->left - $wr->left, $mbi->rcBar->top - $wr->top, $mbi->rcBar->right - $wr->left, $mbi->rcBar->bottom - $wr->top];
    }

    /**
     * The messages that draw the menu bar, while the window draws it (mygo's
     * menuBarMessage): its background, its items measured taller and wider
     * on Windows 11, each item's backplate and label, and the frame's light
     * line below it covered while dark. Null for one left to DefWindowProc.
     */
    public static function barMessage(CData $hwnd, int $m, int $wp, int $lp): ?int
    {
        if (!in_array($m, [0x91, 0x92, 0x94, 0x85, 0x86], true) || !self::drawsBar()) { // WM_UAHDRAWMENU, WM_UAHDRAWMENUITEM, WM_UAHMEASUREMENUITEM, WM_NCPAINT, WM_NCACTIVATE
            return null;
        }
        $u = self::user();
        switch ($m) {
            case 0x91:
                $um = $u->cast('UAHMENU *', $lp);
                if (($r = self::barRect($hwnd)) !== null) { self::fill($um->hdc, ...[...$r, self::barColors()[0]]); }
                return 0;
            case 0x92:
                self::barItem($hwnd, $u->cast('UAHDRAWMENUITEM *', $lp));
                return 0;
            case 0x94: // as tall as Windows 11's bar, and wider for its backplate
                $result = $u->DefWindowProcW($hwnd, $m, $wp, $lp);
                if (self::fluent()) {
                    $mis = $u->cast('MEASUREITEMSTRUCT *', $lp); // UAHMEASUREMENUITEM starts with it
                    $dpi = max(96, $u->GetDpiForWindow($hwnd));
                    $mis->itemWidth += self::px(self::BAR_EXTRA, $dpi);
                    $mis->itemHeight = max($mis->itemHeight, self::px(self::BAR_HEIGHT, $dpi));
                }
                return $result;
        }
        $result = $u->DefWindowProcW($hwnd, $m, $wp, $lp); // the frame, then the line over it
        if (self::dark() && ($r = self::barRect($hwnd)) !== null && ($dc = $u->GetWindowDC($hwnd)) !== null) {
            self::fill($dc, $r[0], $r[3], $r[2], $r[3] + 1, self::DARK_BAR);
            $u->ReleaseDC($hwnd, $dc);
        }
        return $result;
    }

    /** An item of the bar: its backplate and its label; on Windows 11 the backplate inset, its corners rounded (mygo's drawMenuBarItem). */
    private static function barItem(CData $hwnd, CData $d): void
    {
        $u = self::user(); $g = self::gdi();
        $dc = $d->dis->hDC;
        [$rl, $rt, $rr, $rb] = [$d->dis->rcItem->left, $d->dis->rcItem->top, $d->dis->rcItem->right, $d->dis->rcItem->bottom];
        $state = $d->dis->itemState;
        [$back, $hot, $open, $fore, $disabled] = self::barColors();
        $plate = $back;
        if (($state & (self::ODS_GRAYED | self::ODS_DISABLED)) !== 0) {
            $fore = $disabled;
        } elseif (($state & self::ODS_SELECTED) !== 0) {
            $plate = $open;
        } elseif (($state & self::ODS_HOTLIGHT) !== 0) {
            $plate = $hot;
        }
        $buf = self::user()->new('uint16_t[256]');
        $n = $u->GetMenuStringW($d->um->hmenu, $d->iPosition, $buf, 256, 0x400);
        $flags = self::DT_CENTER | self::DT_VCENTER | self::DT_SINGLELINE | (($state & self::ODS_NOACCEL) !== 0 ? self::DT_HIDEPREFIX : 0);
        $label = function (CData $dc, int $l, int $t, int $r, int $b) use ($u, $g, $fore, $buf, $n, $flags): void {
            $g->SetBkMode($dc, 1);
            $g->SetTextColor($dc, $fore);
            $rect = $u->new('RECT');
            [$rect->left, $rect->top, $rect->right, $rect->bottom] = [$l, $t, $r, $b];
            $u->DrawTextW($dc, $buf, $n, FFI::addr($rect), $flags);
        };
        if (!self::fluent()) {
            self::fill($dc, $rl, $rt, $rr, $rb, $plate);
            $label($dc, $rl, $rt, $rr, $rb);
            return;
        }
        $font = $g->GetCurrentObject($dc, 6); // OBJ_FONT
        $dpi = max(96, $u->GetDpiForWindow($hwnd));
        [$x, $y] = [self::px(self::BAR_INSET_X, $dpi), self::px(self::BAR_INSET_Y, $dpi)];
        $box = $plate === $back ? null : [$x, $y, $rr - $rl - $x, $rb - $rt - $y];
        self::paint($dc, $rl, $rt, $rr - $rl, $rb - $rt, $back, $box, $plate, self::px(self::RADIUS, $dpi), function (CData $mem, int $w, int $h) use ($g, $font, $label): void {
            $old = $g->SelectObject($mem, $font);
            $label($mem, 0, 0, $w, $h);
            $g->SelectObject($mem, $old);
        });
    }
}
