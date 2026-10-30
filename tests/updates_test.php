<?php
// Updates (mygo's updater.go and internal/update tests): versions, release
// notes, Ed25519 (RFC 8032's vectors), archives, binary patches and delta
// updates, feeds of GitHub releases, fibers, then a check and installs
// from a local server (php -S) into a bundle, from the archive and from a
// delta, with a damaged delta falling back to the archive.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, ErrUpdatesDisabled, Update, Updater};
use MyPHP\Native\Fibers;
use MyPHP\Native\Update\{Archive, Bsdiff, Delta, Ed25519, Feed, Format, NotPublished, SuffixArray};

/** A temporary directory, removed at the end. */
/**
 * Installs an update by $install into the bundle $target as an app does that
 * exits after it: staged, the bundle kept while the app runs (the Dock keeps
 * its tile), replaced by the helper once the app's process ended.
 */
function updStaged(\Closure $install, string $target): void
{
    $app = proc_open(['sleep', '0.3'], [], $pipes); // the app, whose exit the staged update waits for
    \MyPHP\Update::$appPid = proc_get_status($app)['pid'];
    $install();
    same('1.0.0', file_get_contents("$target/Contents/version.txt"), 'staged: the app keeps its bundle while it runs');
    proc_close($app);
    $dir = dirname($target);
    $done = fn(): bool => !in_array(@file_get_contents("$target/Contents/version.txt"), [false, '1.0.0'], true) && count(scandir($dir)) === 3; // the new bundle in place, where it was made removed
    for ($i = 0; $i < 150 && !$done(); $i++) { usleep(20000); clearstatcache(); }
}

function updDir(): string
{
    $d = sys_get_temp_dir() . '/myphp-upd-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($d);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($d)));
    return $d;
}

/** A gzipped tar of one file named $name holding $data, written by hand: no tar of the system writes such a name the same way (GNU's --transform, bsdtar's -s). */
function updTar(string $name, string $data): string
{
    $h = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
        . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 0) . "\0" . '        ' . '0' . str_repeat("\0", 100) . "ustar\0" . '00';
    $h = str_pad($h, 512, "\0");
    $sum = array_sum(array_map('ord', str_split($h))); // the checksum field counted as spaces
    $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
    return gzencode($h . str_pad($data, (int)ceil(strlen($data) / 512) * 512, "\0") . str_repeat("\0", 1024));
}

/** Whether $fn throws, and its message. */
function updThrows(\Closure $fn): string
{
    try { $fn(); } catch (\Throwable $e) { return $e->getMessage() === '' ? get_class($e) : $e->getMessage(); }
    return '';
}

(function () {
    // Versions, as semantic versioning compares them.
    foreach ([['1.2.3', '1.2.3', 0], ['1.2.3', '1.2.10', -1], ['1.10.0', '1.9.9', 1], ['v2.0.0', '1.99.0', 1], ['1.2', '1.2.0', -1],
        ['1.0.0-beta.1', '1.0.0', -1], ['1.0.0-beta.2', '1.0.0-beta.10', -1], ['1.0.0-alpha', '1.0.0-beta', -1], ['1.0.0+build.5', '1.0.0', 0], ['1.01', '1.1', 0]] as [$a, $b, $want]) {
        same([$want, -$want], [Format::compare($a, $b), Format::compare($b, $a)], "compare $a $b");
    }
    // Release notes from a changelog.
    $changelog = "# Changelog\n\nIntro.\n\n## 1.2.0 - 2026-09-19\n\nFaster.\n\n### Fixed\n- Drafts\n\n## 1.1.0\n- Tray\n";
    same(["Faster.\n\n### Fixed\n- Drafts", '- Tray', ''], [Format::releaseNotes($changelog, '1.2.0'), Format::releaseNotes($changelog, 'v1.1.0'), Format::releaseNotes($changelog, '1.3.0')], 'release notes');
    $keep = "# Changelog\r\n\r\n## [Unreleased]\r\n\r\n- Soon\r\n\r\n## [1.2.0] - 2026-09-19\r\n\r\n- Faster\r\n\r\n## [v1.1.0](https://example.com/compare/v1.0.0...v1.1.0)\r\n- Tray\r\n";
    foreach (['1.2.0' => '- Faster', '1.1.0' => '- Tray', 'Unreleased' => '- Soon', '1.0.0' => ''] as $v => $want) {
        same($want, Format::releaseNotes($keep, (string)$v), "Keep a Changelog: $v");
    }
})();

(function () {
    // Ed25519: RFC 8032's test vectors, then signatures of archives.
    foreach ([
        ['9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60', 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', '', 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b'],
        ['4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb', '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c', '72', '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00'],
        ['c5aa8df43f9f837bedb7442f31dcb7b166d38535076f094b85ce3a2e0b4458f7', 'fc51cd8e6218a1a38da47ed00230f0580816ed13ba3303ac5deb911548908025', 'af82', '6291d657deec24024827e69c3abe01a30ce548a284743a445e3680d7db5ac3ac18ff9b538d16f290ae67f760984dc6594a7c15e9716ed28dc027beceea1ec40a'],
    ] as $i => [$seed, $pub, $msg, $sig]) {
        [$sk, $pk] = Ed25519::keyPair(hex2bin($seed));
        same($pub, bin2hex($pk), "RFC 8032 test $i: the public key");
        same($sig, bin2hex(Ed25519::sign($sk, hex2bin($msg))), "RFC 8032 test $i: the signature");
        check(Ed25519::verify($pk, hex2bin($msg), hex2bin($sig)), "RFC 8032 test $i: verified");
        check(!Ed25519::verify($pk, hex2bin($msg) . 'x', hex2bin($sig)), "RFC 8032 test $i: another message refused");
    }
    [$sk, $pk] = Ed25519::keyPair(random_bytes(32));
    $sum = hash('sha256', 'archive', true);
    $sig = Format::sign(Format::privateKey(base64_encode($sk)), $sum);
    same('', updThrows(fn() => Format::verify(Format::publicKey(base64_encode($pk)), $sum, $sig)), 'an archive verified');
    check(updThrows(fn() => Format::verify($pk, hash('sha256', 'tampered', true), $sig)) !== '', 'a tampered archive refused');
    [, $other] = Ed25519::keyPair(random_bytes(32));
    check(updThrows(fn() => Format::verify($other, $sum, $sig)) !== '', 'another key refused');
    check(updThrows(fn() => Format::publicKey('not a key')) !== '', 'a key that is none');
    // S at or above L is refused, as Go's Verify does.
    $s = hex2bin('edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010');
    check(!Ed25519::verify($pk, $sum, substr(base64_decode($sig), 0, 32) . $s), 'a non-canonical S');
})();

(function () {
    // Archives: files with their modes, directories, links; and what an archive may not hold.
    $src = updDir();
    @mkdir("$src/App.app/Contents/MacOS", 0777, true);
    @mkdir("$src/App.app/Contents/Resources/data", 0777, true);
    file_put_contents("$src/App.app/Contents/MacOS/App", 'binary');
    chmod("$src/App.app/Contents/MacOS/App", 0755);
    file_put_contents("$src/App.app/Contents/Resources/data/a.txt", 'a');
    symlink('data/a.txt', "$src/App.app/Contents/Resources/link");
    $long = "$src/App.app/Contents/Resources/" . str_repeat('very-long-directory-name/', 8);
    mkdir($long, 0777, true);
    file_put_contents("$long/deep.txt", 'deep');
    $sum = Archive::write("$src/app.tar.gz", $src, ['App.app']);
    same(hash_file('sha256', "$src/app.tar.gz"), bin2hex($sum), 'the SHA-256 of what was written');
    $dst = updDir();
    Archive::extract("$src/app.tar.gz", $dst);
    same(['a', 'deep', 'data/a.txt'], [file_get_contents("$dst/App.app/Contents/Resources/data/a.txt"), file_get_contents(str_replace($src, $dst, $long) . 'deep.txt'), readlink("$dst/App.app/Contents/Resources/link")], 'extracted');
    check((fileperms("$dst/App.app/Contents/MacOS/App") & 0100) !== 0, 'the executable keeps its mode');
    exec('tar -tzf ' . escapeshellarg("$src/app.tar.gz") . ' 2>&1', $names, $code);
    check($code === 0 && in_array('App.app/Contents/Resources/link', $names, true), 'tar reads it');
    // What tar writes, extracted here.
    exec('cd ' . escapeshellarg($src) . ' && tar czf other.tar.gz App.app');
    $dst2 = updDir();
    Archive::extract("$src/other.tar.gz", $dst2);
    same('deep', file_get_contents(str_replace($src, $dst2, $long) . 'deep.txt'), 'tar\'s archive extracted');
    // Refused: paths out of the directory, links out of it.
    foreach (['../evil' => 'invalid path', '/etc/x' => 'invalid path'] as $bad => $why) {
        $evil = updDir();
        file_put_contents("$evil/bad.tar.gz", updTar($bad, "x\n"));
        check(str_contains(updThrows(fn() => Archive::extract("$evil/bad.tar.gz", updDir())), $why), "refused: $bad");
    }
    $evil = updDir();
    mkdir("$evil/x");
    symlink('../../outside', "$evil/x/link");
    exec('cd ' . escapeshellarg("$evil/x") . ' && tar czf ../bad.tar.gz link');
    check(str_contains(updThrows(fn() => Archive::extract("$evil/bad.tar.gz", updDir())), 'points outside'), 'a link out of the app');
})();

(function () {
    // Binary patches and the suffix array they search.
    foreach (['', 'a', 'banana', 'abracadabra', str_repeat('ab', 40), random_bytes(300)] as $s) {
        $want = range(0, strlen($s));
        usort($want, fn($a, $b) => strcmp(substr($s, $a), substr($s, $b)));
        same($want, SuffixArray::of($s), 'the suffix array of ' . json_encode(bin2hex(substr($s, 0, 8))));
    }
    $dir = updDir();
    $old = random_bytes(20000) . str_repeat('code ', 4000);
    $new = substr_replace($old, 'inserted', 5000, 0);
    $new = substr_replace($new, random_bytes(100), 12000, 40) . 'tail';
    foreach ([[$old, $new], ['', 'new file'], ['old', ''], [$old, $old]] as $i => [$a, $b]) {
        $patch = Bsdiff::diff($a, $b);
        file_put_contents("$dir/old", $a);
        file_put_contents("$dir/patch", $patch);
        $out = '';
        Bsdiff::patch(function (string $s) use (&$out) { $out .= $s; }, strlen($b), fopen("$dir/old", 'rb'), strlen($a), fopen("$dir/patch", 'rb'), 0, strlen($patch));
        check($out === $b, "patch $i makes the new file");
    }
    check(strlen(Bsdiff::diff($old, $new)) < 2000, 'a small change, a small patch');
})();

(function () {
    // Delta updates: copies, patches, new files and links; refused for another app.
    $dir = updDir();
    $old = "$dir/old/App.app";
    $new = "$dir/new/App.app";
    foreach ([$old, $new] as $app) {
        mkdir("$app/Contents/MacOS", 0777, true);
        mkdir("$app/Contents/Resources", 0777, true);
        file_put_contents("$app/Contents/Resources/same.txt", 'same');
    }
    $bin = random_bytes(50000);
    file_put_contents("$old/Contents/MacOS/App", $bin);
    file_put_contents("$new/Contents/MacOS/App", substr_replace($bin, 'v2', 100, 2));
    chmod("$new/Contents/MacOS/App", 0755);
    file_put_contents("$old/Contents/Resources/gone.txt", 'gone');
    file_put_contents("$new/Contents/Resources/copy.txt", 'same');
    file_put_contents("$new/Contents/Resources/new.txt", random_bytes(500));
    file_put_contents("$new/Contents/CodeResources", 'ticket');
    symlink('MacOS/App', "$new/Contents/link");
    $sum = Delta::write("$dir/d.delta", '1.0.0', '1.1.0', $old, $new, null);
    same(hash_file('sha256', "$dir/d.delta"), bin2hex($sum), 'the SHA-256 of the delta');
    check(filesize("$dir/d.delta") < 2000, 'a delta holds what changed: ' . filesize("$dir/d.delta") . ' bytes');
    Delta::apply("$dir/d.delta", filesize("$dir/d.delta"), '1.0.0', '1.1.0', $old, "$dir/made");
    exec('diff -r ' . escapeshellarg($new) . ' ' . escapeshellarg("$dir/made"), $o, $code);
    same(0, $code, 'the delta makes the new app: ' . implode("\n", $o));
    check((fileperms("$dir/made/Contents/MacOS/App") & 0100) !== 0, 'with its modes');
    check(str_contains(updThrows(fn() => Delta::apply("$dir/d.delta", filesize("$dir/d.delta"), '1.0.0', '1.2.0', $old, "$dir/x")), 'not 1.0.0 to 1.2.0'), 'another version refused');
    file_put_contents("$old/Contents/MacOS/App", 'changed since');
    check(str_contains(updThrows(fn() => Delta::apply("$dir/d.delta", filesize("$dir/d.delta"), '1.0.0', '1.1.0', $old, "$dir/y")), 'made Contents/MacOS/App wrong'), 'another app than the delta\'s');
    file_put_contents("$dir/bad.delta", "mygo delta 1\n\x05xx");
    check(updThrows(fn() => Delta::apply("$dir/bad.delta", filesize("$dir/bad.delta"), '1.0.0', '1.1.0', $old, "$dir/z")) !== '', 'a damaged delta');
})();

(function () {
    // Feeds of tagged GitHub releases.
    $feed = Feed::tagged('me/app', 'desktop-v', 'update-darwin-arm64.json');
    same('https://github.com/me/app/releases/download/desktop-v{version}/update-darwin-arm64.json', $feed, 'a tagged feed');
    $pages = [];
    $get = function (string $u) use (&$pages): string {
        foreach ($pages as $i => $page) {
            if ($u === 'https://api.github.com/repos/me/app/releases?per_page=100&page=' . ($i + 1)) { return '[' . implode(',', $page) . ']'; }
        }
        if ($u === 'https://api.github.com/repos/me/app/releases?per_page=100&page=' . (count($pages) + 1)) { return '[]'; }
        throw new \RuntimeException("GET $u");
    };
    $rel = fn(string $tag, bool $draft, bool $pre) => json_encode(['tag_name' => $tag, 'draft' => $draft, 'prerelease' => $pre, 'body' => 'not "tag_name": "desktop-v9.0.0"']);
    $pages = [[$rel('cli-v2.0.0', false, false), $rel('desktop-v1.3.0', true, false), $rel('desktop-v1.2.0-beta.1', false, true), $rel('desktop-v1.1.0', false, false), $rel('desktop-v1.0.0', false, false)]];
    same('https://github.com/me/app/releases/download/desktop-v1.1.0/update-darwin-arm64.json', Feed::resolve($feed, $get), 'the newest published release with the prefix');
    $full = [];
    for ($i = 0; $i < 100; $i++) { $full[] = $rel("cli-v1.0.$i", false, false); }
    $pages = [$full, [$rel('desktop-v0.9.0', false, false)]];
    check(str_contains(Feed::resolve($feed, $get), '/desktop-v0.9.0/'), 'a full page leads to the next');
    $pages = [[$rel('cli-v2.0.0', false, false)]];
    try { Feed::resolve($feed, $get); check(false, 'resolved'); } catch (NotPublished) { check(true, 'without a release, NotPublished'); }
    $plain = 'https://github.com/me/app/releases/latest/download/update-darwin-arm64.json';
    same($plain, Feed::resolve($plain, $get), 'other feeds are the manifest');
    check(updThrows(fn() => Feed::resolve('https://example.com/{version}/update.json', $get)) !== '', '{version} outside GitHub releases');

    // Prereleases (mygo's TestResolvePrereleaseFeed): the highest version that is not a draft.
    $latest = 'https://github.com/me/app/releases/latest/download/update-linux-amd64.json';
    $tagged = Feed::tagged('me/app', 'desktop-v', 'update-linux-amd64.json');
    $dl = 'https://github.com/me/app/releases/download/';
    foreach ([
        ['a prerelease newer than the latest release', $latest, [$rel('v1.3.0', true, false), $rel('v1.2.0-beta.2', false, true), $rel('v1.2.0-beta.10', false, true), $rel('v1.1.0', false, false)], 'v1.2.0-beta.10'],
        ['a release newer than the prereleases', $latest, [$rel('v1.2.0', false, false), $rel('v1.2.0-beta.2', false, true)], 'v1.2.0'],
        ['by version, not by date', $latest, [$rel('v1.1.1', false, false), $rel('v1.2.0-beta.1', false, true), $rel('nightly', false, true)], 'v1.2.0-beta.1'],
        ['tagged', $tagged, [$rel('cli-v3.0.0-beta.1', false, true), $rel('desktop-v2.0.0-rc.1', false, true), $rel('desktop-v1.0.0', false, false)], 'desktop-v2.0.0-rc.1'],
    ] as [$name, $f, $page, $want]) {
        $pages = [$page];
        same("{$dl}$want/update-linux-amd64.json", Feed::resolvePrerelease($f, $get), $name);
    }
    $pages = [[$rel('v1.0.0', true, false)]];
    try { Feed::resolvePrerelease($latest, $get); check(false, 'resolved'); } catch (NotPublished) { check(true, 'with drafts only, NotPublished'); }
    $plain = 'https://downloads.example.com/my-app/update-linux-amd64.json';
    same($plain, Feed::resolvePrerelease($plain, fn() => throw new \RuntimeException('read')), 'other feeds are the manifest, unread');
    check(updThrows(fn() => Feed::resolvePrerelease('https://example.com/{version}/update.json', $get)) !== '', 'prereleases: {version} outside GitHub releases');

    // The pages read (mygo's TestResolvePrereleaseFeedPages): up to the one with a stable release.
    $nightlies = function (int $n) use ($rel): array {
        $rs = [];
        for ($i = 0; $i < $n; $i++) { $rs[] = $rel('v2.0.0-nightly.' . ($n - $i), false, true); }
        return $rs;
    };
    foreach ([
        ['a full page with a stable release', [[...$nightlies(99), $rel('v1.9.0', false, false)], [$rel('v3.0.0-beta.1', false, true)]], 'v2.0.0-nightly.99', 1],
        ['prereleases only on the first page', [$nightlies(100), [$rel('v2.0.0-beta.1', false, true), $rel('v1.9.0', false, false)], [$rel('v1.0.0', false, false)]], 'v2.0.0-nightly.100', 2],
        ['a stable release after the prereleases', [$nightlies(100), [$rel('v2.0.0', false, false)]], 'v2.0.0', 2],
    ] as [$name, $ps, $want, $reads]) {
        $n = 0;
        $counted = function (string $u) use ($ps, &$n): string {
            $n++;
            foreach ($ps as $i => $p) {
                if ($u === 'https://api.github.com/repos/me/app/releases?per_page=100&page=' . ($i + 1)) { return '[' . implode(',', $p) . ']'; }
            }
            throw new \RuntimeException("GET $u");
        };
        same(["{$dl}$want/update-linux-amd64.json", $reads], [Feed::resolvePrerelease($latest, $counted), $n], "$name: the release and the pages read");
    }
    check(str_contains(updThrows(fn() => Feed::resolvePrerelease($latest, fn() => throw new \RuntimeException('GET: 403 Forbidden'))), '403'), 'an error reading the releases is the check\'s');

    // Updater::setPrereleases (mygo's TestUpdaterPrereleases).
    same(false, Updater::prereleases(), 'prereleases off at launch');
    Updater::setPrereleases(true);
    same(true, Updater::prereleases(), 'setPrereleases(true)');
    Updater::setPrereleases(false);
    same(false, Updater::prereleases(), 'setPrereleases(false)');
})();

(function () {
    // Fibers: App::go's work waits, and the rest goes on meanwhile.
    $log = [];
    App::go(function () use (&$log) { $log[] = 'a1'; Fibers::sleep(0.02); $log[] = 'a2'; });
    App::go(function () use (&$log) { $log[] = 'b1'; Fibers::sleep(0.01); $log[] = 'b2'; });
    $log[] = 'main';
    Fibers::drain();
    same(['a1', 'b1', 'main', 'b2', 'a2'], $log, 'fibers run until they wait, and go on in time');
})();

try {
    \MyPHP\Native\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: updates: {$e->getMessage()}\n";
    return;
}

(function () {
    // Check and install from a local server, into a bundle.
    $port = freePort();
    $www = updDir();
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $www], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) { usleep(20000); }
    $base = "http://127.0.0.1:$port";
    try {
        [$sk, $pk] = Ed25519::keyPair(random_bytes(32));
        $app = function (string $dir, string $version, string $bin): string {
            mkdir("$dir/My.app/Contents/MacOS", 0777, true);
            file_put_contents("$dir/My.app/Contents/MacOS/My", $bin);
            file_put_contents("$dir/My.app/Contents/version.txt", $version);
            return "$dir/My.app";
        };
        $bin = random_bytes(40000);
        $stage = updDir();
        $new = $app($stage, '1.1.0', substr_replace($bin, 'NEW', 10, 3));
        $sum = Archive::write("$www/my-1.1.0.tar.gz", $stage, ['My.app']);
        $manifest = ['version' => '1.1.0', 'notes' => '- Faster', 'date' => '2026-10-21T08:00:00Z', 'url' => "$base/my-1.1.0.tar.gz",
            'size' => filesize("$www/my-1.1.0.tar.gz"), 'signature' => Format::sign($sk, $sum)];
        $install = function (array $manifest, ?\Closure $progress = null) use ($www, $app, $bin, $pk) {
            file_put_contents("$www/update.json", json_encode($manifest));
            $installed = updDir();
            $target = $app($installed, '1.0.0', $bin);
            Updater::$target = $target;
            $u = Updater::checkFeed("http://127.0.0.1:" . parse_url($manifest['url'], PHP_URL_PORT) . '/update.json', base64_encode($pk), '1.0.0');
            check($u instanceof Update, 'an update found');
            updStaged(fn() => $u->install($progress), $target);
            return $target;
        };
        $seen = [];
        $target = $install($manifest, function (int $n, int $total) use (&$seen) { $seen[] = [$n, $total]; });
        same('1.1.0', file_get_contents("$target/Contents/version.txt"), 'the bundle replaced by the archive\'s');
        same([filesize("$www/my-1.1.0.tar.gz"), filesize("$www/my-1.1.0.tar.gz")], end($seen), 'the progress ends at the total');
        same(['My.app'], array_values(array_diff(scandir(dirname($target)), ['.', '..'])), 'nothing left beside the app');
        $u = Updater::checkFeed("$base/update.json", base64_encode($pk), '1.0.0');
        same(['1.1.0', '- Faster', '2026-10-21'], [$u->version, $u->notes, $u->date?->format('Y-m-d')], 'the version, its notes and date');
        same(null, Updater::checkFeed("$base/update.json", base64_encode($pk), '1.1.0'), 'up to date');
        same('myphp-updater', \MyPHP\Native\Update\Http::$userAgent, 'asked as the updater, not the app (a store answers 400 to a name past ASCII)');
        same(null, Updater::checkFeed("$base/update.json", base64_encode($pk), '1.2.0'), 'newer than the feed');
        // An app on a prerelease, whose user turned prereleases off, takes no older version, and the release of its version once there is one.
        same(null, Updater::checkFeed("$base/update.json", base64_encode($pk), '1.2.0-beta.1'), 'no older version than a prerelease');
        same('1.1.0', Updater::checkFeed("$base/update.json", base64_encode($pk), '1.1.0-rc.1')?->version, 'from 1.1.0-rc.1, 1.1.0');
        same('1.1.0', Updater::checkFeed("$base/update.json", base64_encode($pk), '1.0.0', null, true)?->version, 'feeds of updates.url the same with prereleases on');
        check(str_contains(updThrows(fn() => Updater::checkFeed("$base/missing.json", base64_encode($pk), '1.0.0')), '404'), 'a missing manifest');
        check(str_contains(updThrows(fn() => Updater::checkFeed('http://example.com/update.json', base64_encode($pk), '1.0.0')), 'updates need HTTPS'), 'plain HTTP refused');

        // A signature of another key.
        [$other] = Ed25519::keyPair(random_bytes(32));
        check(str_contains(updThrows(fn() => $install(['signature' => Format::sign($other, $sum)] + $manifest)), 'not signed with the app\'s key'), 'another key\'s signature');
        check(str_contains(updThrows(fn() => $install(['size' => $manifest['size'] + 1] + $manifest)), 'downloaded'), 'a wrong size');

        // A delta, which downloads less; a damaged one falls back to the archive.
        $old = updDir();
        $app($old, '1.0.0', $bin);
        $dsum = Delta::write("$www/my-1.0.0-to-1.1.0.delta", '1.0.0', '1.1.0', "$old/My.app", $new, null);
        $withDelta = $manifest + ['deltas' => [['from' => '1.0.0', 'url' => "$base/my-1.0.0-to-1.1.0.delta", 'size' => filesize("$www/my-1.0.0-to-1.1.0.delta"), 'signature' => Format::sign($sk, $dsum)]]];
        $seen = [];
        $target = $install($withDelta, function (int $n, int $total) use (&$seen) { $seen[] = $total; });
        same(['1.1.0', [filesize("$www/my-1.0.0-to-1.1.0.delta")]], [file_get_contents("$target/Contents/version.txt"), array_values(array_unique($seen))], 'installed from the delta alone');
        $bad = $withDelta;
        $bad['deltas'][0]['signature'] = Format::sign($other, $dsum);
        $seen = [];
        $target = $install($bad, function (int $n, int $total) use (&$seen) { $seen[] = $total; });
        same(['1.1.0', filesize("$www/my-1.1.0.tar.gz")], [file_get_contents("$target/Contents/version.txt"), end($seen)], 'a delta that fails falls back to the archive');

        // What myphp build publishes: v1.0.0, then v1.1.0 with a delta from it, which the app installs.
        $project = updDir();
        $keys = updDir();
        $said = \MyPHP\Native\Update\Publish::keygen($keys, false);
        check(str_contains($said, 'publicKey') && str_contains(updThrows(fn() => \MyPHP\Native\Update\Publish::keygen($keys, false)), 'already holds keys'), 'keygen, once');
        $pubText = trim(file_get_contents("$keys/myphp-update.pub"));
        file_put_contents("$project/CHANGELOG.md", "# Changelog\n\n## 1.1.0\n- Faster\n\n## 1.0.0\n- First\n");
        $publish = function (string $version, string $bin) use ($project, $keys, $pubText, $www, $base, $app): array {
            $config = ['name' => 'My App', 'version' => $version, 'updates' => ['publicKey' => $pubText, 'url' => 'https://downloads.example.com/my', 'privateKey' => "$keys/myphp-update.key"]];
            $p = new \MyPHP\Native\Update\Publish($project, $config);
            \Closure::bind(fn() => $this->url = $base, $p, \MyPHP\Native\Update\Publish::class)(); // the local server, over plain HTTP
            $out = updDir();
            $app($out, $version, $bin);
            $log = [];
            $files = $p->write($out, 'My.app', Format::target(), function (string $l) use (&$log) { $log[] = $l; });
            foreach ($files as $f) { copy($f, "$www/" . basename($f)); }
            return [array_map('basename', $files), $log, $p];
        };
        [$files, $log, $p] = $publish('1.0.0', $bin);
        same(['my-app-1.0.0-' . Format::target() . '.tar.gz', Format::manifestName(Format::target())], $files, 'the first version: its archive and manifest');
        same(['no delta updates for ' . Format::target() . ': no version is published yet'], $log, 'no delta without a version published');
        same(['MyPHPUpdateFeed' => "https://downloads.example.com/my/" . Format::manifestName(Format::target()), 'MyPHPUpdateKey' => $pubText], (new \MyPHP\Native\Update\Publish($project, ['name' => 'x', 'updates' => ['publicKey' => $pubText, 'url' => 'https://downloads.example.com/my']]))->plist(Format::target()), 'the feed and key the bundle gets');
        [$files, $log] = $publish('1.1.0', substr_replace($bin, 'V11', 5, 3));
        same(3, count($files), 'the second version: an archive, a delta and the manifest');
        check(str_contains($log[0] ?? '', 'made the delta update from 1.0.0'), 'the delta made: ' . json_encode($log));
        $m = json_decode(file_get_contents("$www/" . Format::manifestName(Format::target())), true);
        same(['1.1.0', '- Faster', '1.0.0', '1.0.0'], [$m['version'], $m['notes'], $m['deltas'][0]['from'], $m['previous'][0]['version']], 'the manifest');
        $installed = updDir();
        Updater::$target = $app($installed, '1.0.0', $bin);
        $u = Updater::checkFeed("$base/" . Format::manifestName(Format::target()), $pubText, '1.0.0');
        $sizes = [];
        updStaged(function () use ($u, &$sizes) { $u->install(function (int $n, int $total) use (&$sizes) { $sizes[$total] = true; }); }, Updater::$target);
        same(['1.1.0', [$m['deltas'][0]['size']]], [file_get_contents(Updater::$target . '/Contents/version.txt'), array_keys($sizes)], 'installed from the delta myphp build made');
        foreach ([['github' => 'nope'], ['url' => 'http://insecure'], ['deltas' => -1], ['publicKey' => 'bad'], ['s3' => ['prefix' => 'x']], ['s3' => ['bucket' => 'b', 'endpoint' => 'https://r2.example/path']]] as $bad) {
            check(updThrows(fn() => new \MyPHP\Native\Update\Publish($project, ['name' => 'x', 'updates' => $bad + ['publicKey' => $pubText, 'url' => 'https://x.example']])) !== '', 'refused: ' . json_encode($bad));
        }
        [, $wrongPub] = Ed25519::keyPair(random_bytes(32));
        check(str_contains(updThrows(fn() => (new \MyPHP\Native\Update\Publish($project, ['name' => 'x', 'updates' => ['publicKey' => base64_encode($wrongPub), 'url' => 'https://x.example', 'privateKey' => "$keys/myphp-update.key"]]))->signingKey()), 'does not match'), 'a key of another pair');

        // Canceled.
        check(updThrows(fn() => Updater::checkFeed("$base/update.json", base64_encode($pk), '1.0.0', fn() => true)) !== '', 'a canceled check');
        // Builds without updates.
        Updater::$target = null;
        check(!Updater::enabled(), 'not enabled without a bundle and a feed');
        try { Updater::check(); check(false, 'checked'); } catch (ErrUpdatesDisabled) { check(true, 'ErrUpdatesDisabled'); }
    } finally {
        Updater::$target = null;
        proc_terminate($server);
        proc_close($server);
    }
})();
