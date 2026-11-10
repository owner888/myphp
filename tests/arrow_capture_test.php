<?php
// AGENTS.md's rule 12 checked across the code: an arrow function copies
// what it reads as it is made, so App::wait(fn() => $done) never sees a
// $done that a closure of the same function sets by reference (use (&$done)):
// it waits on what $done was. Each function's App::wait arrow functions
// and its closures' by-reference variables are read from PHP's tokens.
declare(strict_types=1);

namespace MyPHP\Tests;

/**
 * The App::wait arrow functions of $code reading a variable that a closure
 * of the same function takes by reference: [line, variable] each.
 * @return list<array{0: int, 1: string}>
 */
function arrowCaptureViolations(string $code): array
{
    $tokens = token_get_all($code);
    $out = [];
    /** @var list<array{depth: int, refs: array<string, true>, waits: list<array{0: int, 1: string}>}> $scopes the functions open, the file's own first */
    $scopes = [['depth' => -1, 'refs' => [], 'waits' => []]];
    $depth = 0;
    $function = false; // a function's body opens at the next "{"
    $close = function (array $scope) use (&$out): void {
        foreach ($scope['waits'] as [$line, $var]) {
            if (isset($scope['refs'][$var])) { $out[] = [$line, $var]; }
        }
    };
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        $id = is_array($t) ? $t[0] : $t;
        if ($id === T_FUNCTION) {
            $function = true;
        } elseif ($id === T_USE && $function) {
            // A closure's use list: its by-reference variables are the enclosing function's.
            for ($j = $i + 1, $ref = false; $j < $n && $tokens[$j] !== ')'; $j++) {
                if ($tokens[$j] === '&' || is_array($tokens[$j]) && $tokens[$j][0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) { $ref = true; } // the token of a & before a variable, since PHP 8.1
                elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    if ($ref) { $scopes[count($scopes) - 1]['refs'][$tokens[$j][1]] = true; }
                    $ref = false;
                }
            }
            $i = $j;
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            if ($function && $id === '{') {
                $scopes[] = ['depth' => $depth, 'refs' => [], 'waits' => []];
                $function = false;
            }
        } elseif ($id === '}') {
            if (count($scopes) > 1 && $scopes[count($scopes) - 1]['depth'] === $depth) {
                $close(array_pop($scopes));
            }
            $depth--;
        } elseif ($id === ';') {
            $function = false; // an abstract or interface method: no body
        } elseif (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(^|\\\\)App$/', $t[1]) === 1
            && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON && ($tokens[$i + 2][1] ?? null) === 'wait' && ($tokens[$i + 3] ?? null) === '(') {
            $j = $i + 4;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_FN) {
                continue;
            }
            // The arrow function's variables, to the parenthesis closing App::wait's call.
            for ($k = $j, $paren = 1; $k < $n && $paren > 0; $k++) {
                $p = $tokens[$k];
                if ($p === '(') { $paren++; } elseif ($p === ')') { $paren--; }
                elseif (is_array($p) && $p[0] === T_VARIABLE && $p[1] !== '$this') {
                    $scopes[count($scopes) - 1]['waits'][] = [$t[2], $p[1]];
                }
            }
        }
    }
    while (count($scopes) > 0) { $close(array_pop($scopes)); }
    return $out;
}

(function (): void {
    // What the check finds, and what it lets be.
    $bad = '<?php function f() { $done = false; g(function () use (&$done) { $done = true; }); App::wait(fn() => $done || microtime(true) > 1); }';
    same([[1, '$done']], arrowCaptureViolations($bad), 'arrow capture: a flag set by reference, read by an arrow function');
    $good = '<?php function f() { $done = false; g(function () use (&$done) { $done = true; }); App::wait(function () use (&$done) { return $done; }); }';
    same([], arrowCaptureViolations($good), 'arrow capture: a closure taking it by reference');
    $time = '<?php function f() { $at = microtime(true) + 1; App::wait(fn(): bool => microtime(true) >= $at); }';
    same([], arrowCaptureViolations($time), 'arrow capture: a value that never changes');
    $other = '<?php function f() { g(function () use (&$x) {}); } function h() { $x = 1; \\MyPHP\\App::wait(fn() => $x); }';
    same([], arrowCaptureViolations($other), 'arrow capture: another function\'s reference');

    // The code's.
    $root = dirname(__DIR__);
    $found = [];
    foreach (['src', 'tests'] as $dir) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            foreach (arrowCaptureViolations((string)file_get_contents($file->getPathname())) as [$line, $var]) {
                $found[] = substr($file->getPathname(), strlen($root) + 1) . ":$line: $var";
            }
        }
    }
    same([], $found, 'arrow capture: no App::wait arrow function reads a variable a closure sets by reference (AGENTS.md\'s rule 12)');
})();
