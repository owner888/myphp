<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\{App, Protocol, ProtocolRequest, ProtocolResponse};

/**
 * The app's frontend, its web UI (mygo's frontend.go). Windows load it
 * with URLs without a scheme, "/" or "/settings" (WindowOptions::$url,
 * Window::loadURL), which resolve against the dev server at myphp.json's
 * devUrl while `myphp dev` runs the app (MYPHP_DEV_URL), and otherwise
 * against myphp://localhost/, which serves the frontend's files: the
 * directory App::setFrontend() set, frontendDist from disk under `myphp
 * dev` without a dev server (MYPHP_FRONTEND_DIST), or the bundle's
 * Resources/frontend, which `myphp build` copies frontendDist to.
 *
 * Which pages may call the app's bound methods is decided here too
 * (mygo's isTrusted): the app's own.
 *
 * @internal
 */
final class Frontend
{
    /** The directory App::setFrontend() set. */
    public static ?string $dir = null;

    /** The dev server `myphp dev` points the app at, or ''. */
    public static function devURL(): string
    {
        return App::isDev() ? (string)getenv('MYPHP_DEV_URL') : '';
    }

    /** $raw resolved against the frontend when it has no scheme, as "/" or "settings?tab=1". */
    public static function resolve(string $raw): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $raw) === 1) {
            return $raw;
        }
        $base = self::devURL() !== '' ? self::devURL() : Protocol::FRONTEND . '://localhost/';
        $p = parse_url($base);
        $origin = "{$p['scheme']}://{$p['host']}" . (isset($p['port']) ? ":{$p['port']}" : '');
        if (str_starts_with($raw, '/')) {
            return $origin . $raw;
        }
        $dir = substr($p['path'] ?? '/', 0, strrpos($p['path'] ?? '/', '/') + 1);
        return $origin . ($dir === '' ? '/' : $dir) . $raw;
    }

    /** The directory of the frontend's files, or null when the app has none. */
    public static function directory(): ?string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        if (App::isDev()) {
            $dist = (string)getenv('MYPHP_FRONTEND_DIST');
            return $dist !== '' ? $dist : null;
        }
        $res = Bundle::path() . '/Contents/Resources/frontend';
        return is_dir($res) ? $res : null;
    }

    /** What serves myphp:// when the app handles it not itself: the frontend's files, or a page saying how to get some. */
    public static function handler(): Closure
    {
        $dir = self::directory();
        return $dir !== null && is_dir($dir) ? Protocol::fileServer($dir) : fn(ProtocolRequest $r) => new ProtocolResponse(404, ['Content-Type' => 'text/html; charset=utf-8'], <<<'HTML'
            <!doctype html>
            <meta charset="utf-8">
            <title>No frontend</title>
            <style>body{font:15px/1.5 system-ui,sans-serif;margin:3em;color:#444}code{font-size:90%}</style>
            <h1>No frontend</h1>
            <p>Run the app with <code>myphp dev</code>, which loads <code>devUrl</code> from myphp.json,
            or build it with <code>myphp build</code>, which ships <code>frontendDist</code>; or call <code>App::setFrontend()</code>.</p>
            HTML);
    }

    /**
     * Whether a page at $url may call the app's bound methods, and its
     * requests of custom schemes get CORS headers: the app's own content
     * (about:, file:, the frontend, handled schemes), the dev server and
     * loopback servers under `myphp dev`, and $trusted origins ("*" for
     * every one).
     * @param list<string> $trusted
     */
    public static function isTrusted(string $url, array $trusted): bool
    {
        $u = parse_url($url);
        if ($u === false || !isset($u['scheme'])) {
            return false;
        }
        $scheme = strtolower($u['scheme']);
        if ($scheme === 'about' || $scheme === 'file' || $scheme === Protocol::FRONTEND || Protocol::isHandled($scheme)) {
            return true;
        }
        $host = strtolower($u['host'] ?? '');
        $origin = "$scheme://$host" . (isset($u['port']) ? ":{$u['port']}" : ''); // an IPv6 host keeps its brackets
        $dev = self::devURL();
        if ($dev !== '' && strcasecmp(rtrim(self::origin($dev), '/'), $origin) === 0) {
            return true;
        }
        foreach ($trusted as $o) {
            if ($o === '*' || strcasecmp(rtrim($o, '/'), $origin) === 0) {
                return true;
            }
        }
        return App::isDev() && ($scheme === 'http' || $scheme === 'https') && in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** The origin of $url: scheme://host[:port]. */
    private static function origin(string $url): string
    {
        $u = parse_url($url);
        return strtolower(($u['scheme'] ?? '') . '://' . ($u['host'] ?? '')) . (isset($u['port']) ? ":{$u['port']}" : '');
    }
}
