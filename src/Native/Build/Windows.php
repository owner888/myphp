<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * What `myphp build` makes of a Windows app (myphp's: mygo compiles a Go
 * executable): a directory of its launcher, <Name>.exe, the PHP that built
 * it in php/ (its executable, its DLLs and its extensions: Windows has no
 * package to depend on), the app's files and the framework's in app/, the
 * frontend, the resources, info.json (Linux::info, which Native\Bundle
 * reads as on Linux) and the icon, and a zip of it. The launcher is a
 * small C program of the GUI subsystem: it runs php.exe without a console
 * window (CREATE_NO_WINDOW) on app/<entry>, in app/, with no php.ini but
 * the extensions the building PHP had loaded, naming its directory in
 * MYPHP_BUNDLE, and gives back its exit code.
 *
 * @internal bin/myphp
 */
final class Windows
{
    /** The machine types the launcher is compiled for, by architecture. */
    public const ARCHES = ['amd64', 'arm64'];

    /**
     * myphp.json's windows, checked, its defaults filled in (mygo's
     * Windows): displayName, the name Windows shows the app by (its
     * shortcuts, Settings > Apps, the installer), the name's by default;
     * installDirName, its folder in %LOCALAPPDATA%\Programs, the name's by
     * default; publisher, Settings > Apps's, package.json's author's by
     * default; certificate, signCommand and timestampUrl (WinSign); and
     * installerInclude, an NSIS script of the app's own (Nsis::hooks).
     * $bad fails with a message.
     * @param \Closure(string): never $bad
     */
    public static function config(mixed $w, string $name, string $author, \Closure $bad): array
    {
        $w ??= [];
        is_array($w) && ($w === [] || !array_is_list($w)) || $bad('windows is an object');
        foreach (['displayName', 'installDirName', 'publisher', 'certificate', 'signCommand', 'timestampUrl', 'installerInclude'] as $k) {
            $w[$k] ??= '';
            is_string($w[$k]) || $bad("windows.$k is a string");
        }
        $w['displayName'] = $w['displayName'] !== '' ? $w['displayName'] : $name;
        $w['installDirName'] = $w['installDirName'] !== '' ? $w['installDirName'] : $name;
        preg_match('/[<>:"\/\\|?*\x00-\x1f]/', $w['installDirName']) === 0 && !in_array(rtrim($w['installDirName'], '. '), ['', '.', '..'], true) || $bad('windows.installDirName ' . json_encode($w['installDirName']) . ' is not a folder name');
        $w['publisher'] = $w['publisher'] !== '' ? $w['publisher'] : trim(explode('<', $author)[0]);
        $w['certificate'] === '' || $w['signCommand'] === '' || $bad('windows has certificate or signCommand, not both');
        return $w;
    }

    /** The launcher's file name: the app's name, its characters a file name cannot hold left out, ".exe". */
    public static function exeName(array $c): string
    {
        $base = trim((string)preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '', $c['name']), '. ');
        return ($base !== '' ? $base : Slug::ofConfig($c)) . '.exe';
    }

    /** The name of the app's shortcuts: windows.displayName, its characters a file name cannot hold left out (the launcher's name where none is left). */
    public static function shortcutName(array $c): string
    {
        $name = trim((string)preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '', $c['windows']['displayName']), '. ');
        return $name !== '' ? $name : pathinfo(self::exeName($c), PATHINFO_FILENAME);
    }

    /**
     * The flags of php.exe that load $php's extensions, as it has them
     * loaded now, from the bundle's php\ext: each one whose DLL is in its
     * extension directory (php_<name>.dll), OPcache as a Zend extension.
     * @return list<string>
     */
    public static function extensionFlags(string $extDir): array
    {
        $flags = [];
        foreach (get_loaded_extensions() as $name) {
            $file = strtolower($name === 'Zend OPcache' ? 'opcache' : $name);
            if (!is_file("$extDir/php_$file.dll")) {
                continue; // built in
            }
            array_push($flags, '-d', ($name === 'Zend OPcache' ? 'zend_extension=' : 'extension=') . $file);
        }
        return $flags;
    }

    /** The building PHP's extension directory, absolute. */
    public static function extensionDir(): string
    {
        $dir = (string)ini_get('extension_dir');
        if ($dir === '' || !\MyPHP\Native\Paths::isAbsolute($dir)) {
            $dir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . ($dir === '' ? 'ext' : $dir);
        }
        return rtrim($dir, '\\/');
    }

    /** A C string literal of wide characters of $s. */
    private static function wide(string $s): string
    {
        return 'L"' . addcslashes($s, "\"\\") . '"';
    }

    /** The launcher's C source (see the class), running php.exe with $flags (PHP's, then the extensions') on app\$entry. @param list<string> $flags */
    public static function launcherSource(array $flags, string $entry): string
    {
        $args = self::wide(implode(' ', array_map(fn(string $f): string => str_contains($f, ' ') ? "\"$f\"" : $f, $flags)));
        $file = self::wide(str_replace('/', '\\', $entry));
        return <<<C
        #define UNICODE
        #define _UNICODE
        #include <windows.h>
        #include <stdio.h>
        #include <wchar.h>
        static void fail(const wchar_t *what) {
            wchar_t msg[512];
            swprintf(msg, 512, L"%ls (error %lu)", what, GetLastError());
            MessageBoxW(NULL, msg, L"Launcher", MB_ICONERROR);
        }
        int WINAPI wWinMain(HINSTANCE inst, HINSTANCE prev, PWSTR unused, int show) {
            static wchar_t dir[32768], php[32800], app[32800];
            DWORD n = GetModuleFileNameW(NULL, dir, 32768);
            if (n == 0 || n >= 32768) { fail(L"cannot find the launcher"); return 1; }
            wchar_t *slash = wcsrchr(dir, L'\\\\');
            if (slash != NULL) *slash = 0;
            SetEnvironmentVariableW(L"MYPHP_BUNDLE", dir);
            swprintf(php, 32800, L"%ls\\\\php\\\\php.exe", dir);
            swprintf(app, 32800, L"%ls\\\\app", dir);
            /* the arguments it was given: its command line past its own name */
            wchar_t *rest = GetCommandLineW();
            if (*rest == L'"') { rest++; while (*rest && *rest != L'"') rest++; if (*rest) rest++; }
            else { while (*rest && *rest != L' ' && *rest != L'\\t') rest++; }
            while (*rest == L' ' || *rest == L'\\t') rest++;
            size_t size = wcslen(rest) + 4 * 32800 + 8192;
            wchar_t *line = (wchar_t *)HeapAlloc(GetProcessHeap(), 0, size * sizeof(wchar_t));
            if (line == NULL) { fail(L"no memory"); return 1; }
            swprintf(line, size, L"\"%ls\" -n -d \"extension_dir=%ls\\\\php\\\\ext\" %ls \"%ls\\\\%ls\" %ls", php, dir, $args, app, $file, rest);
            STARTUPINFOW si; PROCESS_INFORMATION pi;
            ZeroMemory(&si, sizeof si); si.cb = sizeof si;
            if (!CreateProcessW(php, line, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, app, &si, &pi)) { fail(L"cannot start php\\\\php.exe"); return 1; }
            WaitForSingleObject(pi.hProcess, INFINITE);
            DWORD code = 1;
            GetExitCodeProcess(pi.hProcess, &code);
            CloseHandle(pi.hThread); CloseHandle(pi.hProcess);
            return (int)code;
        }

        C;
    }

    /**
     * The C compiler of the launcher, on Windows: MinGW's gcc (or cc,
     * clang) of the GNU conventions, else MSVC's cl in a developer prompt;
     * [its command, 'gnu' or 'msvc'].
     * @return array{0: string, 1: string}
     */
    public static function compiler(): array
    {
        foreach (['gcc', 'cc', 'clang'] as $cc) {
            if (($path = Cmd::look($cc)) !== '') {
                return [$path, 'gnu'];
            }
        }
        if (($cl = Cmd::look('cl')) !== '') {
            return [$cl, 'msvc'];
        }
        throw new RuntimeException('no C compiler for the launcher: install MinGW-w64 (gcc, as MSYS2 or WinLibs have it) or build in a Visual Studio developer prompt (cl)');
    }

    /** The command that compiles $c into the GUI executable $exe, with icon resource $res (a compiled .res or .o, '' for none). @return list<string> */
    public static function compileCommand(array $compiler, string $c, string $exe, string $res): array
    {
        [$cc, $kind] = $compiler;
        if ($kind === 'msvc') {
            return [$cc, '/nologo', '/O2', '/W3', "/Fe:$exe", $c, ...($res !== '' ? [$res] : []), '/link', '/SUBSYSTEM:WINDOWS', 'user32.lib'];
        }
        return [$cc, '-O2', '-Wall', '-municode', '-mwindows', '-o', $exe, $c, ...($res !== '' ? [$res] : [])];
    }

    /** An .ico of a PNG, the PNG itself its one image (Windows Vista's PNG icons), 256 pixels or less a side, as Explorer shows it. */
    public static function ico(string $png): string
    {
        $head = substr($png, 0, 24);
        if (strlen($head) < 24 || substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('the icon is no PNG');
        }
        $w = unpack('N', substr($head, 16, 4))[1];
        $h = unpack('N', substr($head, 20, 4))[1];
        if ($w > 256 || $h > 256) {
            throw new RuntimeException("the icon is $w by $h pixels: an .ico's are 256 or less a side");
        }
        // ICONDIR, one ICONDIRENTRY (256 is 0), then the PNG
        return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $w % 256, $h % 256, 0, 0, 1, 32, strlen($png), 22) . $png;
    }
}
