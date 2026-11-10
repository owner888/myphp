<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * The tools packaging runs (codesign, hdiutil, xcrun, gh, curl), as
 * mygo's command and exec.Command(...).CombinedOutput().
 *
 * @internal
 */
final class Cmd
{
    /** The null device, Windows's NUL elsewhere /dev/null. */
    public const NULL = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

    /** Runs $cmd and returns its exit status and what it wrote to stdout and stderr together. @param list<string> $cmd @return array{int, string} */
    public static function output(array $cmd, ?string $cwd = null): array
    {
        $p = proc_open($cmd, [0 => ['file', self::NULL, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
        if ($p === false) {
            throw new RuntimeException("cannot run {$cmd[0]}");
        }
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($p), $out];
    }

    /** Runs $cmd, failing with its output unless it succeeds (mygo's command). @param list<string> $cmd */
    public static function run(array $cmd, ?string $cwd = null): string
    {
        [$code, $out] = self::output($cmd, $cwd);
        if ($code !== 0) {
            throw new RuntimeException(implode(' ', $cmd) . ": exit status $code\n" . trim($out));
        }
        return $out;
    }

    /** The path of $name on PATH, or '': on Windows with each extension of PATHEXT (.exe, .cmd), as cmd looks. */
    public static function look(string $name): string
    {
        $exts = PHP_OS_FAMILY === 'Windows' ? ['', ...explode(';', strtolower((string)(getenv('PATHEXT') ?: '.com;.exe;.bat;.cmd')))] : [''];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            foreach ($exts as $ext) {
                if ($dir !== '' && is_file("$dir/$name$ext") && (PHP_OS_FAMILY === 'Windows' ? $ext !== '' || str_contains($name, '.') : is_executable("$dir/$name"))) {
                    return "$dir/$name$ext";
                }
            }
        }
        return '';
    }
}
