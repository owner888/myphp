<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\{App, Logger, Protocol, ProtocolRequest, ProtocolResponse};

/**
 * Serving the requests of custom schemes (mygo's protocol.go): the
 * handler's response to the WebKit task, its body in chunks (a string cut
 * in 256 KiB pieces, an iterable's chunks a run loop turn apart), the CORS
 * headers that let the app's own pages read other schemes, files with
 * ranges, and the downloads of custom schemes, which WebKit can't make of
 * what a scheme handler serves.
 *
 * @internal
 */
final class Scheme
{
    public const CHUNK = 256 << 10;
    /** @var ?Closure(Closure): void runs a function on the next turn of the run loop; tests set it */
    public static ?Closure $later = null;
    /** @var array<string, string> the Content-Disposition of the latest responses by URL, which the navigation policy needs to tell attachments */
    public static array $dispositions = [];

    /**
     * Serves $req to $task with the handler of its scheme. $trusted tells
     * whether an origin ("scheme://host") is one of the window's trusted
     * pages, whose requests get CORS headers.
     * @param Closure(string): bool $trusted
     */
    public static function serve(ProtocolRequest $req, SchemeTask $task, Closure $trusted): void
    {
        $handler = Protocol::handlerFor((string)parse_url($req->url, PHP_URL_SCHEME));
        if ($handler === null) {
            $task->respond(404, []);
            $task->finish();
            return;
        }
        $cors = [];
        if (self::cors($req, $cors, $trusted)) {
            $task->respond(204, $cors); // a preflight, answered here
            $task->finish();
            return;
        }
        try {
            $resp = $handler($req);
            if (!$resp instanceof ProtocolResponse) {
                throw new \UnexpectedValueException('the handler returned ' . get_debug_type($resp) . ', not a ProtocolResponse');
            }
        } catch (\Throwable $e) {
            Logger::error("serving {$req->url}: {$e->getMessage()}", 'scheme');
            $task->respond(500, []);
            $task->finish();
            return;
        }
        foreach ($cors as $k => $v) { // the handler's own win
            if ($resp->header($k) === '') {
                $resp->headers[$k] = $v;
            }
        }
        $body = $resp->body;
        if (is_string($body)) {
            self::start($req, $task, $resp, $body);
            for ($i = 0, $n = strlen($body); $i < $n && !$task->stopped(); $i += self::CHUNK) {
                $task->write(substr($body, $i, self::CHUNK));
            }
            $task->finish();
            return;
        }
        $it = (function () use ($body) { yield from $body; })();
        $first = '';
        try {
            while ($it->valid() && ($first = (string)$it->current()) === '') { // the first bytes, to sniff the content type
                $it->next();
            }
        } catch (\Throwable $e) {
            Logger::error("serving {$req->url}: {$e->getMessage()}", 'scheme');
            $task->respond(500, []);
            $task->finish();
            return;
        }
        self::start($req, $task, $resp, $first);
        if (!$it->valid()) {
            $task->finish();
            return;
        }
        $task->write($first);
        self::pump($req->url, $task, $it);
    }

    /** Sends the response's status and headers, the content type sniffed from $first when it has none. */
    private static function start(ProtocolRequest $req, SchemeTask $task, ProtocolResponse $resp, string $first): void
    {
        if ($resp->header('Content-Type') === '' && $resp->status !== 204 && $resp->status !== 304) {
            $resp->setHeader('Content-Type', self::sniff($first));
        }
        if (count(self::$dispositions) > 64) {
            self::$dispositions = [];
        }
        self::$dispositions[$req->url] = $resp->header('Content-Disposition');
        $task->respond($resp->status, $resp->headers);
    }

    /** Writes the chunks after the one $it is at a turn of the run loop apart, until it ends or the page stops reading. */
    private static function pump(string $url, SchemeTask $task, \Generator $it): void
    {
        (self::$later ?? App::later(...))(function () use ($url, $task, $it): void {
            if ($task->stopped()) {
                return; // the generator goes, and with it what it holds
            }
            try {
                $it->next();
                if (!$it->valid()) {
                    $task->finish();
                    return;
                }
                $task->write((string)$it->current());
            } catch (\Throwable $e) {
                Logger::error("serving $url: {$e->getMessage()}", 'scheme');
                $task->fail($e->getMessage());
                return;
            }
            self::pump($url, $task, $it);
        });
    }

    /**
     * Lets the app's own pages read responses of custom schemes, which are
     * other origins than theirs: the frontend, another scheme, the dev
     * server. Fills $headers with the CORS headers for those origins, and
     * reports whether the request is a preflight it answers. Pages of other
     * sites get none, unless the window trusts them.
     * @param array<string, string> $headers
     * @param Closure(string): bool $trusted
     */
    public static function cors(ProtocolRequest $r, array &$headers, Closure $trusted): bool
    {
        $origin = $r->header('Origin');
        if ($origin === '' || $origin === 'null' || !$trusted($origin)) {
            return false;
        }
        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Access-Control-Expose-Headers'] = '*';
        $headers['Vary'] = 'Origin';
        $method = $r->header('Access-Control-Request-Method');
        if ($r->method !== 'OPTIONS' || $method === '') {
            return false;
        }
        $headers['Access-Control-Allow-Methods'] = $method;
        if (($h = $r->header('Access-Control-Request-Headers')) !== '') {
            $headers['Access-Control-Allow-Headers'] = $h;
        }
        $headers['Access-Control-Max-Age'] = '600';
        return true;
    }

    /** The content type of a body by its first bytes, as Go's http.DetectContentType finds the common ones. */
    public static function sniff(string $b): string
    {
        $head = ltrim(substr($b, 0, 512), "\t\n\x0c\r ");
        foreach (['<!doctype html', '<html', '<head', '<script', '<iframe', '<h1', '<div', '<font', '<table', '<a', '<style', '<title', '<b', '<body', '<br', '<p', '<!--'] as $tag) {
            $n = strlen($tag);
            if (strncasecmp($head, $tag, $n) === 0 && ($tag === '<!--' || in_array($head[$n] ?? '', [' ', '>'], true))) {
                return 'text/html; charset=utf-8';
            }
        }
        if (str_starts_with($head, '<?xml')) {
            return 'text/xml; charset=utf-8';
        }
        foreach (['%PDF-' => 'application/pdf', "\x89PNG\r\n\x1a\n" => 'image/png', "\xff\xd8\xff" => 'image/jpeg', 'GIF87a' => 'image/gif', 'GIF89a' => 'image/gif', "\x00\x00\x01\x00" => 'image/x-icon', 'wOFF' => 'font/woff', 'wOF2' => 'font/woff2', "\x00asm" => 'application/wasm', 'PK' . "\x03\x04" => 'application/zip', "\x1f\x8b\x08" => 'application/x-gzip', 'OggS' => 'application/ogg'] as $sig => $type) {
            if (str_starts_with($b, $sig)) {
                return $type;
            }
        }
        if (strlen($b) >= 12 && substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strlen($b) >= 12 && substr($b, 4, 4) === 'ftyp') {
            return 'video/mp4';
        }
        $sample = substr($b, 0, 512);
        return !str_contains($sample, "\0") && mb_check_encoding(self::wholeChars($sample), 'UTF-8') ? 'text/plain; charset=utf-8' : 'application/octet-stream';
    }

    /** $s without a character cut at its end. */
    private static function wholeChars(string $s): string
    {
        for ($i = strlen($s) - 1, $k = 0; $i >= 0 && $k < 3 && (ord($s[$i]) & 0xC0) === 0x80; $i--, $k++);
        return $i >= 0 && ord($s[$i]) >= 0xC0 ? substr($s, 0, $i) : $s;
    }

    /** Content types by extension, those of the web's files. */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json',
        'map' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'wasm' => 'application/wasm',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'mp4' => 'video/mp4',
        'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'xml' => 'text/xml; charset=utf-8',
        'pdf' => 'application/pdf', 'csv' => 'text/csv; charset=utf-8',
    ];

    /** The file at $path as a response to $r: its type, its last modification, the range $r asks for (bytes=a-b, a-, -n), read in chunks. */
    public static function file(string $path, ProtocolRequest $r): ProtocolResponse
    {
        $size = (int)filesize($path);
        $headers = ['Accept-Ranges' => 'bytes', 'Last-Modified' => gmdate('D, d M Y H:i:s', (int)filemtime($path)) . ' GMT'];
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($type !== null) {
            $headers['Content-Type'] = $type;
        }
        [$from, $to, $status] = [0, $size - 1, 200];
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($r->header('Range')), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                [$from, $to] = [max(0, $size - (int)$m[2]), $size - 1];
            } else {
                [$from, $to] = [(int)$m[1], $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1)];
            }
            if ($from > $to || $from >= $size) {
                return new ProtocolResponse(416, ['Content-Range' => "bytes */$size", 'Content-Type' => 'text/plain; charset=utf-8'], "invalid range\n");
            }
            $status = 206;
            $headers['Content-Range'] = "bytes $from-$to/$size";
        }
        $headers['Content-Length'] = (string)max(0, $to - $from + 1);
        if ($r->method === 'HEAD' || $size === 0) {
            return new ProtocolResponse($status, $headers, '');
        }
        return new ProtocolResponse($status, $headers, (function () use ($path, $from, $to) {
            $f = fopen($path, 'rb') ?: throw new \RuntimeException("myphp: cannot read $path");
            try {
                fseek($f, $from);
                for ($left = $to - $from + 1; $left > 0;) {
                    $chunk = fread($f, min(self::CHUNK, $left));
                    if ($chunk === false || $chunk === '') {
                        return;
                    }
                    $left -= strlen($chunk);
                    yield $chunk;
                }
            } finally {
                fclose($f);
            }
        })());
    }

    /**
     * Serves $url of a custom scheme into a download: $start(url,
     * suggested name) says where it goes (null cancels), $done(url, path,
     * ?error) that it ended.
     * @param Closure(string, string): ?string $start
     * @param Closure(string, string, ?string): void $done
     */
    public static function download(string $url, ProtocolRequest $req, Closure $start, Closure $done): void
    {
        $handler = Protocol::handlerFor((string)parse_url($url, PHP_URL_SCHEME));
        try {
            $resp = $handler === null ? null : $handler($req);
        } catch (\Throwable $e) {
            $done($url, '', "myphp: downloading $url: {$e->getMessage()}");
            return;
        }
        if (!$resp instanceof ProtocolResponse) {
            $done($url, '', "myphp: cannot download $url");
            return;
        }
        if ($resp->status >= 400) {
            $done($url, '', "myphp: downloading $url: {$resp->status}");
            return;
        }
        $name = '';
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $resp->header('Content-Disposition'), $m) === 1) {
            $name = rawurldecode($m[1]);
        }
        $name = $name !== '' ? $name : basename((string)parse_url($url, PHP_URL_PATH));
        $path = $start($url, $name);
        if ($path === null) {
            return; // canceled
        }
        $f = @fopen($path, 'wb');
        if ($f === false) {
            $done($url, '', "myphp: cannot write $path");
            return;
        }
        $error = null;
        try {
            foreach (is_string($resp->body) ? [$resp->body] : $resp->body as $chunk) {
                if (fwrite($f, (string)$chunk) === false) {
                    throw new \RuntimeException("myphp: cannot write $path");
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        fclose($f);
        if ($error !== null) {
            @unlink($path);
        }
        $done($url, $error === null ? $path : '', $error);
    }
}
