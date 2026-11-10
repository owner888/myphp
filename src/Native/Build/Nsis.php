<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * The Windows installer, made with NSIS (mygo's nsis.go): a per-user
 * install in %LOCALAPPDATA%\Programs, where the app can update itself,
 * with a Start menu shortcut, a desktop shortcut the finish page offers,
 * the app's file associations and URL schemes, and an uninstaller that
 * Settings > Apps lists. Windows rarely has NSIS installed, so `myphp
 * build` downloads it there when it needs it (RELEASE, its SHA-256
 * checked), as Tauri and electron-builder do.
 *
 * @internal bin/myphp
 */
final class Nsis
{
    /** The NSIS that builds download: its version, SHA-256 and where it is. */
    public const RELEASE = [
        'version' => '3.13',
        'sha256' => 'ba63dffc4410ee89193e1cb5a41989991bd77c61068da17e3156d136b7b0b3d8',
        'urls' => ['https://downloads.sourceforge.net/project/nsis/NSIS%203/3.13/nsis-3.13.zip'],
    ];

    /** What the names of the app's installers start with, before their version (install.ps1 finds them by it). */
    public static function installerPrefix(array $c): string
    {
        return Slug::ofConfig($c) . '-setup-';
    }

    /** "<slug>-setup-<version>-<arch>.exe": each architecture's apart, so that two uploaded to one release keep both. */
    public static function installerName(array $c, string $arch): string
    {
        return self::installerPrefix($c) . $c['version'] . "-$arch.exe";
    }

    /** Where downloads keep NSIS: the user's cache (%LOCALAPPDATA%\myphp). */
    public static function cacheDir(): string
    {
        $base = (string)(getenv('LOCALAPPDATA') ?: getenv('XDG_CACHE_HOME') ?: (getenv('HOME') . '/.cache'));
        return rtrim($base, '\\/') . '/myphp/nsis-' . self::RELEASE['version'];
    }

    /** makensis in $dir, as NSIS's zip has it, or ''. */
    public static function executable(string $dir): string
    {
        foreach (["$dir/Bin/makensis.exe", "$dir/makensis.exe"] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return '';
    }

    /** The NSIS compiler: an installed one, else one a build downloaded, else, on Windows, one downloaded now ($log tells); '' elsewhere without one. */
    public static function compiler(\Closure $log): string
    {
        if (($p = Cmd::look('makensis')) !== '') {
            return $p;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return '';
        }
        foreach (['ProgramFiles(x86)', 'ProgramFiles'] as $env) {
            if (($dir = (string)getenv($env)) !== '' && is_file("$dir/NSIS/makensis.exe")) {
                return "$dir/NSIS/makensis.exe";
            }
        }
        $dir = self::cacheDir();
        if (($p = self::executable($dir)) !== '') {
            return $p;
        }
        return self::download($dir, $log);
    }

    /** Downloads RELEASE into $dir with Windows's curl, checks its SHA-256, unzips it with Windows's tar and returns its makensis. */
    private static function download(string $dir, \Closure $log): string
    {
        $v = self::RELEASE['version'];
        $log("Downloading NSIS $v for the Windows installer");
        $parent = dirname($dir);
        is_dir($parent) || @mkdir($parent, 0o755, true);
        $work = "$parent/.nsis-" . bin2hex(random_bytes(4));
        mkdir($work, 0o755);
        try {
            $zip = "$work/nsis.zip";
            $system = rtrim((string)getenv('SystemRoot'), '\\/') . '\\System32\\';
            $errors = [];
            foreach (self::RELEASE['urls'] as $url) {
                [$code, $out] = Cmd::output([$system . 'curl.exe', '-fsSL', '--retry', '2', '-o', $zip, $url]);
                if ($code === 0 && hash_file('sha256', $zip) === self::RELEASE['sha256']) {
                    $errors = [];
                    break;
                }
                $errors[] = $code !== 0 ? "$url: " . trim($out) : "$url: not the SHA-256 of NSIS $v";
            }
            if ($errors !== []) {
                throw new RuntimeException("downloading NSIS $v (install it, https://nsis.sourceforge.io, or build with -skip-installer):\n" . implode("\n", $errors));
            }
            [$code, $out] = Cmd::output([$system . 'tar.exe', '-xf', $zip, '-C', $work]);
            if ($code !== 0) {
                throw new RuntimeException("unpacking NSIS: $out");
            }
            $top = "$work/nsis-$v";
            if (self::executable($top) === '') {
                throw new RuntimeException('unpacking NSIS: the archive holds no makensis.exe');
            }
            if (!@rename($top, $dir) && self::executable($dir) === '') {
                throw new RuntimeException("cannot move NSIS to $dir");
            }
        } finally {
            \MyPHP\Update::removeAll($work);
        }
        $log("Downloaded NSIS $v");
        return self::executable($dir);
    }

    /** makensis's version, [major, minor], zeros when it does not tell ("v3.13", or "v3.08-3" of a distribution's). @return array{0: int, 1: int} */
    public static function version(string $tool): array
    {
        [$code, $out] = Cmd::output([$tool, '-VERSION']);
        return $code === 0 && preg_match('/^v?(\d+)\.(\d+)/', trim($out), $m) === 1 ? [(int)$m[1], (int)$m[2]] : [0, 0];
    }

    /** $s escaped for an NSIS string in double quotes. */
    public static function escape(string $s): string
    {
        return strtr($s, ['$' => '$$', '"' => '$\\"', "\n" => '$\\n', "\r" => '$\\r', "\t" => '$\\t']);
    }

    public static function str(string $s): string
    {
        return '"' . self::escape($s) . '"';
    }

    /**
     * The NSIS languages, by language and region, language and script, or
     * language alone (mygo's nsisLanguages): the language files every NSIS 3
     * has, which MUI_LANGUAGE takes without .nlf.
     */
    private const LANGUAGES = [
        'af' => 'Afrikaans', 'ar' => 'Arabic', 'be' => 'Belarusian', 'bg' => 'Bulgarian', 'bs' => 'Bosnian', 'ca' => 'Catalan', 'cs' => 'Czech', 'cy' => 'Welsh',
        'da' => 'Danish', 'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'es-419' => 'SpanishInternational', 'et' => 'Estonian', 'eu' => 'Basque',
        'fa' => 'Farsi', 'fi' => 'Finnish', 'fr' => 'French', 'ga' => 'Irish', 'gl' => 'Galician', 'he' => 'Hebrew', 'hr' => 'Croatian', 'hu' => 'Hungarian',
        'hy' => 'Armenian', 'id' => 'Indonesian', 'is' => 'Icelandic', 'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian', 'ko' => 'Korean', 'lb' => 'Luxembourgish',
        'lt' => 'Lithuanian', 'lv' => 'Latvian', 'mk' => 'Macedonian', 'mn' => 'Mongolian', 'ms' => 'Malay', 'nb' => 'Norwegian', 'nl' => 'Dutch', 'nn' => 'NorwegianNynorsk',
        'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'PortugueseBR', 'pt-br' => 'PortugueseBR', 'pt-pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak',
        'sl' => 'Slovenian', 'sq' => 'Albanian', 'sr' => 'Serbian', 'sr-latn' => 'SerbianLatin', 'sv' => 'Swedish', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'uz' => 'Uzbek', 'vi' => 'Vietnamese', 'zh' => 'SimpChinese', 'zh-hans' => 'SimpChinese', 'zh-cn' => 'SimpChinese', 'zh-sg' => 'SimpChinese',
        'zh-hant' => 'TradChinese', 'zh-tw' => 'TradChinese', 'zh-hk' => 'TradChinese', 'zh-mo' => 'TradChinese',
    ];

    /** The NSIS language of BCP 47 tag $tag (its language and region, then script, then language), or null. */
    public static function language(string $tag): ?string
    {
        $parts = explode('-', strtolower(str_replace('_', '-', $tag)));
        $keys = [];
        if (count($parts) >= 3) { $keys[] = "$parts[0]-$parts[2]"; }
        if (count($parts) >= 2) { $keys[] = "$parts[0]-$parts[1]"; }
        $keys[] = $parts[0];
        foreach ($keys as $k) {
            if (isset(self::LANGUAGES[$k])) {
                return self::LANGUAGES[$k];
            }
        }
        return null;
    }

    /**
     * The installer's languages (mygo's nsisLanguageMacros): a MUI_LANGUAGE
     * each, the app's own first, which the installer falls back to, and the
     * finish page's label in each ($langs: [tag, the label of the desktop
     * shortcut]); English alone for an app of no languages. A language NSIS
     * has no file of fails, as it would fail makensis.
     * @param list<array{0: string, 1: string}> $langs
     */
    public static function languageMacros(array $langs): string
    {
        $langs = $langs === [] ? [['en', '']] : $langs;
        $seen = [];
        $macros = $labels = '';
        foreach ($langs as [$tag, $label]) {
            $name = self::language($tag) ?? throw new RuntimeException("the Windows installer cannot be in $tag, which NSIS has no language file of");
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $macros .= '!insertmacro MUI_LANGUAGE ' . self::str($name) . "\n";
            $label = $label !== '' ? $label : \MyPHP\L10n::in($tag)->get('desktopShortcut');
            $labels .= 'LangString MYPHP_DESKTOP_SHORTCUT ${LANG_' . strtoupper($name) . '} ' . self::str($label) . "\n";
        }
        return $macros . $labels;
    }

    /**
     * The PowerShell that quits the processes of the app, those whose
     * executables are in the folder $env:MYPHP_APP_DIR (mygo's closeAppPS1):
     * it asks their windows to close and, after 10 s, stops those still
     * running, as an app in the tray keeps running without windows. With
     * $env:MYPHP_APP_CHECK=1 it only tells whether any runs: exit code 2
     * when one does; 1 is for processes that did not stop.
     */
    public const CLOSE_APP_PS1 = <<<'PS'
        $ErrorActionPreference = 'SilentlyContinue'
        $dir = $env:MYPHP_APP_DIR.TrimEnd('\') + '\'
        # Not the installer, which starts this.
        $installer = (Get-CimInstance Win32_Process -Filter "ProcessId=$PID").ParentProcessId
        function Get-AppProcess {
        	@(Get-CimInstance Win32_Process | Where-Object {
        		$_.ExecutablePath -and $_.ProcessId -ne $PID -and $_.ProcessId -ne $installer -and $_.ExecutablePath.StartsWith($dir, [StringComparison]::OrdinalIgnoreCase)
        	} | ForEach-Object { Get-Process -Id $_.ProcessId })
        }
        $running = Get-AppProcess
        if ($running.Count -eq 0) { exit 0 }
        if ($env:MYPHP_APP_CHECK -eq '1') { exit 2 }
        foreach ($p in $running) { [void]$p.CloseMainWindow() }
        $deadline = (Get-Date).AddSeconds(10)
        foreach ($p in $running) {
        	$left = [int]($deadline - (Get-Date)).TotalMilliseconds
        	if ($left -lt 0) { $left = 0 }
        	[void]$p.WaitForExit($left)
        }
        $running = @(Get-AppProcess)
        if ($running.Count -eq 0) { exit 0 }
        $running | Stop-Process -Force
        $deadline = (Get-Date).AddSeconds(10)
        foreach ($p in $running) {
        	$left = [int]($deadline - (Get-Date)).TotalMilliseconds
        	if ($left -lt 0) { $left = 0 }
        	[void]$p.WaitForExit($left)
        }
        if (@(Get-AppProcess).Count -gt 0) { exit 1 }
        exit 0
        PS;

    /**
     * The installer's and the uninstaller's CloseApp, which quit the app
     * running from the folder on the stack before its files are replaced or
     * deleted (CLOSE_APP_PS1, written to a file line by line: NSIS strings
     * hold 1024 characters). A silent run (/S), as an update's, quits it
     * without asking; the others ask first.
     */
    private static function closeApp(string $name): string
    {
        $write = '';
        foreach (explode("\n", self::CLOSE_APP_PS1) as $line) {
            $write .= '  FileWrite $2 ' . self::str($line . "\r\n") . "\n";
        }
        $command = "'\"\$1\" -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command \"& ([scriptblock]::Create([IO.File]::ReadAllText(\$\$env:MYPHP_APP_SCRIPT)))\"'";
        $running = self::str("$name is running. Close it to continue?");
        $stuck = self::str("$name is still running. Quit it, then try again.");
        $out = '';
        foreach (['', 'un.'] as $prefix) {
            $out .= <<<NSIS
                ; Quits the app running from the folder on the stack.
                Function {$prefix}CloseApp
                  Exch \$0
                  Push \$1
                  Push \$2
                  ; The PowerShell of the system's own architecture, not the 32-bit one
                  ; that a 32-bit installer would otherwise start.
                  StrCpy \$1 "\$WINDIR\\Sysnative\\WindowsPowerShell\\v1.0\\powershell.exe"
                  IfFileExists \$1 +2
                  StrCpy \$1 "\$SYSDIR\\WindowsPowerShell\\v1.0\\powershell.exe"
                  IfFileExists \$1 0 done
                  InitPluginsDir
                  FileOpen \$2 "\$PLUGINSDIR\\close-app.ps1" w

                NSIS . $write . <<<NSIS
                  FileClose \$2
                  System::Call 'Kernel32::SetEnvironmentVariable(t "MYPHP_APP_SCRIPT", t "\$PLUGINSDIR\\close-app.ps1")'
                  System::Call 'Kernel32::SetEnvironmentVariable(t "MYPHP_APP_DIR", t r0)'
                  IfSilent close
                  System::Call 'Kernel32::SetEnvironmentVariable(t "MYPHP_APP_CHECK", t "1")'
                  nsExec::Exec $command
                  Pop \$2
                  System::Call 'Kernel32::SetEnvironmentVariable(t "MYPHP_APP_CHECK", t "")'
                  StrCmp \$2 "2" 0 done
                  MessageBox MB_OKCANCEL|MB_ICONEXCLAMATION $running IDOK close
                  Abort
                  close:
                  nsExec::Exec $command
                  Pop \$2
                  StrCmp \$2 "1" 0 done
                  MessageBox MB_OK|MB_ICONSTOP $stuck /SD IDOK
                  SetErrorLevel 2
                  Quit
                  done:
                  Pop \$2
                  Pop \$1
                  Pop \$0
                FunctionEnd


                NSIS;
        }
        return $out;
    }

    /** The commands that register, and unregister, the app's file associations and URL schemes for the user, opening them with $exe (mygo's nsisAssociations). @return array{0: string, 1: string} */
    private static function associations(array $c, string $name, string $exe): array
    {
        $r = $u = '';
        $open = "'\"\$INSTDIR\\" . self::escape($exe) . "\" \"%1\"'";
        $prefix = (string)preg_replace('/[^.0-9a-zA-Z]/', '', $c['identifier']);
        foreach ($c['fileAssociations'] ?? [] as $fa) {
            foreach ($fa['ext'] as $ext) {
                $progId = "$prefix." . strtolower($ext);
                $class = "Software\\Classes\\$progId";
                $label = ($fa['name'] ?? '') !== '' ? $fa['name'] : strtoupper($ext) . ' file';
                $r .= '  WriteRegStr HKCU ' . self::str($class) . ' "" ' . self::str($label) . "\n";
                $r .= '  WriteRegStr HKCU ' . self::str("$class\\DefaultIcon") . ' "" "$INSTDIR\\' . self::escape($exe) . ",0\"\n";
                $r .= '  WriteRegStr HKCU ' . self::str("$class\\shell\\open\\command") . " \"\" $open\n";
                $r .= '  WriteRegStr HKCU ' . self::str("Software\\Classes\\.$ext\\OpenWithProgids") . ' ' . self::str($progId) . " \"\"\n";
                $u .= '  DeleteRegKey HKCU ' . self::str($class) . "\n";
                $u .= '  DeleteRegValue HKCU ' . self::str("Software\\Classes\\.$ext\\OpenWithProgids") . ' ' . self::str($progId) . "\n";
            }
        }
        foreach ($c['urlSchemes'] ?? [] as $scheme) {
            $key = "Software\\Classes\\$scheme";
            $r .= '  WriteRegStr HKCU ' . self::str($key) . ' "" ' . self::str("URL:$name") . "\n";
            $r .= '  WriteRegStr HKCU ' . self::str($key) . " \"URL Protocol\" \"\"\n";
            $r .= '  WriteRegStr HKCU ' . self::str("$key\\shell\\open\\command") . " \"\" $open\n";
            $u .= '  DeleteRegKey HKCU ' . self::str($key) . "\n";
        }
        if ($r !== '') { // Explorer told the associations changed
            $notify = "  System::Call 'shell32::SHChangeNotify(i 0x08000000, i 0, p 0, p 0)'\n";
            [$r, $u] = [$r . $notify, $u . $notify];
        }
        return [$r, $u];
    }

    /**
     * windows.installerInclude, a script of the app's own (mygo's
     * nsisHooks): included after MUI2.nsh and the languages, its customInit
     * macro run in .onInit, customInstall at the end of the install and
     * customUnInstall before the uninstaller removes $INSTDIR, each when it
     * defines it. @return array{0: string, 1: string, 2: string}
     */
    private static function hooks(string $path): array
    {
        if ($path === '') {
            return ['', '', ''];
        }
        if (!is_file($path)) {
            throw new RuntimeException("windows.installerInclude: $path is not a file (the path is relative to the project's directory)");
        }
        return [
            '!include ' . self::str($path) . "\n!ifmacrodef customInit\nFunction .onInit\n  !insertmacro customInit\nFunctionEnd\n!endif\n",
            "  !ifmacrodef customInstall\n    !insertmacro customInstall\n  !endif\n",
            "  !ifmacrodef customUnInstall\n    !insertmacro customUnInstall\n  !endif\n",
        ];
    }

    /**
     * The installer's script (mygo's installerScript): $out made of the
     * files in $stage named $installed, the launcher $exe among them, with
     * the icon (an .ico, '' for none), the languages (languageMacros), the
     * uninstaller signed by $signing (an !uninstfinalize, '' for none).
     * $c is myphp.json, its windows section Windows::config's.
     * @param list<string> $installed
     */
    public static function script(array $c, string $out, string $stage, array $installed, string $exe, string $ico, string $languages, string $signing, string $include): string
    {
        $w = $c['windows'];
        $name = $w['displayName'];
        $uninstallKey = 'Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\' . $c['identifier'];
        $shortcut = self::escape(Windows::shortcutName($c)) . '.lnk';
        [$register, $unregister] = self::associations($c, $name, $exe);
        [$includeHook, $installHook, $uninstallHook] = self::hooks($include);
        $files = '';
        foreach ($installed as $f) {
            $p = str_replace('/', '\\', "$stage/$f");
            $files .= (is_dir("$stage/$f") ? '  File /r ' : '  File ') . self::str($p) . "\n";
        }
        $icon = $ico === '' ? '' : '!define MUI_ICON ' . self::str($ico) . "\n!define MUI_UNICON " . self::str($ico) . "\n";
        $nameCmd = str_contains($name, '&') ? 'Name ' . self::str($name) . ' ' . self::str(str_replace('&', '&&', $name)) : 'Name ' . self::str($name);
        $e = self::escape($exe);
        $entry = '';
        $reg = fn(string $k, string $v) => '  WriteRegStr HKCU ' . self::str($uninstallKey) . ' ' . self::str($k) . " $v\n";
        $entry .= $reg('DisplayName', self::str($name)) . $reg('DisplayVersion', self::str($c['version'])) . $reg('DisplayIcon', "\"\$INSTDIR\\$e\"");
        if (($w['publisher'] ?? '') !== '') { $entry .= $reg('Publisher', self::str($w['publisher'])); }
        $entry .= $reg('InstallLocation', '"$INSTDIR"') . $reg('UninstallString', "'\"\$INSTDIR\\Uninstall.exe\"'") . $reg('QuietUninstallString', "'\"\$INSTDIR\\Uninstall.exe\" /S'");
        $entry .= '  WriteRegDWORD HKCU ' . self::str($uninstallKey) . " \"NoModify\" 1\n" . '  WriteRegDWORD HKCU ' . self::str($uninstallKey) . " \"NoRepair\" 1\n";
        $renamed = '';
        $old = pathinfo($exe, PATHINFO_FILENAME);
        if (strcasecmp($old, Windows::shortcutName($c)) !== 0) { // shortcuts an earlier version made under the executable's name, when windows.displayName names them now
            $o = self::escape($old) . '.lnk';
            $renamed = "  Delete \"\$SMPROGRAMS\\$o\"\n  IfFileExists \"\$DESKTOP\\$o\" 0 +3\n  Delete \"\$DESKTOP\\$o\"\n  Call CreateDesktopShortcut\n";
        }
        $dirName = self::escape($w['installDirName']);
        return "Unicode true\nManifestDPIAware true\nSetCompressor /SOLID lzma\n$nameCmd\nOutFile " . self::str($out) . "\n" . $signing
            . "InstallDir \"\$LOCALAPPDATA\\Programs\\$dirName\"\n"
            . "; An installed app is updated in its folder, the one the user chose or one of another windows.installDirName.\n"
            . 'InstallDirRegKey HKCU ' . self::str($uninstallKey) . " \"InstallLocation\"\nRequestExecutionLevel user\nBrandingText \" \"\n"
            . $icon
            . "!define MUI_FINISHPAGE_RUN \"\$INSTDIR\\$e\"\n!define MUI_FINISHPAGE_SHOWREADME\n!define MUI_FINISHPAGE_SHOWREADME_TEXT \"\$(MYPHP_DESKTOP_SHORTCUT)\"\n!define MUI_FINISHPAGE_SHOWREADME_FUNCTION CreateDesktopShortcut\n"
            . "!include \"MUI2.nsh\"\n!insertmacro MUI_PAGE_DIRECTORY\n!insertmacro MUI_PAGE_INSTFILES\n!insertmacro MUI_PAGE_FINISH\n!insertmacro MUI_UNPAGE_CONFIRM\n!insertmacro MUI_UNPAGE_INSTFILES\n"
            . $languages . $includeHook . "\n" . self::closeApp($name)
            . "Section\n  Push \"\$INSTDIR\"\n  Call CloseApp\n  SetOutPath \"\$INSTDIR\"\n" . $files . "  WriteUninstaller \"\$INSTDIR\\Uninstall.exe\"\n"
            . $renamed . "  CreateShortCut \"\$SMPROGRAMS\\$shortcut\" \"\$INSTDIR\\$e\"\n" . $entry . $register . $installHook . "SectionEnd\n\n"
            . "; The finish page offers it, so silent installs (/S) make none.\nFunction CreateDesktopShortcut\n  SetOutPath \"\$INSTDIR\"\n  CreateShortCut \"\$DESKTOP\\$shortcut\" \"\$INSTDIR\\$e\"\nFunctionEnd\n\n"
            . "; Deletes the shortcut on the stack, trying again for 5 s while Explorer holds a new one.\nFunction un.DeleteShortcut\n  Exch \$0\n  Push \$1\n  StrCpy \$1 50\n  retry:\n    ClearErrors\n    Delete \$0\n    IfErrors 0 done\n    IntOp \$1 \$1 - 1\n    IntCmp \$1 0 done\n    Sleep 100\n    Goto retry\n  done:\n  Pop \$1\n  Pop \$0\nFunctionEnd\n\n"
            . "Section \"Uninstall\"\n  Push \"\$INSTDIR\"\n  Call un.CloseApp\n  Push \"\$SMPROGRAMS\\$shortcut\"\n  Call un.DeleteShortcut\n  Push \"\$DESKTOP\\$shortcut\"\n  Call un.DeleteShortcut\n  DeleteRegKey HKCU " . self::str($uninstallKey) . "\n"
            . $unregister . $uninstallHook . "  ; Last: the uninstaller runs from a copy of itself that nothing waits for.\n  RMDir /r \"\$INSTDIR\"\nSectionEnd\n";
    }

    /** Writes the script to $path as UTF-8 with a byte order mark: NSIS reads one without as the machine's code page, and a name or path of other characters would not match. */
    public static function write(string $path, string $script): void
    {
        file_put_contents($path, "\xEF\xBB\xBF" . $script);
    }
}
