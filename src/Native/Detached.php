<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * A process of Windows that outlives the app (Update's helper): started
 * with CreateProcessW, a console of its own that shows no window
 * (CREATE_NO_WINDOW), its handles closed at once, so that nothing waits
 * for it; proc_open's waits as PHP shuts down.
 *
 * @internal Update
 */
final class Detached
{
    private static ?FFI $k = null;

    private static function k(): FFI
    {
        return self::$k ??= FFI::cdef(<<<'C'
            typedef struct { uint32_t cb; void *lpReserved, *lpDesktop, *lpTitle; uint32_t dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags; uint16_t wShowWindow, cbReserved2; void *lpReserved2, *hStdInput, *hStdOutput, *hStdError; } STARTUPINFOW;
            typedef struct { void *hProcess, *hThread; uint32_t dwProcessId, dwThreadId; } PROCESS_INFORMATION;
            int32_t CreateProcessW(const void *app, uint16_t *cmd, void *pa, void *ta, int32_t inherit, uint32_t flags, void *env, const void *dir, STARTUPINFOW *si, PROCESS_INFORMATION *pi);
            int32_t CloseHandle(void *h);
            void *OpenProcess(uint32_t access, int32_t inherit, uint32_t pid);
            int32_t TerminateProcess(void *h, uint32_t code);
            uint32_t GetLastError(void);
            C, 'kernel32.dll');
    }

    /** $arg quoted for a command line as CommandLineToArgvW and the C runtime read it: in quotes, the backslashes before a quote and at the end doubled. */
    public static function quote(string $arg): string
    {
        if ($arg !== '' && strpbrk($arg, " \t\n\v\"") === false) {
            return $arg;
        }
        $out = '"';
        $n = strlen($arg);
        for ($i = 0; $i < $n; $i++) {
            $slashes = 0;
            while ($i < $n && $arg[$i] === '\\') { $i++; $slashes++; }
            if ($i === $n) {
                $out .= str_repeat('\\', $slashes * 2);
                break;
            }
            $out .= $arg[$i] === '"' ? str_repeat('\\', $slashes * 2 + 1) . '"' : str_repeat('\\', $slashes) . $arg[$i];
        }
        return $out . '"';
    }

    /** Starts $cmd ($cmd[0] the executable's path) and returns its pid. @param list<string> $cmd */
    public static function start(array $cmd): int
    {
        $k = self::k();
        $line = (string)iconv('UTF-8', 'UTF-16LE', implode(' ', array_map(self::quote(...), $cmd))) . "\0\0";
        $buf = $k->new('uint16_t[' . (strlen($line) >> 1) . ']');
        FFI::memcpy($buf, $line, strlen($line));
        $app = (string)iconv('UTF-8', 'UTF-16LE', $cmd[0]) . "\0\0";
        $appBuf = $k->new('uint16_t[' . (strlen($app) >> 1) . ']');
        FFI::memcpy($appBuf, $app, strlen($app));
        $si = $k->new('STARTUPINFOW');
        $si->cb = FFI::sizeof($si);
        $pi = $k->new('PROCESS_INFORMATION');
        if ($k->CreateProcessW(FFI::addr($appBuf[0]), FFI::addr($buf[0]), null, null, 0, 0x08000000 /* CREATE_NO_WINDOW */, null, null, FFI::addr($si), FFI::addr($pi)) === 0) {
            throw new RuntimeException("cannot start {$cmd[0]} (error " . $k->GetLastError() . ')');
        }
        $k->CloseHandle($pi->hThread);
        $k->CloseHandle($pi->hProcess);
        return $pi->dwProcessId;
    }

    /** Stops process $pid, if it runs and may be stopped. */
    public static function kill(int $pid): void
    {
        $k = self::k();
        $h = $k->OpenProcess(0x0001 /* PROCESS_TERMINATE */, 0, $pid);
        if ($h !== null) {
            $k->TerminateProcess($h, 1);
            $k->CloseHandle($h);
        }
    }
}
