<?php
// Sets or checks the version MyPHP is released with, which the CLI
// (bin/myphp's VERSION) and the npm packages share, as mygo's
// scripts/version.ts:
//
//   php scripts/version.php 1.29.0           # set it everywhere
//   php scripts/version.php --check v1.29.0  # check that everything has it (the release workflow)
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/** The npm packages, by their directories: the runtime, the CLI and the official plugins' page sides, whose peer myphp-runtime is. */
const PACKAGES = ['packages/runtime', 'packages/cli', 'src/Plugins/Fetch/client', 'src/Plugins/Sqlite/client', 'src/Plugins/Watch/client', 'src/Plugins/WebSocket/client'];

/** Where each file declares the version: a pattern whose group 1 is it. @return array<string, string> */
function places(): array
{
    $out = ['bin/myphp' => "/^const VERSION = '([^']*)';$/m"];
    foreach (PACKAGES as $dir) {
        $out["$dir/package.json"] = '/^  "version": "([^"]*)",$/m';
    }
    return $out;
}

/** The plugins' peer range of myphp-runtime: group 1 is the version after the caret. */
const PEER = '/"myphp-runtime": "\^([^"]*)"/';

function read(string $path): string
{
    $s = file_get_contents(ROOT . "/$path");
    return $s !== false ? $s : throw new RuntimeException("cannot read $path");
}

/** @return list<string> what does not have $version, as "path: what it has" */
function mismatches(string $version): array
{
    $bad = [];
    foreach (places() as $path => $re) {
        $s = read($path);
        if (preg_match($re, $s, $m) !== 1) { $bad[] = "$path: no version"; continue; }
        if ($m[1] !== $version) { $bad[] = "$path: {$m[1]}"; }
        if (preg_match_all(PEER, $s, $peers) > 0) {
            foreach ($peers[1] as $p) { if ($p !== $version) { $bad[] = "$path: myphp-runtime ^$p"; } }
        }
    }
    return $bad;
}

function set(string $version): void
{
    foreach (places() as $path => $re) {
        $s = read($path);
        $n = 0;
        $s = preg_replace_callback($re, fn(array $m) => str_replace($m[1], $version, $m[0]), $s, 1, $n);
        if ($n !== 1) { throw new RuntimeException("$path: no version to set"); }
        $s = preg_replace(PEER, '"myphp-runtime": "^' . $version . '"', $s);
        file_put_contents(ROOT . "/$path", $s);
    }
}

$args = array_slice($argv, 1);
$check = $args !== [] && $args[0] === '--check';
if ($check) { array_shift($args); }
if (count($args) !== 1) {
    fwrite(STDERR, "usage: php scripts/version.php [--check] <version>\n");
    exit(2);
}
$version = ltrim($args[0], 'v');
if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
    fwrite(STDERR, "version.php: $args[0] is no version (1.29.0, v1.29.0 or 1.30.0-beta.1)\n");
    exit(2);
}
if ($check) {
    $bad = mismatches($version);
    if ($bad !== []) {
        fwrite(STDERR, "version.php: not at $version:\n  " . implode("\n  ", $bad) . "\nRun php scripts/version.php $version, commit, and tag again.\n");
        exit(1);
    }
    echo "everything is at $version\n";
} else {
    set($version);
    echo "set $version in bin/myphp and " . count(PACKAGES) . " npm packages\n";
}
