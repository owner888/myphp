<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use Closure;
use MyPHP\{App, Update};
use RuntimeException;

/**
 * Signed updates as `myphp build` makes them (mygo's cmd/mygo updates.go):
 * an archive of the app signed with the private key, delta updates from
 * the versions before, and update-<target>.json, which apps built with the
 * public key check. myphp.json's "updates":
 *
 *     publicKey   the contents of myphp-update.pub (myphp keygen)
 *     github      "owner/name", whose releases hold the updates, or
 *     url         the https URL of a directory that serves them
 *     tagPrefix   of the releases' tags, "v" by default; another prefix finds
 *                 the newest release tagged with it through the GitHub API
 *     privateKey  the path of myphp-update.key; $MYPHP_UPDATER_PRIVATE_KEY,
 *                 holding the key itself, takes precedence
 *     changelog   whose "## <version>" section is the release notes
 *                 (CHANGELOG.md when it exists)
 *     deltas      how many earlier versions get a delta update (3; 0 for none)
 *
 * @internal
 */
final class Publish
{
    private string $publicKey;
    private string $github;
    private string $url;
    private string $tagPrefix;
    private int $deltas;
    /** @var array<string, mixed> */
    private array $u;

    /** @param array<string, mixed> $config myphp.json, which has "updates" */
    public function __construct(private readonly string $dir, private readonly array $config)
    {
        $u = $config['updates'];
        if (!is_array($u)) {
            throw new RuntimeException('updates must be an object');
        }
        $this->u = $u;
        $this->publicKey = trim((string)($u['publicKey'] ?? ''));
        if ($this->publicKey === '') {
            throw new RuntimeException('updates needs the publicKey of myphp keygen');
        }
        try {
            Format::publicKey($this->publicKey);
        } catch (RuntimeException $e) {
            throw new RuntimeException("updates.publicKey: {$e->getMessage()}");
        }
        $this->github = (string)($u['github'] ?? '');
        $this->url = (string)($u['url'] ?? '');
        if (($this->github === '') === ($this->url === '')) {
            throw new RuntimeException('updates needs either github (owner/name) or url');
        }
        if ($this->github !== '' && substr_count($this->github, '/') !== 1) {
            throw new RuntimeException("updates.github \"{$this->github}\" is not owner/name");
        }
        if ($this->url !== '' && preg_match('~^https://[^/]+~', $this->url) !== 1) {
            throw new RuntimeException("updates.url \"{$this->url}\" is not an https URL");
        }
        if (isset($u['s3'])) {
            throw new RuntimeException('updates.s3 is not supported: upload the files to the bucket yourself');
        }
        $deltas = $u['deltas'] ?? 3;
        if (!is_int($deltas) || $deltas < 0) {
            throw new RuntimeException('updates.deltas is not a number of versions');
        }
        $this->deltas = $deltas;
        $prefix = (string)($u['tagPrefix'] ?? '');
        $this->tagPrefix = $prefix === '' ? 'v' : $prefix;
    }

    private function version(): string
    {
        return (string)($this->config['version'] ?? '0.1.0');
    }

    /** The URL of a published update file: manifests from the latest release (or the newest tagged one), archives from the release of this version. */
    public function file(string $name, bool $latest): string
    {
        if ($this->github === '') {
            return rtrim($this->url, '/') . '/' . rawurlencode($name);
        }
        if ($latest && $this->tagPrefix !== 'v') {
            return Feed::tagged($this->github, $this->tagPrefix, $name);
        }
        if ($latest) {
            return "https://github.com/{$this->github}/releases/latest/download/" . rawurlencode($name);
        }
        return "https://github.com/{$this->github}/releases/download/" . rawurlencode($this->tagPrefix . $this->version()) . '/' . rawurlencode($name);
    }

    /** The Info.plist keys that point the app at its manifest (mygo's -ldflags of updateFlags). @return array<string, string> */
    public function plist(): array
    {
        return ['MyPHPUpdateFeed' => $this->file(Format::manifestName(Format::target()), true), 'MyPHPUpdateKey' => $this->publicKey];
    }

    /** The private key that signs updates, or null when none is available. */
    public function signingKey(): ?string
    {
        $text = (string)getenv('MYPHP_UPDATER_PRIVATE_KEY');
        $path = (string)($this->u['privateKey'] ?? '');
        if ($text === '' && $path !== '') {
            if (str_starts_with($path, '~/')) {
                $path = getenv('HOME') . substr($path, 1);
            } elseif (!str_starts_with($path, '/')) {
                $path = "{$this->dir}/$path";
            }
            $read = @file_get_contents($path);
            if ($read === false) {
                throw new RuntimeException("cannot read the update key $path");
            }
            $text = $read;
        }
        if ($text === '') {
            return null;
        }
        try {
            $key = Format::privateKey($text);
        } catch (RuntimeException $e) {
            throw new RuntimeException("updates: {$e->getMessage()}");
        }
        if (base64_encode(substr($key, 32)) !== $this->publicKey) {
            throw new RuntimeException('updates: the update signing key does not match updates.publicKey in myphp.json');
        }
        return $key;
    }

    /** The release notes of this version, from the changelog. */
    private function notes(): string
    {
        $name = (string)($this->u['changelog'] ?? '');
        $file = "{$this->dir}/" . ($name === '' ? 'CHANGELOG.md' : $name);
        if ($name === '' && !is_file($file)) {
            return '';
        }
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new RuntimeException("cannot read $file");
        }
        $notes = Format::releaseNotes($text, $this->version());
        if ($notes === '') {
            throw new RuntimeException(basename($file) . " has no notes for {$this->version()}: add a \"## {$this->version()}\" section");
        }
        return $notes;
    }

    /**
     * With the signing key, writes next to the app $entry in $out its
     * signed archive, delta updates from the versions before, and the
     * manifest, and returns the files written; none without the key, which
     * $log says (mygo's writeArchive).
     *
     * @param Closure(string): void $log
     * @return list<string>
     */
    public function write(string $out, string $entry, Closure $log): array
    {
        $key = $this->signingKey();
        if ($key === null) {
            $log('not signing an update: set MYPHP_UPDATER_PRIVATE_KEY or updates.privateKey');
            return [];
        }
        $notes = $this->notes();
        $target = Format::target();
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$this->config['name'])), '-');
        $version = $this->version();
        $name = "$slug-$version-$target.tar.gz";
        $sum = Archive::write("$out/$name", $out, [$entry]);
        $m = ['version' => $version] + ($notes !== '' ? ['notes' => $notes] : []) + [
            'date' => gmdate('Y-m-d\TH:i:s\Z'),
            'url' => $this->file($name, false),
            'size' => (int)filesize("$out/$name"),
            'signature' => Format::sign($key, $sum),
        ];
        $files = ["$out/$name"];
        if ($this->deltas > 0) {
            array_push($files, ...$this->writeDeltas($key, $out, $entry, $target, $slug, $m, $log));
        }
        $manifest = "$out/" . Format::manifestName($target);
        file_put_contents($manifest, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $files[] = $manifest;
        return $files;
    }

    /**
     * Delta updates to the app $entry in $out from the versions before it,
     * as Sparkle makes them: the version of the published manifest and those
     * it lists as previous, whose archives it downloads and checks (mygo's
     * writeDeltas). They go in $m, with the archives the next version makes
     * its deltas from. A version without one is skipped with a message.
     *
     * @param array<string, mixed> $m
     * @param Closure(string): void $log
     * @return list<string>
     */
    private function writeDeltas(string $key, string $out, string $entry, string $target, string $slug, array &$m, Closure $log): array
    {
        Http::$userAgent = 'myphp/' . App::VERSION;
        $token = getenv('GH_TOKEN');
        Http::$githubToken = is_string($token) && $token !== '' ? $token : (string)getenv('GITHUB_TOKEN');
        $version = $this->version();
        try {
            $url = Feed::resolve($this->file(Format::manifestName($target), true), fn(string $x): string => Http::get($x, 64 << 20));
            $published = Format::manifest(Http::get($url, Format::MAX_MANIFEST_SIZE));
        } catch (NotPublished) {
            $log("no delta updates for $target: no version is published yet");
            return [];
        } catch (RuntimeException $e) {
            $log($e->getCode() === 404 ? "no delta updates for $target: no version is published yet" : "no delta updates for $target: {$e->getMessage()}");
            return [];
        }
        $bases = [];
        foreach ([$published, ...($published['previous'] ?? [])] as $a) {
            $a = ['version' => (string)($a['version'] ?? ''), 'url' => (string)($a['url'] ?? ''), 'size' => (int)($a['size'] ?? 0), 'signature' => (string)($a['signature'] ?? '')];
            if (Format::compare($a['version'], $version) < 0 && !in_array($a['version'], array_column($bases, 'version'), true)) {
                $bases[] = $a;
            }
        }
        if ($bases === []) {
            $log("no delta updates for $target: the published version is {$published['version']}");
            return [];
        }
        $bases = array_slice($bases, 0, $this->deltas);
        $previous = array_slice($bases, 0, $this->deltas - 1);
        if ($previous !== []) {
            $m['previous'] = $previous;
        }
        $files = [];
        foreach ($bases as $base) {
            $name = "$slug-{$base['version']}-to-$version-$target.delta";
            $work = sys_get_temp_dir() . '/myphp-delta-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($work, 0o700, true);
            try {
                $sum = Http::download($base['url'], $base['size'], "$work/archive.tar.gz", null);
                try {
                    Format::verify(substr($key, 32), $sum, $base['signature']);
                } catch (RuntimeException) {
                    throw new RuntimeException("{$base['url']} is not signed with the key of updates.publicKey");
                }
                mkdir("$work/app");
                Archive::extract("$work/archive.tar.gz", "$work/app");
                $apps = array_values(array_diff(scandir("$work/app") ?: [], ['.', '..']));
                if (count($apps) !== 1 || !str_ends_with($apps[0], '.app')) {
                    throw new RuntimeException('its archive holds no app bundle');
                }
                $t = microtime(true);
                $dsum = Delta::write("$out/$name", $base['version'], $version, "$work/app/{$apps[0]}", "$out/$entry", null);
                $size = (int)filesize("$out/$name");
                if ($size >= $m['size']) {
                    unlink("$out/$name");
                    $log("no delta update from {$base['version']} for $target: it is not smaller than the archive");
                    continue;
                }
                $m['deltas'][] = ['from' => $base['version'], 'url' => $this->file($name, false), 'size' => $size, 'signature' => Format::sign($key, $dsum)];
                $files[] = "$out/$name";
                $log(sprintf('made the delta update from %s for %s: %s, instead of %s (%.1fs)', $base['version'], $target, self::size($size), self::size($m['size']), microtime(true) - $t));
            } catch (RuntimeException $e) {
                @unlink("$out/$name");
                $log("no delta update from {$base['version']} for $target: {$e->getMessage()}");
            } finally {
                Update::removeAll($work);
            }
        }
        return $files;
    }

    private static function size(int $n): string
    {
        return $n >= 1 << 20 ? sprintf('%.1f MB', $n / (1 << 20)) : sprintf('%.0f KB', $n / 1024);
    }

    /** Writes the key pair that signs updates to $dir, and returns what to tell the developer (mygo's runKeygen). */
    public static function keygen(string $dir, bool $force): string
    {
        $secret = "$dir/myphp-update.key";
        $public = "$dir/myphp-update.pub";
        if (!$force && (file_exists($secret) || file_exists($public))) {
            throw new RuntimeException("$dir already holds keys; pass --force to replace them");
        }
        [$priv, $pub] = Ed25519::keyPair(random_bytes(32));
        if (!is_dir($dir) && !mkdir($dir, 0o700, true)) {
            throw new RuntimeException("cannot make $dir");
        }
        if (file_put_contents($secret, base64_encode($priv) . "\n") === false) {
            throw new RuntimeException("cannot write $secret");
        }
        chmod($secret, 0o600);
        file_put_contents($public, base64_encode($pub) . "\n");
        $text = base64_encode($pub);
        return "myphp: wrote $secret (secret) and $public\n"
            . "Add to myphp.json:\n\n  \"updates\": {\n    \"publicKey\": \"$text\",\n    \"github\": \"owner/name\"\n  }\n\n"
            . "Keep the secret key out of the repository, in a password manager and as the MYPHP_UPDATER_PRIVATE_KEY secret of CI:\n"
            . "installed apps accept only updates signed with it, so losing it strands them.\n";
    }
}
