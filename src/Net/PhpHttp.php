<?php
declare(strict_types=1);

namespace MyPHP\Net;

use Closure;
use Fiber;
use MyPHP\{App, HttpResponse, Logger, URLError};
use MyPHP\Native\NetProxy;
use MyPHP\Net\Async\Loop;
use MyPHP\Net\Http\{HttpClient, Proxy, Request};
use RuntimeException;
use Throwable;

/**
 * Http's requests over MyPHP's own network stack (src/Net/Async and src/Net/Http: an event loop over
 * stream_select and fibers, TLS by PHP's openssl, HTTP/1.1 and HTTP/2 in PHP), in place of the system's libcurl:
 * nothing of it waits on a facility of the system's (a CFFileDescriptor, a GLib source), so it behaves the same
 * on every platform. Chosen with Http::useTransport('php') or MYPHP_HTTP=php.
 *
 * The stack's loop does not own the thread in an app: the app's run loop turns it from a timer, every TURN seconds
 * while a request is in flight (a turn does not wait: Loop::turn) and every IDLE seconds while only kept connections
 * are open, which answer a server's pings and notice its hanging up. Without the app's run loop (a script, a test)
 * the request turns the stack's loop itself until it is done, as curl's pump does.
 *
 * @internal Http
 */
final class PhpHttp
{
    /** Seconds between turns of the stack's loop while a request is in flight, and while only kept connections are open. */
    private const TURN = 0.002, IDLE = 0.25;
    /** The most often a progress callback is called, in seconds: 30 times a second, as Http's. */
    private const PROGRESS_INTERVAL = 1 / 30;

    /** Requests in flight: sent, their bodies not yet read to the end. */
    private static int $inFlight = 0;
    /** The timer that turns the loop next: its number (a timer of another number is stale), and whether it is a TURN's. */
    private static int $timer = 0;
    private static ?bool $fast = null;

    /**
     * Http::send(), its arguments and its answer the same. $timeout is of each step (connecting, the head, each piece
     * of the body), not of the whole transfer as curl's; $progress runs in the fiber that asks, between pieces.
     * @param array<string, string> $headers
     * @param list<string> $protocols the URL schemes allowed, the redirects' too
     */
    public static function send(string $method, string $url, array $headers, ?string $body, float $timeout, array $protocols, string $agent, string $who, ?Closure $progress, ?string $saveTo, ?Closure $onData): HttpResponse
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        in_array($scheme, $protocols, true) || throw new RuntimeException("$who: unsupported protocol \"$scheme\"");
        $scheme !== 'https' || extension_loaded('openssl') || throw new RuntimeException("$who: https over the php transport needs PHP's openssl extension");
        $lower = array_change_key_case($headers, CASE_LOWER);
        if (!isset($lower['user-agent'])) { $headers['User-Agent'] = $agent; }
        if (!isset($lower['accept-encoding'])) { $headers['Accept-Encoding'] = 'gzip'; } // undone by the stack (InflatedBody)
        return self::drive(static function () use ($method, $url, $headers, $body, $timeout, $who, $progress, $saveTo, $onData): HttpResponse {
            try {
                return self::exchange(new Request($method, $url, $headers, $body), $timeout, $who, $progress, $saveTo, $onData);
            } catch (URLError $e) {
                throw $e;
            } catch (Async\AsyncError | Async\SocketError | Http\HttpError $e) { // the stack's own: told as Http's failures are
                throw new RuntimeException("$who: " . $e->getMessage(), 0, $e);
            }
        });
    }

    /** The request sent, redirects followed, and its body read to the end: kept, written to $saveTo, each piece given to $onData. */
    private static function exchange(Request $request, float $timeout, string $who, ?Closure $progress, ?string $saveTo, ?Closure $onData): HttpResponse
    {
        $proxy = match ($p = NetProxy::decide($request->url)) { // the system's proxy, as curl's requests take it
            null => null,
            false => Proxy::fromEnvironment(getenv()),
            default => Proxy::parse($p),
        };
        $sent = strlen($request->body ?? '');
        $told = 0.0;
        $tell = static function (int $upNow, int $downTotal, int $downNow, bool $last) use ($progress, $sent, &$told): void {
            if ($progress === null || (!$last && hrtime(true) / 1e9 - $told < self::PROGRESS_INTERVAL)) { return; }
            $told = hrtime(true) / 1e9;
            if ($progress($sent, $upNow, $downTotal, $downNow) === false) { throw URLError::cancelled(); }
        };
        if ($sent > 0 && $progress !== null) {
            $request = new Request($request->method, $request->url, $request->headers, $request->body, static function (int $upNow, int $upTotal) use ($tell): void {
                $tell($upNow, 0, 0, $upNow >= $upTotal);
            });
        }
        $response = new HttpClient($timeout > 0 ? $timeout : null, $proxy)->follow($request);
        $out = null;
        try {
            if ($saveTo !== null) {
                $out = fopen($saveTo, 'wb') ?: throw new RuntimeException("$who: cannot write $saveTo");
            }
            $total = (int)($response->header('content-length') ?? 0);
            $text = '';
            $got = 0;
            foreach ($response->body as $chunk) {
                $got += strlen($chunk);
                if ($out === null) {
                    $text .= $chunk;
                } elseif (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("$who: cannot write $saveTo");
                }
                if ($onData !== null) { $onData($chunk); }
                $tell($sent, $total, $got, false);
            }
            $tell($sent, max($total, $got), $got, true);
            return new HttpResponse($response->status, $text, $response->headers);
        } catch (Throwable $e) {
            $response->body->close(); // a body left part way: its stream, or its connection, let go
            throw $e;
        } finally {
            if ($out !== null) { fclose($out); }
        }
    }

    /**
     * Runs $request, which waits on the stack's loop, to its end. In a fiber of the running app it is that fiber that
     * waits, the app's timer turning the loop; outside one the app's run loop turns meanwhile (App::wait); without the
     * app, the stack's own loop is turned here until the request is done.
     * @param Closure(): HttpResponse $request
     */
    private static function drive(Closure $request): HttpResponse
    {
        self::$inFlight++;
        try {
            if (App::running() && Fiber::getCurrent() !== null) {
                self::kick();
                return $request();
            }
            $done = false;
            $result = $error = null;
            new Fiber(static function () use ($request, &$done, &$result, &$error): void {
                try {
                    $result = $request();
                } catch (Throwable $e) {
                    $error = $e;
                }
                $done = true;
            })->start();
            if (App::running()) {
                self::kick();
                App::wait(static function () use (&$done): bool { return $done; }); // by reference: an arrow function would keep the false
            } else {
                while (!$done) { Loop::get()->tick(); }
            }
            return $error === null ? $result : throw $error;
        } finally {
            self::$inFlight--;
        }
    }

    /** Has the app's run loop turn the stack's loop while it has work: soon while a request is in flight, now and then otherwise. */
    private static function kick(): void
    {
        $fast = self::$inFlight > 0;
        if (self::$fast === true || (self::$fast === false && !$fast)) {
            return; // a timer as soon as this one would be is set
        }
        self::$fast = $fast;
        $id = ++self::$timer;
        App::after($fast ? self::TURN : self::IDLE, static function () use ($id): void {
            if ($id !== self::$timer) { return; } // a sooner one took its place
            self::$fast = null;
            try {
                Loop::get()->turn();
            } catch (Throwable $e) { // a callback of the loop's that threw: not out of the run loop's timer
                Logger::error('the network loop: ' . $e->getMessage(), 'http');
            }
            if (!Loop::get()->isIdle()) { self::kick(); }
        });
    }
}
