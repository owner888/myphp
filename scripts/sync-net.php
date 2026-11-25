<?php
// Copies the pure-PHP network stack MyPHP carries in src/Net from the pig checkout it is developed in
// (github.com/owner888/pig: packages/async, the event loop over stream_select and fibers, and packages/ai/src/Http,
// HTTP/1.1 and HTTP/2 over it), its namespaces made MyPHP's:
//
//   php scripts/sync-net.php            # copy from $PIG_HOME, else ../pig
//   php scripts/sync-net.php --check    # say what differs, and exit 1 when something does (tests/net_sync_test.php)
//
// The copy is committed: MyPHP is installed as a checkout, which carries what it runs. It is never edited here; a
// change is made in pig, where its tests are, and copied again.
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/** pig's directory => [the directory under src/Net, the namespace there, pig's namespace, the files left out]. */
const PACKAGES = [
    'packages/async/src' => ['Async', 'MyPHP\\Net\\Async', 'Pig\\Async', []],
    // The parser of server-sent events needs pig's JSON, and nothing here reads them yet; the retries are pig's providers'.
    'packages/ai/src/Http' => ['Http', 'MyPHP\\Net\\Http', 'Pig\\Ai\\Http', ['SseParser.php', 'SseEvent.php', 'Retrying.php']],
];

/** What src/Net holds, as pig has it now: path under src/Net => contents. @return array<string, string> */
function wanted(string $pig): array
{
    $out = [];
    foreach (PACKAGES as $from => [$to, , , $skip]) {
        $dir = "$pig/$from";
        is_dir($dir) || throw new RuntimeException("$dir does not exist: set PIG_HOME to a pig checkout");
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($f->getExtension() !== 'php' || in_array($rel, $skip, true)) { continue; }
            $text = (string)file_get_contents($f->getPathname());
            foreach (PACKAGES as [, $ours, $theirs]) {
                $text = str_replace($theirs, $ours, $text);
            }
            $out["$to/$rel"] = $text;
        }
    }
    ksort($out);
    return $out;
}

/** What src/Net holds. @return array<string, string> */
function have(): array
{
    $out = [];
    $dir = ROOT . '/src/Net';
    foreach (PACKAGES as [$to]) {
        if (!is_dir("$dir/$to")) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/$to", FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[substr($f->getPathname(), strlen($dir) + 1)] = (string)file_get_contents($f->getPathname());
        }
    }
    ksort($out);
    return $out;
}

$pig = getenv('PIG_HOME') ?: ROOT . '/../pig';
$wanted = wanted($pig);
$have = have();
$differ = array_merge(
    array_map(fn(string $p) => "missing: $p", array_keys(array_diff_key($wanted, $have))),
    array_map(fn(string $p) => "not pig's: $p", array_keys(array_diff_key($have, $wanted))),
    array_map(fn(string $p) => "differs: $p", array_keys(array_filter($wanted, fn(string $text, string $p) => isset($have[$p]) && $have[$p] !== $text, ARRAY_FILTER_USE_BOTH))),
);
if (in_array('--check', $argv, true)) {
    echo $differ === [] ? 'src/Net is pig\'s, ' . count($wanted) . " files\n" : implode("\n", $differ) . "\n";
    exit($differ === [] ? 0 : 1);
}
foreach (array_keys(array_diff_key($have, $wanted)) as $p) {
    unlink(ROOT . "/src/Net/$p");
}
foreach ($wanted as $p => $text) {
    $path = ROOT . "/src/Net/$p";
    is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
    file_put_contents($path, $text);
}
echo 'copied ' . count($wanted) . ' files, ' . count($differ) . " changed\n";
