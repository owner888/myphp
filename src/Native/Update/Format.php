<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use RuntimeException;

/**
 * What the updater and `myphp build` share (mygo's internal/update): the
 * manifest, keys and signatures, versions and release notes.
 *
 * A manifest describes the latest version of an app for one target (such
 * as darwin-arm64), published as update-<target>.json next to the archive:
 * {version, notes, date (RFC 3339), url, size, signature (Ed25519 of the
 * archive's SHA-256, base64), deltas: [{from, url, size, signature}],
 * previous: [{version, url, size, signature}]}.
 *
 * @internal
 */
final class Format
{
    /** Limits of what an app downloads. */
    public const MAX_MANIFEST_SIZE = 1 << 20, MAX_ARCHIVE_SIZE = 1 << 30;

    /** The file name of the manifest of $target. */
    public static function manifestName(string $target): string
    {
        return "update-$target.json";
    }

    /** This platform as manifests name it, Go's GOOS-GOARCH. */
    public static function target(): string
    {
        $os = match (PHP_OS_FAMILY) { 'Darwin' => 'darwin', 'Linux' => 'linux', default => strtolower(PHP_OS_FAMILY) };
        $arch = match (strtolower(php_uname('m'))) { 'x86_64', 'amd64' => 'amd64', 'arm64', 'aarch64' => 'arm64', default => strtolower(php_uname('m')) };
        return "$os-$arch";
    }

    /** A manifest from its JSON, checked. @return array<string, mixed> */
    public static function manifest(string $json): array
    {
        $m = json_decode($json, true, 16);
        if (!is_array($m)) {
            throw new RuntimeException('invalid manifest: ' . json_last_error_msg());
        }
        if (!is_string($m['version'] ?? null) || $m['version'] === '' || !is_string($m['url'] ?? null) || $m['url'] === '' || !is_string($m['signature'] ?? null) || $m['signature'] === '') {
            throw new RuntimeException('incomplete manifest');
        }
        return $m;
    }

    /** The delta of $m for version $from, or null. @param array<string, mixed> $m @return ?array{from: string, url: string, size: int, signature: string} */
    public static function delta(array $m, string $from): ?array
    {
        foreach ($m['deltas'] ?? [] as $d) {
            if (is_array($d) && ($d['from'] ?? null) === $from) {
                return ['from' => $from, 'url' => (string)($d['url'] ?? ''), 'size' => (int)($d['size'] ?? 0), 'signature' => (string)($d['signature'] ?? '')];
            }
        }
        return null;
    }

    /** A public key, 32 bytes in base64. */
    public static function publicKey(string $s): string
    {
        $b = base64_decode(trim($s), true);
        if ($b === false || strlen($b) !== Ed25519::PUBLIC_KEY_SIZE) {
            throw new RuntimeException('invalid update public key');
        }
        return $b;
    }

    /** A private key, 64 bytes in base64 (Go's ed25519.PrivateKey). */
    public static function privateKey(string $s): string
    {
        $b = base64_decode(trim($s), true);
        if ($b === false || strlen($b) !== Ed25519::PRIVATE_KEY_SIZE) {
            throw new RuntimeException('invalid update private key');
        }
        return $b;
    }

    /** The signature of an archive's SHA-256 $digest, in base64. */
    public static function sign(string $privateKey, string $digest): string
    {
        return base64_encode(Ed25519::sign($privateKey, $digest));
    }

    /** Checks the signature of an archive's SHA-256 $digest. */
    public static function verify(string $publicKey, string $digest, string $signature): void
    {
        $sig = base64_decode($signature, true);
        if ($sig === false || !Ed25519::verify($publicKey, $digest, $sig)) {
            throw new RuntimeException("the update is not signed with the app's key");
        }
    }

    /** Compares versions such as 1.2.3 and 1.3.0-beta.1 as semantic versioning does: numbers numerically, a pre-release before its release. */
    public static function compare(string $a, string $b): int
    {
        $strip = function (string $v): array {
            $v = str_starts_with($v, 'v') ? substr($v, 1) : $v;
            $v = explode('+', $v, 2)[0];
            return array_pad(explode('-', $v, 2), 2, '');
        };
        [$ar, $apre] = $strip($a);
        [$br, $bpre] = $strip($b);
        if (($c = self::compareFields($ar, $br)) !== 0) {
            return $c;
        }
        return match (true) {
            $apre === $bpre => 0,
            $apre === '' => 1,
            $bpre === '' => -1,
            default => self::compareFields($apre, $bpre),
        };
    }

    private static function compareFields(string $a, string $b): int
    {
        $af = explode('.', $a);
        $bf = explode('.', $b);
        for ($i = 0; $i < count($af) || $i < count($bf); $i++) {
            $x = $af[$i] ?? '';
            $y = $bf[$i] ?? '';
            $xn = preg_match('/^[+-]?\d+$/', $x) === 1;
            $yn = preg_match('/^[+-]?\d+$/', $y) === 1;
            if ($x === $y) {
                continue;
            }
            if ($x === '') {
                return -1;
            }
            if ($y === '') {
                return 1;
            }
            if ($xn && $yn) {
                if (($c = (int)$x <=> (int)$y) !== 0) {
                    return $c;
                }
                continue; // "01" and "1"
            }
            if ($xn) {
                return -1; // numbers sort before words
            }
            if ($yn) {
                return 1;
            }
            return strcmp($x, $y) <=> 0; // Go's strings.Compare
        }
        return 0;
    }

    /**
     * The section of a Markdown changelog under the "## <version>" heading,
     * or "## [<version>]" as in Keep a Changelog (a date or other text may
     * follow the version), or "" when there is none.
     */
    public static function releaseNotes(string $changelog, string $version): string
    {
        $notes = [];
        $in = false;
        $version = str_starts_with($version, 'v') ? substr($version, 1) : $version;
        foreach (explode("\n", str_replace("\r\n", "\n", $changelog)) as $line) {
            if (str_starts_with($line, '## ')) {
                if ($in) {
                    break;
                }
                $fields = preg_split('/\s+/', trim(substr($line, 3)), -1, PREG_SPLIT_NO_EMPTY);
                $first = $fields[0] ?? '';
                if (str_starts_with($first, '[')) {
                    $first = explode(']', substr($first, 1), 2)[0];
                }
                $in = $fields !== [] && (str_starts_with($first, 'v') ? substr($first, 1) : $first) === $version;
                continue;
            }
            if ($in) {
                $notes[] = $line;
            }
        }
        return trim(implode("\n", $notes));
    }
}
