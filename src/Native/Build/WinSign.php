<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * Code signing of Windows apps (mygo's winsign.go): windows.certificate, a
 * .pfx (its password in MYPHP_WINDOWS_CERTIFICATE_PASSWORD), signed with
 * signtool of the Windows SDK and a time stamp (windows.timestampUrl,
 * DigiCert's by default); or windows.signCommand, a command of the app's
 * own with %1 for the file, as for Azure Trusted Signing or a token. It
 * signs the launcher, the installer, its uninstaller (NSIS 3.08 runs
 * `myphp sign-uninstaller` on it) and the programs among the resources
 * that carry no signature, which keeps SmartScreen from warning users.
 *
 * @internal bin/myphp
 */
final class WinSign
{
    /** The environment of the myphp makensis runs to sign the uninstaller: the windows settings and the project's directory, in JSON. */
    public const SETTINGS_ENV = 'MYPHP_SIGN_SETTINGS';

    /** Whether $w (myphp.json's windows) signs. */
    public static function signs(array $w): bool
    {
        return ($w['certificate'] ?? '') !== '' || ($w['signCommand'] ?? '') !== '';
    }

    /** signtool of the Windows SDK: on PATH, else the newest of the Windows Kits' for this machine's architecture; '' for none. */
    public static function signtool(): string
    {
        if (($p = Cmd::look('signtool')) !== '') {
            return $p;
        }
        $kits = rtrim((string)(getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)'), '\\') . '\\Windows Kits\\10\\bin';
        $arch = strtolower((string)getenv('PROCESSOR_ARCHITECTURE')) === 'arm64' ? 'arm64' : 'x64';
        $found = glob("$kits/10.*/$arch/signtool.exe") ?: [];
        natsort($found);
        return $found === [] ? '' : (string)end($found);
    }

    /** Signs $file as $w says, the paths of $w relative to $root. */
    public static function sign(array $w, string $root, string $file): void
    {
        if (($w['signCommand'] ?? '') !== '') {
            $cmd = str_replace('%1', '"' . $file . '"', $w['signCommand']);
            [$code, $out] = Cmd::output(PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', $cmd] : ['/bin/sh', '-c', $cmd], $root);
            if ($code !== 0) {
                throw new RuntimeException("windows.signCommand: exit status $code\n" . trim($out)); // not the command itself, which may hold credentials
            }
            return;
        }
        $cert = \MyPHP\Native\Paths::absolute($w['certificate'], $root);
        $ts = ($w['timestampUrl'] ?? '') !== '' ? $w['timestampUrl'] : 'http://timestamp.digicert.com';
        $password = (string)getenv('MYPHP_WINDOWS_CERTIFICATE_PASSWORD');
        $tool = self::signtool();
        if ($tool === '') {
            throw new RuntimeException('signing needs signtool, of the Windows SDK (winget install Microsoft.WindowsSDK.10.0.26100)');
        }
        $args = [$tool, 'sign', '/f', $cert, '/fd', 'sha256', '/tr', $ts, '/td', 'sha256'];
        if ($password !== '') {
            array_push($args, '/p', $password);
        }
        [$code, $out] = Cmd::output([...$args, $file]);
        if ($code !== 0) {
            throw new RuntimeException("signtool: exit status $code\n" . trim($out));
        }
    }

    /**
     * Whether the file at $path is a PE image, an executable or a DLL, and
     * whether it carries an Authenticode signature: the size of its
     * certificate table, the fifth data directory (mygo's peImage).
     * @return array{0: bool, 1: bool}
     */
    public static function peImage(string $path): array
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return [false, false];
        }
        try {
            $dos = (string)fread($f, 64);
            if (strlen($dos) < 64 || substr($dos, 0, 2) !== 'MZ') {
                return [false, false];
            }
            $pe = unpack('V', substr($dos, 0x3c, 4))[1];
            fseek($f, $pe);
            $h = (string)fread($f, 26);
            if (strlen($h) < 26 || substr($h, 0, 4) !== "PE\0\0") {
                return [false, false];
            }
            $optionalSize = unpack('v', substr($h, 20, 2))[1];
            $dirs = match (unpack('v', substr($h, 24, 2))[1]) { 0x10b => 96, 0x20b => 112, default => null };
            if ($dirs === null) {
                return [false, false];
            }
            if ($optionalSize < $dirs + 5 * 8) {
                return [true, false];
            }
            fseek($f, $pe + 24 + $dirs + 4 * 8); // the certificate table: its offset, then its size
            $d = (string)fread($f, 8);
            return [true, strlen($d) === 8 && unpack('V', substr($d, 4, 4))[1] > 0];
        } finally {
            fclose($f);
        }
    }

    /** Signs the executables and DLLs under $dir (the resources copied) that carry no signature: those signed by their publishers keep theirs. @param list<string> $paths */
    public static function signUnsigned(array $w, string $root, array $paths): void
    {
        foreach ($paths as $path) {
            $files = is_dir($path) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) : [new \SplFileInfo($path)];
            foreach ($files as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    [$image, $signed] = self::peImage($file->getPathname());
                    if ($image && !$signed) {
                        self::sign($w, $root, $file->getPathname());
                    }
                }
            }
        }
    }
}
