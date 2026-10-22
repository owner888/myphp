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
