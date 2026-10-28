<?php
// The messages sent by ObjC::msg and msgSuper, on any system: each of a
// literal signature and selector takes as many arguments as the selector
// has colons, of letters ObjC maps, and is given them; macOS's FFI would
// say so only when it runs the line (a test's own on macOS alone).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\ObjC;

$letters = (new \ReflectionClassConstant(ObjC::class, 'MAP'))->getValue();
$root = dirname(__DIR__);
$files = [];
foreach (['src', 'tests', 'examples', 'packages', 'bin', 'scripts'] as $dir) {
    if (!is_dir("$root/$dir")) { continue; }
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with($f->getFilename(), '.php') || ($dir === 'bin' && !str_contains($f->getFilename(), '.'))) { $files[] = $f->getPathname(); }
    }
}

/** The arguments of the call whose "(" is at $i in $tokens, each its tokens (whitespace and comments left out). @return list<list<mixed>> */
$arguments = function (array $tokens, int $i): array {
    $args = [[]]; $depth = 0;
    for ($n = count($tokens), $i++; $i < $n; $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        if (in_array($text, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
        if (in_array($text, [')', ']', '}'], true)) {
            if ($depth === 0) { return $args; }
            $depth--;
        }
        if ($text === ',' && $depth === 0) { $args[] = []; continue; }
        $args[count($args) - 1][] = $t;
    }
    return $args;
};
/** The string an argument is when it is one literal, else null. */
$literal = fn(array $arg): ?string => count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING ? stripcslashes(substr($arg[0][1], 1, -1)) : null;

$sent = 0; $wrong = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (!str_contains($src, 'ObjC::msg')) { continue; }
    $tokens = token_get_all($src);
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['msg', 'msgSuper'], true)) { continue; }
        $k = $i - 1;
        if (!is_array($tokens[$k] ?? null) || $tokens[$k][0] !== T_DOUBLE_COLON) { continue; }
        $class = $tokens[$k - 1] ?? null;
        if (!is_array($class) || !in_array($class[1], ['ObjC', 'self'], true) || ($class[1] === 'self' && !str_ends_with($file, '/ObjC.php'))) { continue; }
        for ($p = $i + 1; is_array($tokens[$p] ?? null) && $tokens[$p][0] === T_WHITESPACE; $p++) {}
        if (($tokens[$p] ?? null) !== '(') { continue; }
        $args = $arguments($tokens, $p);
        $selAt = $t[1] === 'msg' ? 2 : 3;
        $sig = $literal($args[0] ?? []);
        $sel = $literal($args[$selAt] ?? []);
        if ($sig === null || $sel === null || array_filter($args, fn($a) => is_array($a[0] ?? null) && $a[0][0] === T_ELLIPSIS)) { continue; } // made or spread: what it is shows when it runs
        $sent++;
        $where = substr($file, strlen($root) + 1) . ':' . $t[2] . " $sel";
        $parts = explode(':', $sig);
        if (count($parts) !== 2 || strlen($parts[0]) !== 1) { $wrong[] = "$where: signature \"$sig\" is not one letter, a colon and the arguments'"; continue; }
        foreach (str_split($parts[0] . $parts[1]) as $ch) {
            if ($ch !== '' && !isset($letters[$ch])) { $wrong[] = "$where: \"$ch\" of \"$sig\" is no letter ObjC maps"; }
        }
        $want = substr_count($sel, ':');
        if (strlen($parts[1]) !== $want) { $wrong[] = "$where: \"$sig\" takes " . strlen($parts[1]) . " arguments, the selector $want"; }
        $given = count($args) - $selAt - 1;
        if ($given !== $want) { $wrong[] = "$where: given $given arguments, the selector takes $want"; }
    }
}
check($sent > 1000, "the messages of literal signatures and selectors found: $sent");
same([], $wrong, 'each takes as many arguments as its selector has colons, of letters ObjC maps, and is given them');
