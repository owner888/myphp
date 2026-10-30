<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/**
 * How a shell takes a path as one argument, whatever it holds (mygo's
 * quoting): the paths of files dropped on a terminal are quoted for the
 * program's shell.
 *
 * @internal
 */
final class ShellQuote
{
    /**
     * A backslash before each character a POSIX shell, or fish, would read
     * otherwise, as Ghostty escapes them; a path with control characters,
     * as a newline, which would end the command, is quoted as $'…', where
     * they are escapes.
     */
    public const POSIX = 0;
    /**
     * Single quotes, in which PowerShell expands nothing, unlike its double
     * quotes ($(…), $var and `), each single quote in the path doubled. A
     * POSIX shell takes them too.
     */
    public const POWERSHELL = 1;
    /** Double quotes, in which cmd.exe reads & | < > ^ as text. */
    public const CMD = 2;

    /**
     * The quoting of the shell a terminal runs, by the program's name:
     * PowerShell's, cmd.exe's or, for a POSIX shell and any other program,
     * a POSIX shell's, as on macOS (mygo's quotingFor; Windows, where
     * another program takes PowerShell's, when myphp runs there). A Conn's
     * program, which the terminal does not know, is "".
     */
    public static function forProgram(string $program): int
    {
        $name = $program;
        $i = max(strrpos($name, '/') === false ? -1 : strrpos($name, '/'), strrpos($name, '\\') === false ? -1 : strrpos($name, '\\'));
        if ($i >= 0) { $name = substr($name, $i + 1); }
        $name = strtolower($name);
        if (str_ends_with($name, '.exe')) { $name = substr($name, 0, -4); }
        return match ($name) {
            'pwsh', 'powershell' => self::POWERSHELL,
            'cmd' => self::CMD,
            default => self::POSIX,
        };
    }

    /** $path quoted as $q says. */
    public static function quote(int $q, string $path): string
    {
        if ($q === self::POWERSHELL) {
            if ($path !== '' && preg_match('~^[A-Za-z0-9_\-.:\\\\/]+$~', $path) === 1) {
                return $path;
            }
            // PowerShell's single quotes: ' and the curly ones, ‘ ’ ‚ ‛, which a file name may hold.
            return "'" . preg_replace("~['\u{2018}\u{2019}\u{201a}\u{201b}]~u", '$0$0', $path) . "'";
        }
        if ($q === self::CMD) {
            return strpbrk($path, " \t&()[]{}^=;!'+,`~|<>") !== false ? '"' . $path . '"' : $path;
        }
        if (preg_match('~[\x00-\x1f\x7f]|[\x{80}-\x{9f}]~u', $path) === 1 || !mb_check_encoding($path, 'UTF-8')) {
            return self::ansiC($path);
        }
        return addcslashes($path, "\\ ()[]{}<>\"'`!#$&;|*?\t");
    }

    /**
     * $s quoted as bash, zsh and ksh's $'…': control characters and bytes
     * that are not UTF-8 as \xHH, a backslash and a single quote escaped. A
     * shell without it, as fish, reads it as something other than the
     * path, never as a command.
     */
    private static function ansiC(string $s): string
    {
        $out = "$'";
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $len = self::runeLen($s, $i);
            $r = substr($s, $i, $len);
            $cp = $len === 0 ? -1 : mb_ord($r, 'UTF-8');
            if ($len === 0 || $cp < 0x20 || $cp === 0x7f || ($cp >= 0x80 && $cp <= 0x9f)) {
                $bytes = $len === 0 ? $s[$i] : $r;
                foreach (str_split($bytes) as $c) { $out .= sprintf('\\x%02x', ord($c)); }
                $i += max($len, 1);
                continue;
            }
            $out .= ($r === '\\' || $r === "'") ? '\\' . $r : $r;
            $i += $len;
        }
        return $out . "'";
    }

    /** The length of the UTF-8 character at byte $i of $s, or 0 for a byte that starts none. */
    private static function runeLen(string $s, int $i): int
    {
        for ($len = 1; $len <= 4 && $i + $len <= strlen($s); $len++) {
            if (mb_check_encoding(substr($s, $i, $len), 'UTF-8')) { return $len; }
        }
        return 0;
    }
}
