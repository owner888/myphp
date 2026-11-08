<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;

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

    public function fileIcon(string $path, int $size): string
    {
        throw new Unsupported('myphp: file icons are not on Windows yet');
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

    public function appIcon(int $size): string
    {
        throw new Unsupported('myphp: the app\'s icon is not on Windows yet');
    }

    private static function execute(string $file, ?string $params = null): void
    {
        $r = Win32::shell32()->ShellExecuteW(null, Win32::wide('open'), Win32::wide($file), $params === null ? null : Win32::wide($params), null, 1 /* SW_SHOWNORMAL */);
        if ($r <= 32) {
            throw new \RuntimeException("myphp: cannot open $file (ShellExecute $r)");
        }
    }
}
