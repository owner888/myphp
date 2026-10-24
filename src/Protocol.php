<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use InvalidArgumentException;

/**
 * Custom URL schemes served by PHP (mygo's Protocol), such as files made
 * at run time:
 *
 *     Protocol::handle('thumbs', Protocol::fileServer($cacheDir));
 *     new Window(new WindowOptions(url: 'thumbs://localhost/'));
 *
 * Pages served this way load as web pages do: they can use fetch, ES
 * modules and relative URLs, and call the app's bound methods. Schemes
 * must be handled before the windows that use them are created. A handler
 * is a Closure(ProtocolRequest): ProtocolResponse, which runs on the main
 * thread; a body that is a Generator streams.
 *
 * The myphp scheme serves the app's frontend (App::setFrontend) unless it
 * is handled here.
 */
final class Protocol
{
    /** The scheme of the app's frontend (mygo's "mygo"). */
    public const FRONTEND = 'myphp';
    /** Schemes WebKit handles itself, or that mean other things. */
    public const RESERVED = ['http', 'https', 'file', 'about', 'data', 'blob', 'javascript', 'ws', 'wss', 'ftp', 'mailto', 'webkit'];
    /** @var array<string, Closure(ProtocolRequest): ProtocolResponse> by scheme */
    private static array $handlers = [];

    /** Serves requests for $scheme with $handler; throws for an invalid or reserved scheme. */
    public static function handle(string $scheme, Closure $handler): void
    {
        $scheme = strtolower($scheme);
        if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1) {
            throw new InvalidArgumentException("myphp: invalid scheme \"$scheme\"");
        }
        if (in_array($scheme, self::RESERVED, true)) {
            throw new InvalidArgumentException("myphp: scheme \"$scheme\" is reserved");
        }
        self::$handlers[$scheme] = $handler;
    }

    /** Removes the handler of $scheme: pages requesting it get a 404. */
    public static function unhandle(string $scheme): void
    {
        unset(self::$handlers[strtolower($scheme)]);
    }

    /** Whether $scheme has a handler. */
    public static function isHandled(string $scheme): bool
    {
        return isset(self::$handlers[strtolower($scheme)]);
    }

    /** @internal the schemes windows must handle: the handled ones and the frontend's @return list<string> */
    public static function schemes(): array
    {
        return array_values(array_unique([...array_keys(self::$handlers), self::FRONTEND]));
    }

    /** @internal the handler of $scheme: its own, the frontend's for myphp, else null */
    public static function handlerFor(string $scheme): ?Closure
    {
        $scheme = strtolower($scheme);
        return self::$handlers[$scheme] ?? ($scheme === self::FRONTEND ? Native\Frontend::handler() : null);
    }

    /**
     * Serves the files of the directory $dir (mygo's FileServer): with
     * their content type, ranges for audio and video, index.html for a
     * directory, and index.html for unknown paths without an extension, so
     * client-side routers work.
     */
    public static function fileServer(string $dir): Closure
    {
        $root = realpath($dir);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException("myphp: $dir is not a directory");
        }
        return function (ProtocolRequest $r) use ($root): ProtocolResponse {
            $parts = [];
            foreach (explode('/', $r->path()) as $seg) { // the path cleaned, as path.Clean("/" + p)
                if ($seg === '..') {
                    array_pop($parts);
                } elseif ($seg !== '' && $seg !== '.') {
                    $parts[] = $seg;
                }
            }
            $name = implode('/', $parts);
            $path = $name === '' ? $root : "$root/$name";
            if (is_dir($path)) {
                $path .= '/index.html';
            }
            if (!is_file($path) && pathinfo($name, PATHINFO_EXTENSION) === '') {
                $path = "$root/index.html"; // a client-side route
            }
            $real = realpath($path);
            if ($real === false || !is_file($real) || !str_starts_with($real, $root . '/')) {
                return new ProtocolResponse(404, ['Content-Type' => 'text/plain; charset=utf-8'], "404 page not found\n");
            }
            return Native\Scheme::file($real, $r);
        };
    }
}
