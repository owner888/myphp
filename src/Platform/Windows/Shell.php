<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\{Com, Wic};
use MyPHP\Platform;

/** The desktop of Windows (mygo's windows shell): ShellExecute opens, Explorer shows, the Recycle Bin takes. */
final class Shell implements Platform\Shell
{
    public function openExternal(string $url): void
    {
        self::execute($url);
    }

    public function openPath(string $path): void
    {
        self::execute($path);
    }

    public function openApplication(string $path): void
    {
        self::execute($path);
    }

    public function showItemInFolder(string $path): void
    {
        self::execute('explorer.exe', '/select,"' . str_replace('/', '\\', $path) . '"');
    }

    /** Moves $path to the Recycle Bin (SHFileOperation with FOF_ALLOWUNDO, as mygo's trash.go does without asking). */
    public function trashItem(string $path): void
    {
        $s = Win32::shell32();
        $from = Win32::wide(str_replace('/', '\\', $path) . "\0"); // a list: each name ends in a NUL, the list in another
        $op = $s->new('SHFILEOPSTRUCTW');
        $op->wFunc = 3; // FO_DELETE
        $op->pFrom = $s->cast('const void *', FFI::addr($from[0]));
        $op->fFlags = 0x0040 | 0x0010 | 0x0004 | 0x0400; // FOF_ALLOWUNDO, FOF_NOCONFIRMATION, FOF_SILENT, FOF_NOERRORUI
        $r = $s->SHFileOperationW(FFI::addr($op));
        if ($r !== 0 || $op->fAnyOperationsAborted) {
            throw new \RuntimeException(sprintf('myphp: cannot move %s to the Recycle Bin (error 0x%X)', $path, $r));
        }
    }

    public function beep(): void
    {
        Win32::user32()->MessageBeep(0);
    }

    public function haptic(string $kind): void {}

    /**
     * The icon Explorer shows for a file, a folder or an application, or a
     * shell item such as shell:AppsFolder\<AppUserModelID>, as Windows draws
     * it at $size pixels (IShellItemImageFactory's GetImage, icons only, no
     * thumbnails; mygo's FileIcon): its DIB section's pixels, BGRA of straight
     * alpha, as a PNG.
     */
    public function fileIcon(string $path, int $size): string
    {
        $f = self::icons();
        $out = $f->new('void *');
        $iid = Com::guid(self::IID_IMAGEFACTORY);
        $hr = $f->SHCreateItemFromParsingName(Win32::wide(str_replace('/', '\\', $path)), null, FFI::addr($iid), FFI::addr($out));
        if ($hr < 0 || FFI::isNull($out)) {
            throw new \RuntimeException(sprintf('myphp: cannot find %s (HRESULT 0x%08X)', $path, $hr & 0xFFFFFFFF));
        }
        $factory = Com::typed($f, $out, 'IShellItemImageFactory *');
        $hbm = $f->new('void *');
        try {
            Com::check(($factory->v->GetImage)($factory, ($size & 0xFFFFFFFF) | ($size << 32), 0x4, FFI::addr($hbm)), "the icon of $path"); // a SIZE by value; SIIGBF_ICONONLY
        } finally {
            Com::release($out);
        }
        $g = self::gdi();
        try {
            $ds = $g->new('DIBSECTION');
            if ($g->GetObjectW($hbm, FFI::sizeof($ds), FFI::addr($ds)) === 0 || $ds->dsBm->bmBits === null || $ds->dsBm->bmBitsPixel !== 32) {
                throw new \RuntimeException("myphp: the icon of $path is not a 32-bit DIB section ({$ds->dsBm->bmBitsPixel} bits)");
            }
            [$w, $h, $stride] = [$ds->dsBm->bmWidth, $ds->dsBm->bmHeight, $ds->dsBm->bmWidthBytes];
            $bits = FFI::string($ds->dsBm->bmBits, $stride * $h);
            $bgra = '';
            for ($y = 0; $y < $h; $y++) {
                $bgra .= substr($bits, ($ds->dsBmih->biHeight > 0 ? $h - 1 - $y : $y) * $stride, $w * 4); // bottom-up, as a DIB's rows are unless its height is negative
            }
        } finally {
            $g->DeleteObject($hbm);
        }
        return Wic::png($bgra, $w, $h, false);
    }

    /** Whether $path is on a drive of this computer (GetDriveType of its root: not DRIVE_REMOTE, nor a UNC path's). */
    public function isLocal(string $path): bool
    {
        $k = Win32::kernel32();
        $full = $k->new('uint16_t[1024]');
        $n = $k->GetFullPathNameW(Win32::wide($path), 1024, $full, null);
        $p = $n > 0 ? Win32::str($k->cast('uint16_t *', FFI::addr($full[0])), $n) : $path;
        if (str_starts_with($p, '\\\\')) {
            return false;
        }
        return preg_match('/^[A-Za-z]:/', $p) === 1 && $k->GetDriveTypeW(Win32::wide(substr($p, 0, 2) . '\\')) !== 4; // DRIVE_REMOTE
    }

    /** The executable's icon, as Explorer shows it (fileIcon). */
    public function appIcon(int $size): string
    {
        $k = FFI::cdef('uint32_t GetModuleFileNameW(void *module, uint16_t *name, uint32_t size);', 'kernel32.dll');
        $buf = $k->new('uint16_t[32768]');
        $n = $k->GetModuleFileNameW(null, $buf, 32768);
        return $this->fileIcon(Win32::str($buf, $n), $size);
    }

    /**
     * The apps of the Start menu's All apps list, the shell's Applications
     * folder (FOLDERID_AppsFolder; mygo's Applications): desktop apps by their
     * shortcuts and packaged apps alike, each its AppUserModelID, or its path
     * under a known folder for a desktop app without one; its path the shell
     * item shell:AppsFolder\<ID>, which fileIcon draws and openApplication
     * starts. Sorted by name.
     * @return list<\MyPHP\InstalledApp>
     */
    public static function installedApps(): array
    {
        $f = self::icons();
        $out = $f->new('void *');
        [$folderId, $iidItem] = [Com::guid('1e87508d-89c2-42f0-8a7e-645a0f50ca58'), Com::guid('43826d1e-e718-42ee-bc55-a1e261c37bfe')];
        Com::check($f->SHGetKnownFolderItem(FFI::addr($folderId), 0, null, FFI::addr($iidItem), FFI::addr($out)), 'SHGetKnownFolderItem(AppsFolder)');
        $folder = Com::typed($f, $out, 'IShellItem *');
        $list = $f->new('void *');
        [$bhid, $iidEnum] = [Com::guid('94f60519-2850-4924-aa5a-d15e84868039'), Com::guid('70629033-e363-4a28-a567-0db78006e6d7')]; // BHID_EnumItems, IEnumShellItems
        try {
            Com::check(($folder->v->BindToHandler)($folder, null, FFI::addr($bhid), FFI::addr($iidEnum), FFI::addr($list)), 'enumerating the Applications folder');
        } finally {
            Com::release($out);
        }
        $items = Com::typed($f, $list, 'IEnumShellItems *');
        $apps = [];
        $one = $f->new('void *');
        $fetched = $f->new('uint32_t');
        $name = function (CData $item, int $sigdn) use ($f): string {
            $s = $f->new('uint16_t *');
            if (($item->v->GetDisplayName)($item, $sigdn, FFI::addr($s)) < 0) {
                return '';
            }
            $got = Win32::str($s);
            Win32::ole32()->CoTaskMemFree($s);
            return $got;
        };
        try {
            while (($items->v->Next)($items, 1, FFI::addr($one), FFI::addr($fetched)) === 0 && $fetched->cdata === 1) { // S_FALSE at the end
                $item = Com::typed($f, $one, 'IShellItem *');
                [$label, $id] = [$name($item, 0), $name($item, 0x80018001)]; // SIGDN_NORMALDISPLAY; SIGDN_PARENTRELATIVEPARSING, its ID in the folder
                Com::release($one);
                if ($label !== '' && $id !== '') {
                    $apps[] = new \MyPHP\InstalledApp($label, $id, "shell:AppsFolder\\$id");
                }
            }
        } finally {
            Com::release($list);
        }
        usort($apps, fn(\MyPHP\InstalledApp $a, \MyPHP\InstalledApp $b): int => strnatcasecmp($a->name, $b->name));
        return $apps;
    }

    private const IID_IMAGEFACTORY = 'bcc18b79-ba16-442f-80c4-8a59c30c463b';
    private static ?FFI $icons = null, $gdi = null;

    private static function icons(): FFI
    {
        return self::$icons ??= FFI::cdef(<<<'C'
            typedef struct { void *r0[3]; int32_t (*GetImage)(void *self, uint64_t size, uint32_t flags, void **hbm); } IShellItemImageFactoryVtbl;
            typedef struct { IShellItemImageFactoryVtbl *v; } IShellItemImageFactory;
            typedef struct { void *r0[3]; int32_t (*BindToHandler)(void *self, void *bc, const void *bhid, const void *iid, void **out); void *r4; int32_t (*GetDisplayName)(void *self, uint32_t sigdn, uint16_t **name); } IShellItemVtbl;
            typedef struct { IShellItemVtbl *v; } IShellItem;
            typedef struct { void *r0[3]; int32_t (*Next)(void *self, uint32_t n, void **items, uint32_t *fetched); } IEnumShellItemsVtbl;
            typedef struct { IEnumShellItemsVtbl *v; } IEnumShellItems;
            int32_t SHCreateItemFromParsingName(const void *path, void *bc, const void *iid, void *out);
            int32_t SHGetKnownFolderItem(const void *folder, uint32_t flags, void *token, const void *iid, void *out);
            C, 'shell32.dll');
    }

    private static function gdi(): FFI
    {
        return self::$gdi ??= FFI::cdef(<<<'C'
            typedef struct { int32_t bmType, bmWidth, bmHeight, bmWidthBytes; uint16_t bmPlanes, bmBitsPixel; void *bmBits; } BITMAP;
            typedef struct { uint32_t biSize; int32_t biWidth, biHeight; uint16_t biPlanes, biBitCount; uint32_t biCompression, biSizeImage; int32_t biXPelsPerMeter, biYPelsPerMeter; uint32_t biClrUsed, biClrImportant; } BITMAPINFOHEADER;
            typedef struct { BITMAP dsBm; BITMAPINFOHEADER dsBmih; uint32_t dsBitfields[3]; void *dshSection; uint32_t dsOffset; } DIBSECTION;
            int32_t GetObjectW(void *object, int32_t size, void *out);
            int32_t DeleteObject(void *object);
            C, 'gdi32.dll');
    }

    private static function execute(string $file, ?string $params = null): void
    {
        $r = Win32::shell32()->ShellExecuteW(null, Win32::wide('open'), Win32::wide($file), $params === null ? null : Win32::wide($params), null, 1 /* SW_SHOWNORMAL */);
        if ($r <= 32) {
            throw new \RuntimeException("myphp: cannot open $file (ShellExecute $r)");
        }
    }
}
