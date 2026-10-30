<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use DateTimeImmutable;
use MyPHP\Native\Bundle;
use MyPHP\Native\Update\{Feed, Format, Http};
use RuntimeException;

/**
 * Installs new versions of the app (mygo's Updater), which `myphp build`
 * publishes when myphp.json configures updates: signed archives and a
 * manifest per platform.
 *
 *     App::go(function () {
 *         $update = Updater::check();
 *         if ($update !== null) {
 *             $update->install();
 *             App::relaunch();
 *         }
 *     });
 *
 * check() and install() wait for the network as mygo's block on their
 * goroutine: inside App::go() they suspend its fiber and the app runs
 * meanwhile; elsewhere they turn the run loop while they wait.
 */
final class Updater
{
    /** @internal set by tests in place of the bundle's MyPHPUpdateFeed and MyPHPUpdateKey (mygo's packageUpdateFeed and packageUpdateKey) */
    public static ?string $feed = null;
    /** @internal */
    public static ?string $key = null;
    /** @internal set by tests in place of the bundle the app runs from */
    public static ?string $target = null;
    private static bool $prereleases = false;
    private static ?UpdateSource $source = null;

    /**
     * Makes check() read the manifest from $s, and Update::install()
     * download the files it names through $s, instead of from the feed of
     * myphp.json; null restores the feed (mygo's SetSource). Updates are
     * still enabled only in builds with updates in myphp.json (enabled()),
     * which give the key updates are checked with: an update not signed
     * with it is refused, and the app is left as it was. An Update check()
     * returned keeps downloading from the source it came from.
     */
    public static function setSource(?UpdateSource $s): void
    {
        self::$source = $s;
    }

    /**
     * Makes check() offer prereleases too, as 1.3.0-beta.1, for the users
     * of an app who chose its beta (mygo's SetPrereleases): with updates on
     * GitHub, check() reads the release of the highest version that is not
     * a draft through the GitHub API (usually one request a check), so that
     * a release newer than the prereleases is offered too. check() never
     * offers a version older than the running one, so turning it off waits
     * for the release after the prerelease the app runs. Updates of
     * updates.url are the manifest the server has, whichever is set. It is
     * off at launch: the app keeps the user's choice and sets it again.
     */
    public static function setPrereleases(bool $on): void
    {
        self::$prereleases = $on;
    }

    /** Whether check() offers prereleases (setPrereleases). */
    public static function prereleases(): bool
    {
        return self::$prereleases;
    }

    /**
     * Whether the app can update itself: it was built with updates and can
     * write where it is installed. Development runs cannot.
     */
    public static function enabled(): bool
    {
        if (self::feed() === '' || self::key() === '' || self::$target === null && App::isDev()) {
            return false;
        }
        try {
            $target = self::installTarget();
        } catch (RuntimeException) {
            return false;
        }
        $probe = dirname($target) . '/.myphp-update-' . bin2hex(random_bytes(4));
        if (!@mkdir($probe, 0o700)) {
            return false;
        }
        rmdir($probe);
        return true;
    }

    /**
     * Asks the update feed, or the source of setSource(), for a version
     * newer than the running one and returns it, or null when the app is up to date. It throws
     * ErrUpdatesDisabled when the app cannot update itself.
     *
     * @param ?Closure(): bool $canceled stops it once it returns true (mygo's context)
     */
    public static function check(?Closure $canceled = null): ?Update
    {
        if (!self::enabled()) {
            throw new ErrUpdatesDisabled;
        }
        return self::$source !== null ? self::checkSource(self::$source, self::key(), App::version(), $canceled, self::$prereleases)
            : self::checkFeed(self::feed(), self::key(), App::version(), $canceled, self::$prereleases);
    }

    /** @internal what check() asks of a source of setSource() */
    public static function checkSource(UpdateSource $s, string $key, string $current, ?Closure $canceled = null, bool $prereleases = false): ?Update
    {
        try {
            $json = $s->manifest($prereleases, $canceled);
            if (strlen($json) > Format::MAX_MANIFEST_SIZE) {
                throw new RuntimeException('the manifest is over ' . Format::MAX_MANIFEST_SIZE . ' bytes');
            }
            $m = Format::manifest($json);
        } catch (RuntimeException $e) {
            throw new RuntimeException("myphp: checking for updates: {$e->getMessage()}", 0, $e);
        }
        return self::newer($m, $key, $current, $s);
    }

    /** @internal what check() asks */
    public static function checkFeed(string $feed, string $key, string $current, ?Closure $canceled = null, bool $prereleases = false): ?Update
    {
        // Not the app's name (mygo's bae6bfc): GitHub's release assets come from a store that answers 400 to a User-Agent
        // with bytes outside ASCII, as 云音乐's, for every file its CDN has not cached yet.
        Http::$userAgent = 'myphp-updater';
        try {
            $get = fn(string $u): string => Http::get($u, 64 << 20, $canceled);
            $url = $prereleases ? Feed::resolvePrerelease($feed, $get) : Feed::resolve($feed, $get);
            $m = Format::manifest(Http::get($url, Format::MAX_MANIFEST_SIZE, $canceled));
        } catch (RuntimeException $e) {
            throw new RuntimeException("myphp: checking for updates: {$e->getMessage()}", 0, $e);
        }
        return self::newer($m, $key, $current, null);
    }

    /** The update of manifest $m, from $source (null: the feed), or null when it is no newer than $current. @param array<string, mixed> $m */
    private static function newer(array $m, string $key, string $current, ?UpdateSource $source): ?Update
    {
        if (Format::compare($m['version'], $current) <= 0) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DATE_RFC3339, (string)($m['date'] ?? '')) ?: null;
        return new Update($m['version'], (string)($m['notes'] ?? ''), $date, $m, $key, $current, $source);
    }

    /** @internal what an update replaces: the app bundle */
    public static function installTarget(): string
    {
        $app = self::$target ?? Bundle::path();
        if ($app === null) {
            throw new RuntimeException('myphp: only app bundles can update themselves');
        }
        return $app;
    }

    private static function feed(): string
    {
        return self::$feed ?? Bundle::info('MyPHPUpdateFeed') ?? '';
    }

    private static function key(): string
    {
        return self::$key ?? Bundle::info('MyPHPUpdateKey') ?? '';
    }
}
