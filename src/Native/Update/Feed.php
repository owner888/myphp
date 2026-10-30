<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use RuntimeException;

/**
 * Feeds of updates (mygo's github.go). Updates published as GitHub
 * releases in a repository whose latest release may be another's, such as
 * a CLI's beside the app, have a feed that names the manifest in the
 * release of a version,
 *
 *     https://github.com/<owner>/<repo>/releases/download/<prefix>{version}/update-<target>.json
 *
 * and resolve() finds the newest release tagged <prefix> through the
 * GitHub API. Other feeds are the URL of the manifest.
 *
 * @internal
 */
final class Feed
{
    public const VERSION_PLACEHOLDER = '{version}';
    private const RELEASES_PER_PAGE = 100, MAX_RELEASE_PAGES = 10;
    /** The GitHub REST API, which tests replace. */
    public static string $githubApi = 'https://api.github.com';

    /** The feed of the manifest $name in the newest release of $repo ("owner/name") tagged $prefix and a version. */
    public static function tagged(string $repo, string $prefix, string $name): string
    {
        return "https://github.com/$repo/releases/download/" . rawurlencode($prefix) . self::VERSION_PLACEHOLDER . '/' . rawurlencode($name);
    }

    /**
     * The URL of the manifest $feed names: the feed itself, or for a feed
     * of tagged releases the manifest of the newest release with the tag's
     * prefix that is neither a draft nor a prerelease. Throws NotPublished
     * when there is none.
     *
     * @param \Closure(string): string $get the body of a successful GET
     */
    public static function resolve(string $feed, \Closure $get): string
    {
        if (!str_contains($feed, self::VERSION_PLACEHOLDER)) {
            return $feed;
        }
        [$repo, $prefix] = self::parse($feed);
        $tag = self::latestTagged($get, $repo, $prefix);
        return str_replace('/' . rawurlencode($prefix) . self::VERSION_PLACEHOLDER . '/', '/' . rawurlencode($tag) . '/', $feed);
    }

    /**
     * resolve() for apps that take prereleases too (mygo's
     * ResolvePrereleaseFeed): for a feed of GitHub releases, of the latest
     * release or tagged, the manifest of the release of the highest
     * version (Format::compare) that is not a draft, a prerelease or not,
     * among those tagged with the feed's prefix ("v" for the latest
     * release's feed, myphp build's default tagPrefix). Other feeds are the
     * URL of the manifest, as with resolve().
     * @param \Closure(string): string $get
     */
    public static function resolvePrerelease(string $feed, \Closure $get): string
    {
        $g = self::parseGitHub($feed);
        if ($g === null) {
            if (str_contains($feed, self::VERSION_PLACEHOLDER)) { throw new RuntimeException("$feed is not a feed of GitHub releases"); }
            return $feed;
        }
        [$repo, $prefix, $name] = $g;
        return "https://github.com/$repo/releases/download/" . rawurlencode(self::highestTagged($get, $repo, $prefix)) . '/' . rawurlencode($name);
    }

    /** The repository, the tag prefix and the manifest's name of a feed of GitHub releases, tagged or of the latest release; null for another feed. @return ?array{0: string, 1: string, 2: string} */
    private static function parseGitHub(string $feed): ?array
    {
        $parts = str_starts_with($feed, 'https://github.com/') ? explode('/', substr($feed, strlen('https://github.com/')), 6) : [];
        if (count($parts) !== 6 || $parts[2] !== 'releases') { return null; }
        if ($parts[3] === 'latest' && $parts[4] === 'download') {
            $prefix = 'v';
        } elseif ($parts[3] === 'download' && str_ends_with($parts[4], self::VERSION_PLACEHOLDER)) {
            $prefix = rawurldecode(substr($parts[4], 0, -strlen(self::VERSION_PLACEHOLDER)));
        } else {
            return null;
        }
        return ["$parts[0]/$parts[1]", $prefix, rawurldecode($parts[5])];
    }

    /**
     * The tag of the release of $repo tagged $prefix and the highest version
     * that is not a draft: the pages of releases, newest first, up to the
     * one holding the newest release that is no prerelease, the one
     * latestTagged() finds; those listed after it are taken as not newer,
     * so that a repository of many releases still costs a request a check
     * (mygo's highestTagged).
     */
    private static function highestTagged(\Closure $get, string $repo, string $prefix): string
    {
        $best = ''; $bestVersion = '';
        for ($page = 1; $page <= self::MAX_RELEASE_PAGES; $page++) {
            $u = self::$githubApi . "/repos/$repo/releases?per_page=" . self::RELEASES_PER_PAGE . "&page=$page";
            $releases = json_decode($get($u), true, 64);
            if (!is_array($releases)) {
                throw new RuntimeException("reading the releases of $u: " . json_last_error_msg());
            }
            $stable = false;
            foreach ($releases as $r) {
                $tag = is_array($r) ? (string)($r['tag_name'] ?? '') : '';
                if (!is_array($r) || !empty($r['draft']) || !str_starts_with($tag, $prefix)) { continue; }
                $v = substr($tag, strlen($prefix));
                if ($v === '' || !ctype_digit($v[0])) { continue; }
                $stable = $stable || empty($r['prerelease']);
                if ($best === '' || Format::compare($v, $bestVersion) > 0) { [$best, $bestVersion] = [$tag, $v]; }
            }
            if ($stable || count($releases) < self::RELEASES_PER_PAGE) { break; }
        }
        if ($best === '') { throw new NotPublished("no release is published in $repo tagged $prefix"); }
        return $best;
    }

    /** @return array{0: string, 1: string} the repository and the tag prefix */
    private static function parse(string $feed): array
    {
        $parts = str_starts_with($feed, 'https://github.com/') ? explode('/', substr($feed, strlen('https://github.com/')), 6) : [];
        if (count($parts) !== 6 || $parts[2] !== 'releases' || $parts[3] !== 'download' || !str_ends_with($parts[4], self::VERSION_PLACEHOLDER)) {
            throw new RuntimeException("$feed is not a feed of GitHub releases");
        }
        return ["$parts[0]/$parts[1]", rawurldecode(substr($parts[4], 0, -strlen(self::VERSION_PLACEHOLDER)))];
    }

    private static function latestTagged(\Closure $get, string $repo, string $prefix): string
    {
        for ($page = 1; $page <= self::MAX_RELEASE_PAGES; $page++) {
            $u = self::$githubApi . "/repos/$repo/releases?per_page=" . self::RELEASES_PER_PAGE . "&page=$page";
            $releases = json_decode($get($u), true, 64);
            if (!is_array($releases)) {
                throw new RuntimeException("reading the releases of $u: " . json_last_error_msg());
            }
            foreach ($releases as $r) {
                if (is_array($r) && empty($r['draft']) && empty($r['prerelease']) && str_starts_with((string)($r['tag_name'] ?? ''), $prefix)) {
                    return (string)$r['tag_name'];
                }
            }
            if (count($releases) < self::RELEASES_PER_PAGE) {
                break;
            }
        }
        throw new NotPublished("no release is published in $repo tagged $prefix");
    }
}
