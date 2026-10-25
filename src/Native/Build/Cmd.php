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
    /** Runs $cmd and returns its exit status and what it wrote to stdout and stderr together. @param list<string> $cmd @return array{int, string} */
    public static function output(array $cmd, ?string $cwd = null): array
    {
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
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

    /** The path of $name on PATH, or ''. */
    public static function look(string $name): string
    {
        foreach (explode(':', (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/$name") && is_executable("$dir/$name")) {
                return "$dir/$name";
            }
        }
        return '';
    }
}
