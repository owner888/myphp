<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use Closure;
use RuntimeException;

/**
 * `myphp build --upload` (mygo's publish.go): the installers and update
 * files of a build, uploaded where updates point to: the bucket of
 * updates.s3, else the GitHub release of this version, a draft, with the
 * gh CLI. The manifests go last, so that they never point at a file that
 * is not there.
 *
 * @internal
 */
final class Upload
{
    private const TYPES = [
        'dmg' => 'application/x-apple-diskimage',
        'gz' => 'application/gzip',
        'json' => 'application/json',
        'exe' => 'application/vnd.microsoft.portable-executable',
        'zip' => 'application/zip',
        'ps1' => 'text/plain; charset=utf-8',
    ]; // mygo's .deb and install.sh come with Linux's updates

    /** Checks, before a build, that --upload has somewhere to upload to. @param array<string, mixed> $c myphp.json */
    public static function check(array $c, string $configName): void
    {
        $u = $c['updates'] ?? null;
        if (is_array($u) && isset($u['s3'])) {
            S3::client($u['s3']);
            return;
        }
        if (!is_array($u) || ($u['github'] ?? '') === '') {
            throw new RuntimeException("--upload needs updates.github or updates.s3 in $configName");
        }
    }

    /**
     * The artifacts to publish: the installers and update files, and apart
     * the update manifests. The bundle ships in the disk image and the update.
     *
     * @param list<string> $artifacts
     * @return array{list<string>, list<string>}
     */
    public static function uploads(array $artifacts): array
    {
        $files = []; $manifests = [];
        foreach ($artifacts as $a) {
            if (!is_file($a)) {
                continue;
            }
            $name = basename($a);
            if (str_starts_with($name, 'update-') && str_ends_with($name, '.json')) {
                $manifests[] = $a;
            } elseif (str_ends_with($name, '.dmg') || str_ends_with($name, '.tar.gz') || str_ends_with($name, '.delta') || str_contains($name, '-setup-') && str_ends_with($name, '.exe')
                || str_ends_with($name, '.zip') || $name === InstallPs1::NAME || $name === Checksums::NAME) {
                $files[] = $a; // the Windows installers, the zips of the apps, install.ps1 and SHA256SUMS too
            }
        }
        if ($files === [] && $manifests === []) {
            throw new RuntimeException('nothing to upload');
        }
        return [$files, $manifests];
    }

    /**
     * Uploads the artifacts of a build where updates point to.
     *
     * @param array<string, mixed> $c myphp.json
     * @param list<string> $artifacts
     * @param Closure(string): void $log
     */
    public static function publish(array $c, string $version, array $artifacts, string $notes, string $userAgent, string $configName, Closure $log): void
    {
        $u = $c['updates'];
        if (isset($u['s3'])) {
            $bucket = S3::client($u['s3']);
            [$files, $manifests] = self::uploads($artifacts);
            self::mergeChecksums($files, function (string $dir) use ($u): ?string {
                try {
                    return \MyPHP\Native\Update\Http::get(rtrim($u['url'], '/') . '/' . Checksums::NAME, 4 << 20);
                } catch (RuntimeException) {
                    return null; // none published yet
                }
            });
            foreach ([...$files, ...$manifests] as $f) {
                $name = basename($f);
                $bucket->put($bucket->key($name), $f, self::headers($name), $userAgent);
                $log("Uploaded $name");
            }
            $log("Uploaded to s3://{$bucket->bucket}/{$bucket->prefix}, which {$u['url']} serves");
            return;
        }
        self::github($u, $version, $artifacts, $notes, $configName, $log);
    }

    /**
     * Uploads to the GitHub release of this version, a draft created if
     * needed, with the gh CLI. Installed apps see the update once the
     * release is published.
     *
     * @param array<string, mixed> $u updates
     * @param list<string> $artifacts
     * @param Closure(string): void $log
     */
    private static function github(array $u, string $version, array $artifacts, string $notes, string $configName, Closure $log): void
    {
        if (($u['github'] ?? '') === '') {
            throw new RuntimeException("--upload needs updates.github in $configName");
        }
        $gh = Cmd::look('gh');
        if ($gh === '') {
            throw new RuntimeException('--upload needs the GitHub CLI (https://cli.github.com), signed in with gh auth login or GH_TOKEN');
        }
        $prefix = (string)($u['tagPrefix'] ?? 'v');
        $prefix = $prefix === '' ? 'v' : $prefix;
        $repo = $u['github']; $tag = $prefix . $version;
        [$files, $manifests] = self::uploads($artifacts);
        // With a tag prefix, the repository's latest release is another's, which the app's releases leave alone.
        $latest = $prefix !== 'v' ? ' --latest=false' : '';
        if (Cmd::output([$gh, 'release', 'view', $tag, '--repo', $repo])[0] !== 0) {
            $args = [$gh, 'release', 'create', $tag, '--repo', $repo, '--draft', '--title', $tag, '--notes', $notes];
            if ($latest !== '') {
                $args[] = '--latest=false';
            }
            if (str_contains(explode('+', $version, 2)[0], '-')) {
                $args[] = '--prerelease'; // as 1.3.0-beta.1: only apps that take prereleases (Updater::setPrereleases) update to it
            }
            [$code, $out] = Cmd::output($args);
            if ($code !== 0) {
                throw new RuntimeException("gh release create: exit status $code\n$out");
            }
            $log("Created the draft release $tag of $repo");
        }
        self::mergeChecksums($files, function (string $dir) use ($gh, $tag, $repo): ?string {
            [$code] = Cmd::output([$gh, 'release', 'download', $tag, '--repo', $repo, '--pattern', Checksums::NAME, '--dir', $dir, '--clobber']);
            return $code === 0 ? (string)@file_get_contents("$dir/" . Checksums::NAME) : null;
        });
        foreach ([$files, $manifests] as $batch) {
            if ($batch === []) {
                continue;
            }
            [$code, $out] = Cmd::output([$gh, 'release', 'upload', $tag, '--repo', $repo, '--clobber', ...$batch]);
            if ($code !== 0) {
                throw new RuntimeException("gh release upload: exit status $code\n$out");
            }
            $log('Uploaded ' . (count($batch) === 1 ? basename($batch[0]) : count($batch) . ' files'));
        }
        $log("Uploaded to the release $tag of $repo; publish it when every platform is there:\n  gh release edit $tag --repo $repo --draft=false$latest");
    }

    /**
     * Adds to the SHA256SUMS among $files the lines of the one published
     * already, which $earlier fetches into a directory it is given (null
     * for none): another architecture's build, uploaded to the same
     * release, keeps its sums. @param list<string> $files @param Closure(string): ?string $earlier
     */
    private static function mergeChecksums(array $files, Closure $earlier): void
    {
        foreach ($files as $f) {
            if (basename($f) !== Checksums::NAME) {
                continue;
            }
            $dir = sys_get_temp_dir() . '/myphp-sums-' . bin2hex(random_bytes(4));
            mkdir($dir, 0o700, true);
            try {
                $old = $earlier($dir);
                if ($old !== null && $old !== '') {
                    file_put_contents($f, Checksums::merge((string)file_get_contents($f), $old));
                }
            } finally {
                \MyPHP\Update::removeAll($dir);
            }
        }
    }

    /** The headers of an uploaded file: its type and, for the files whose names stay from version to version, that caches must check them again. @return array<string, string> */
    public static function headers(string $name): array
    {
        $h = ['Content-Type' => self::TYPES[pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream'];
        if (str_starts_with($name, 'update-') && str_ends_with($name, '.json') || $name === InstallPs1::NAME || $name === Checksums::NAME) {
            $h['Cache-Control'] = 'no-cache';
        }
        return $h;
    }
}
