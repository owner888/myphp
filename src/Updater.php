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
     * Asks the update feed for a version newer than the running one and
     * returns it, or null when the app is up to date. It throws
     * ErrUpdatesDisabled when the app cannot update itself.
     *
     * @param ?Closure(): bool $canceled stops it once it returns true (mygo's context)
     */
    public static function check(?Closure $canceled = null): ?Update
    {
        if (!self::enabled()) {
            throw new ErrUpdatesDisabled;
        }
        return self::checkFeed(self::feed(), self::key(), App::version(), $canceled);
    }

    /** @internal what check() asks */
    public static function checkFeed(string $feed, string $key, string $current, ?Closure $canceled = null): ?Update
    {
        // Not the app's name (mygo's bae6bfc): GitHub's release assets come from a store that answers 400 to a User-Agent
        // with bytes outside ASCII, as 云音乐's, for every file its CDN has not cached yet.
        Http::$userAgent = 'myphp-updater';
        try {
            $url = Feed::resolve($feed, fn(string $u): string => Http::get($u, 64 << 20, $canceled));
            $m = Format::manifest(Http::get($url, Format::MAX_MANIFEST_SIZE, $canceled));
        } catch (RuntimeException $e) {
            throw new RuntimeException("myphp: checking for updates: {$e->getMessage()}", 0, $e);
        }
        if (Format::compare($m['version'], $current) <= 0) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DATE_RFC3339, (string)($m['date'] ?? '')) ?: null;
        return new Update($m['version'], (string)($m['notes'] ?? ''), $date, $m, $key, $current);
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
